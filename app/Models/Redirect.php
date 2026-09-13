<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A single 301/302 rule: one source path of this site, one destination.
 * Resolution lives in App\Support\RedirectResolver; this model only owns the row and the path shape.
 */
class Redirect extends Model
{
    protected $fillable = [
        'source_path',
        'target_url',
        'status_code',
        'is_active',
        'hit_count',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'is_active' => 'boolean',
            'hit_count' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Canonical form of a source path: leading slash, no trailing slash, no query or fragment.
     * Absolute URLs are accepted so a pasted legacy URL still matches by path.
     */
    public static function normalizePath(string $path): string
    {
        $path = trim($path);
        $path = parse_url($path, PHP_URL_PATH) ?: $path;
        $path = '/'.ltrim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /** True when the destination points outside this site. */
    public function isExternal(): bool
    {
        return (bool) parse_url(trim($this->target_url), PHP_URL_HOST);
    }
}
