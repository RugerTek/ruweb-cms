<?php

namespace App\Services\Media;

use App\Models\Media;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class MediaImagePipeline
{
    public const OPTIMIZABLE_EXTENSIONS = ['jpg', 'jpeg', 'png'];
    public const RASTER_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    public const VARIANT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(
        protected ImageOptimizer $optimizer
    ) {
    }

    public function processUploadedMedia(Media $media): void
    {
        if (! $this->isRasterSupported($media)) {
            return;
        }

        if (! $this->supportsLocalPath($media->disk)) {
            $this->warn("Media optimization skipped for media #{$media->id}: disk [{$media->disk}] does not support local paths.");
            return;
        }

        if ($this->isOptimizable($media) && config('cms.image_optimization.enabled', true)) {
            $this->convertToWebp($media);
        } else {
            $this->refreshDimensions($media);
        }

        if (config('cms.responsive_images.enabled', true) && $this->isVariantEligible($media)) {
            $this->generateVariants($media, force: true);
        }
    }

    public function convertToWebp(Media $media, bool $dryRun = false): array
    {
        if (! $this->isOptimizable($media)) {
            return ['status' => 'skipped', 'reason' => 'unsupported_extension'];
        }

        if (! $this->supportsLocalPath($media->disk)) {
            return ['status' => 'skipped', 'reason' => 'non_local_disk'];
        }

        $disk = Storage::disk($media->disk);
        if (! $disk->exists($media->path)) {
            return ['status' => 'skipped', 'reason' => 'missing_file'];
        }

        if ($dryRun) {
            return ['status' => 'dry-run'];
        }

        $absoluteSource = $disk->path($media->path);
        $dirname = trim(pathinfo($media->path, PATHINFO_DIRNAME), '.');
        $basename = pathinfo($media->filename, PATHINFO_FILENAME);
        $newRelativePath = ($dirname ? $dirname . '/' : '') . $basename . '.webp';
        $absoluteTarget = $disk->path($newRelativePath);

        $result = $this->optimizer->optimizeToWebp(
            $absoluteSource,
            $absoluteTarget,
            (int) config('cms.image_optimization.quality', 80),
            (int) config('cms.image_optimization.max_width', 2048)
        );

        $originalPath = $media->path;
        $originalSize = $media->size;
        $originalExtension = $media->extension;
        $originalMimeType = $media->mime_type;

        $keepOriginal = (bool) config('cms.image_optimization.keep_original', false);
        $storedOriginalPath = null;

        if ($keepOriginal) {
            $origDir = ($dirname ? $dirname . '/' : '') . 'originals';
            $origFilename = Str::uuid() . '.' . $originalExtension;
            $storedOriginalPath = $origDir . '/' . $origFilename;
            $disk->move($originalPath, $storedOriginalPath);
        } else {
            $disk->delete($originalPath);
        }

        $media->forceFill([
            'path' => $newRelativePath,
            'filename' => basename($newRelativePath),
            'mime_type' => 'image/webp',
            'extension' => 'webp',
            'size' => $result['size'],
            'width' => $result['width'],
            'height' => $result['height'],
            'original_size' => $originalSize,
            'original_extension' => $originalExtension,
            'original_mime_type' => $originalMimeType,
            'original_path' => $storedOriginalPath,
            'optimized_at' => now(),
        ])->save();

        return ['status' => 'converted'];
    }

    public function refreshDimensions(Media $media, bool $dryRun = false): array
    {
        if (! $this->isRasterSupported($media)) {
            return ['status' => 'skipped', 'reason' => 'unsupported_extension'];
        }

        if (! $this->supportsLocalPath($media->disk)) {
            return ['status' => 'skipped', 'reason' => 'non_local_disk'];
        }

        $disk = Storage::disk($media->disk);
        if (! $disk->exists($media->path)) {
            return ['status' => 'skipped', 'reason' => 'missing_file'];
        }

        $dimensions = $this->optimizer->readDimensions($disk->path($media->path));
        if (! $dimensions) {
            return ['status' => 'skipped', 'reason' => 'invalid_image'];
        }

        if ($dryRun) {
            return ['status' => 'dry-run'];
        }

        $media->forceFill([
            'width' => $dimensions['width'],
            'height' => $dimensions['height'],
        ])->save();

        return ['status' => 'updated'];
    }

    public function generateVariants(Media $media, bool $force = false, bool $dryRun = false): array
    {
        if (! $this->isVariantEligible($media)) {
            return ['status' => 'skipped', 'reason' => 'unsupported_extension'];
        }

        if (! config('cms.responsive_images.enabled', true)) {
            return ['status' => 'skipped', 'reason' => 'disabled'];
        }

        if (! $this->supportsLocalPath($media->disk)) {
            return ['status' => 'skipped', 'reason' => 'non_local_disk'];
        }

        $disk = Storage::disk($media->disk);
        if (! $disk->exists($media->path)) {
            return ['status' => 'skipped', 'reason' => 'missing_file'];
        }

        $widths = (array) config('cms.responsive_images.widths', []);
        if (empty($widths)) {
            return ['status' => 'skipped', 'reason' => 'no_widths'];
        }

        if (! $force && $media->hasResponsiveVariants()) {
            return ['status' => 'skipped', 'reason' => 'already_exists'];
        }

        if ($dryRun) {
            return ['status' => 'dry-run'];
        }

        if ($force) {
            $this->deleteExistingVariants($media);
        }

        $dirname = trim(pathinfo($media->path, PATHINFO_DIRNAME), '.');
        $variantsRelativeDir = ($dirname ? $dirname . '/' : '') . 'variants';
        $variantsAbsoluteDir = $disk->path($variantsRelativeDir);
        $baseName = pathinfo($media->filename, PATHINFO_FILENAME);

        $variants = $this->optimizer->generateResponsiveVariants(
            $disk->path($media->path),
            $variantsAbsoluteDir,
            $baseName,
            $widths,
            (int) config('cms.image_optimization.quality', 80),
            $force
        );

        $serialized = collect($variants)->map(function (array $variant) use ($variantsRelativeDir, $media) {
            $relativePath = trim($variantsRelativeDir . '/' . $variant['filename'], '/');
            return [
                'width' => (int) $variant['width'],
                'height' => (int) $variant['height'],
                'size' => (int) $variant['size'],
                'path' => $relativePath,
                'media_id' => $media->id,
            ];
        })->values()->all();

        $media->forceFill(['variants' => $serialized])->save();

        return ['status' => 'generated', 'count' => count($serialized)];
    }

    public function deleteExistingVariants(Media $media): void
    {
        $variants = collect($media->variants ?: []);
        if ($variants->isEmpty()) {
            return;
        }

        $disk = Storage::disk($media->disk);
        foreach ($variants as $variant) {
            $path = $variant['path'] ?? null;
            if ($path) {
                $disk->delete($path);
            }
        }

        $media->forceFill(['variants' => null])->save();
    }

    public function deleteRelatedFiles(Media $media): void
    {
        $disk = Storage::disk($media->disk);
        $disk->delete($media->path);

        if (! empty($media->original_path)) {
            $disk->delete($media->original_path);
        }

        $this->deleteExistingVariants($media);
    }

    public function supportsLocalPath(string $disk): bool
    {
        try {
            Storage::disk($disk)->path('/');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function isOptimizable(Media $media): bool
    {
        return in_array(strtolower($media->extension), self::OPTIMIZABLE_EXTENSIONS, true);
    }

    public function isVariantEligible(Media $media): bool
    {
        return in_array(strtolower($media->extension), self::VARIANT_EXTENSIONS, true);
    }

    public function isRasterSupported(Media $media): bool
    {
        return in_array(strtolower($media->extension), self::RASTER_EXTENSIONS, true);
    }

    protected function warn(string $message): void
    {
        Log::warning($message);
    }
}
