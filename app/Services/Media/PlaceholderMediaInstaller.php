<?php

namespace App\Services\Media;

use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Seeds the starter placeholder images into the media library.
 *
 * The uploads directory is not versioned, so a fresh clone or install has an empty
 * media library. These stubs ship with the repository and are copied in through the
 * regular upload pipeline, which also produces the WebP version and its variants.
 */
class PlaceholderMediaInstaller
{
    /**
     * @var array<string, array{file: string, title: string, alt: string}>
     */
    public const PLACEHOLDERS = [
        'horizontal' => [
            'file' => 'placeholder-horizontal.png',
            'title' => 'Placeholder horizontal',
            'alt' => 'Imagen de ejemplo en formato horizontal',
        ],
        'square' => [
            'file' => 'placeholder-square.png',
            'title' => 'Placeholder cuadrado',
            'alt' => 'Imagen de ejemplo en formato cuadrado',
        ],
        'vertical' => [
            'file' => 'placeholder-vertical.png',
            'title' => 'Placeholder vertical',
            'alt' => 'Imagen de ejemplo en formato vertical',
        ],
    ];

    public function __construct(
        protected MediaImagePipeline $pipeline
    ) {
    }

    public static function stubDirectory(): string
    {
        return database_path('seeders/stubs/placeholders');
    }

    /**
     * Install every placeholder.
     *
     * @return array<string, string> placeholder key => created|restored|regenerated|skipped
     */
    public function install(bool $force = false): array
    {
        $results = [];

        foreach (self::PLACEHOLDERS as $key => $spec) {
            $results[$key] = $this->installOne($key, $spec, $force);
        }

        return $results;
    }

    /**
     * @param  array{file: string, title: string, alt: string}  $spec
     */
    protected function installOne(string $key, array $spec, bool $force): string
    {
        $stub = self::stubDirectory() . '/' . $spec['file'];

        if (! is_file($stub)) {
            throw new RuntimeException("Missing placeholder stub: {$stub}");
        }

        $existing = Media::where('placeholder_key', $key)->first();

        // A record can outlive its files: the database is restored or seeded while
        // storage/app/public is empty, as happens on a fresh clone.
        $filesPresent = $existing !== null
            && Storage::disk($existing->disk)->exists($existing->path);

        if ($existing && $filesPresent && ! $force) {
            return 'skipped';
        }

        $directory = 'media/' . now()->format('Y/m');
        $filename = Str::uuid() . '.png';
        $path = $directory . '/' . $filename;

        Storage::disk('public')->put($path, file_get_contents($stub));

        $attributes = [
            'filename' => $filename,
            'original_filename' => $spec['file'],
            'path' => $path,
            'disk' => 'public',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size' => filesize($stub),
            'title' => $spec['title'],
            'alt' => $spec['alt'],
            'placeholder_key' => $key,
        ];

        if ($existing) {
            $this->pipeline->deleteRelatedFiles($existing);
            $existing->forceFill($attributes + [
                'width' => null,
                'height' => null,
                'variants' => null,
                'original_size' => null,
                'original_extension' => null,
                'original_mime_type' => null,
                'original_path' => null,
                'optimized_at' => null,
            ])->save();
            $media = $existing;
        } else {
            $media = Media::create($attributes);
        }

        $this->pipeline->processUploadedMedia($media);

        if (! $existing) {
            return 'created';
        }

        return $filesPresent ? 'regenerated' : 'restored';
    }
}
