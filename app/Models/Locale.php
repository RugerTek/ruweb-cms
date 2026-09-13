<?php

namespace App\Models;

use App\Traits\RecordsContentRevisions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Locale extends Model
{
    use RecordsContentRevisions;

    protected $fillable = [
        'code',
        'name',
        'is_base',
        'is_active',
        'is_public',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_base' => 'boolean',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    public static function base(): ?self
    {
        return static::where('is_base', true)->first();
    }

    public static function baseCode(): string
    {
        return static::base()?->code ?: config('cms.locales.default', 'es');
    }

    public static function publicCodes(): Collection
    {
        return static::where('is_active', true)
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->pluck('code');
    }

    public static function secondaryPublicCodes(): Collection
    {
        $base = static::baseCode();

        return static::publicCodes()->reject(fn (string $code): bool => $code === $base)->values();
    }

    /**
     * Languages that may be installed (the selectable universe).
     *
     * @return array<string, string> code => display name
     */
    public static function catalog(): array
    {
        return config('cms.locales.catalog', ['es' => 'Español', 'en' => 'English']);
    }

    /**
     * @return array<int, string>
     */
    public static function catalogCodes(): array
    {
        return array_keys(static::catalog());
    }

    /**
     * Codes of the languages actually installed in this site.
     *
     * @return array<int, string>
     */
    public static function installedCodes(): array
    {
        return static::orderBy('sort_order')->pluck('code')->all();
    }
}
