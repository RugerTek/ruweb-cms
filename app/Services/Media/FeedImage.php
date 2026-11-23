<?php

namespace App\Services\Media;

use App\Models\Media;
use App\Support\SiteUrl;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Images for catalog feeds (PLAN.md §4.8). Meta and Google accept JPEG or PNG but not WebP, and uploads are stored
 * as WebP without the original (`cms.image_optimization.keep_original`). For a WebP medium this writes a JPEG
 * derivative once, `feed/{media_id}-{fingerprint}.jpg` on the public disk, and reuses it until the file changes.
 * Derivatives are built while the feed file or its preview is generated, never per request of the destination.
 */
class FeedImage
{
    /** Folder of the derivatives on the public disk. */
    public const DIRECTORY = 'feed';

    public const DISK = 'public';

    public const QUALITY = 85;

    /** Longer side of a derivative; smaller images are never enlarged. */
    public const MAX_SIDE = 2048;

    /** Meta asks for at least 500 × 500 (field `image_link`); below that the item gets the `image_too_small` notice. */
    public const MIN_SIDE = 500;

    private const PASSTHROUGH_MIME_TYPES = ['image/jpeg', 'image/png'];

    public function __construct(private readonly ImageOptimizer $optimizer) {}

    /**
     * Absolute URL of a JPEG or PNG version of the medium, or null when it cannot be produced (not an image, a
     * format other than JPEG/PNG/WebP, a WebP on a non-local disk, GD without WebP or a missing file).
     */
    public function jpegUrl(Media $media): ?string
    {
        if (in_array($this->mimeType($media), self::PASSTHROUGH_MIME_TYPES, true)) {
            return $this->absolute($media->url());
        }

        if ($this->mimeType($media) !== 'image/webp' || ! $this->convertible($media)) {
            return null;
        }

        $source = Storage::disk($media->disk)->path($media->path);
        if (! is_file($source)) {
            return null;
        }

        $disk = Storage::disk(self::DISK);
        $target = $this->derivativePath($media, $source);
        if (! $disk->exists($target)) {
            try {
                $this->optimizer->toJpeg($source, $disk->path($target), self::QUALITY, self::MAX_SIDE);
            } catch (Throwable $e) {
                report($e);

                return null;
            }
            $this->forget($media, except: $target);
        }

        return $this->absolute($disk->url($target));
    }

    /** Whether the shorter side is under MIN_SIDE (the item still goes to the feed, with a notice). */
    public function isTooSmall(Media $media): bool
    {
        $width = (int) $media->width;
        $height = (int) $media->height;
        if (($width <= 0 || $height <= 0) && $this->isLocal($media->disk)) {
            $dimensions = $this->optimizer->readDimensions(Storage::disk($media->disk)->path($media->path));
            $width = $dimensions['width'] ?? 0;
            $height = $dimensions['height'] ?? 0;
        }

        return $width > 0 && $height > 0 && min($width, $height) < self::MIN_SIDE;
    }

    /** Delete the medium's derivatives (all of them, or all but $except). Call it when the medium is deleted. */
    public function forget(Media $media, ?string $except = null): void
    {
        $disk = Storage::disk(self::DISK);
        $prefix = self::DIRECTORY.'/'.$media->getKey().'-';
        // Local disk: glob only this medium's files instead of listing the whole folder on every conversion.
        $files = $this->isLocal(self::DISK)
            ? array_map(fn (string $path) => self::DIRECTORY.'/'.basename($path), glob(str_replace('\\', '/', $disk->path($prefix)).'*.jpg') ?: [])
            : $disk->files(self::DIRECTORY);
        foreach ($files as $file) {
            if ($file !== $except && str_starts_with($file, $prefix) && str_ends_with($file, '.jpg')) {
                $disk->delete($file);
            }
        }
    }

    /** `feed/{id}-{fingerprint}.jpg`: the fingerprint changes with the path, the file size or `updated_at`. */
    private function derivativePath(Media $media, string $source): string
    {
        clearstatcache(true, $source);
        $fingerprint = substr(hash('sha256', implode('|', [
            $media->path,
            (string) (filesize($source) ?: 0),
            (string) $media->updated_at?->getTimestamp(),
        ])), 0, 16);

        return self::DIRECTORY.'/'.$media->getKey().'-'.$fingerprint.'.jpg';
    }

    private function convertible(Media $media): bool
    {
        return $this->isLocal($media->disk)
            && $this->isLocal(self::DISK)
            && function_exists('imagecreatefromwebp')
            && function_exists('imagejpeg');
    }

    private function isLocal(?string $disk): bool
    {
        return $disk !== null && config('filesystems.disks.'.$disk.'.driver') === 'local';
    }

    private function mimeType(Media $media): string
    {
        $mime = strtolower((string) $media->mime_type);
        if ($mime !== '') {
            return $mime === 'image/jpg' ? 'image/jpeg' : $mime;
        }

        return match (strtolower((string) $media->extension)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => '',
        };
    }

    private function absolute(string $url): string
    {
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        return SiteUrl::origin().'/'.ltrim($url, '/');
    }
}
