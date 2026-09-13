<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    protected $appends = [
        'url',
        'is_image',
        'formatted_size',
        'bytes_saved',
        'optimization_ratio',
        'has_responsive_variants',
        'is_optimized_raster',
    ];

    protected $fillable = [
        'filename',
        'original_filename',
        'path',
        'mime_type',
        'extension',
        'size',
        'width',
        'height',
        'variants',
        'original_size',
        'original_extension',
        'original_mime_type',
        'original_path',
        'optimized_at',
        'alt',
        'title',
        'placeholder_key',
        'disk',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'variants' => 'array',
            'optimized_at' => 'datetime',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function getUrlAttribute(): string
    {
        return $this->url();
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function getIsImageAttribute(): bool
    {
        return $this->isImage();
    }

    public function formattedSize(): string
    {
        $bytes = $this->size;
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }

    public function getFormattedSizeAttribute(): string
    {
        return $this->formattedSize();
    }

    public function srcset(): ?string
    {
        if (! $this->hasResponsiveVariants()) {
            return null;
        }

        return collect($this->variants)
            ->sortBy('width')
            ->filter(fn ($variant) => ! empty($variant['width']) && $this->variantUrl($variant) !== null)
            ->map(fn ($variant) => $this->variantUrl($variant) . ' ' . $variant['width'] . 'w')
            ->implode(', ');
    }

    /**
     * Variants store a disk-relative `path`; the URL is built at render time so
     * it always carries the current domain. Older rows that only kept `url`
     * still resolve.
     */
    public function variantUrl(array $variant): ?string
    {
        if (! empty($variant['path'])) {
            return Storage::disk($this->disk)->url($variant['path']);
        }

        return ! empty($variant['url']) ? (string) $variant['url'] : null;
    }

    public function hasResponsiveVariants(): bool
    {
        return ! empty($this->variants) && is_array($this->variants);
    }

    public function bytesSaved(): ?int
    {
        if ($this->original_size === null || $this->size === null || (int) $this->original_size <= 0) {
            return null;
        }

        return max((int) $this->original_size - (int) $this->size, 0);
    }

    public function optimizationRatio(): ?float
    {
        $saved = $this->bytesSaved();
        if ($saved === null || (int) $this->original_size <= 0) {
            return null;
        }

        return round(($saved / (int) $this->original_size) * 100, 2);
    }

    public function isOptimizedRaster(): bool
    {
        return $this->optimized_at !== null
            && strtolower($this->extension) === 'webp'
            && $this->original_size !== null;
    }

    public function getBytesSavedAttribute(): ?int
    {
        return $this->bytesSaved();
    }

    public function getOptimizationRatioAttribute(): ?float
    {
        return $this->optimizationRatio();
    }

    public function getHasResponsiveVariantsAttribute(): bool
    {
        return $this->hasResponsiveVariants();
    }

    public function getIsOptimizedRasterAttribute(): bool
    {
        return $this->isOptimizedRaster();
    }
}
