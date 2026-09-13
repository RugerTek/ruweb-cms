<?php

namespace App\Models;

use App\Traits\RecordsContentRevisions;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    use RecordsContentRevisions;

    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'options',
    ];

    protected $casts = [
        'options' => 'array',
    ];

    protected static array $cache = [];

    public function translations(): HasMany
    {
        return $this->hasMany(SettingTranslation::class);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, static::$cache)) {
            return static::$cache[$key];
        }

        $setting = static::where('key', $key)->first();

        if (! $setting) {
            return $default;
        }

        $value = static::castValue($setting->value, $setting->type);
        static::$cache[$key] = $value;

        return $value;
    }

    public static function getLocalized(string $key, ?string $locale = null, mixed $default = null): mixed
    {
        $locale = $locale ?: app()->getLocale();
        $cacheKey = $key.':'.$locale;

        if (array_key_exists($cacheKey, static::$cache)) {
            return static::$cache[$cacheKey];
        }

        $setting = static::where('key', $key)->first();

        if (! $setting) {
            return $default;
        }

        $translation = $setting->translations()
            ->where('locale', $locale)
            ->first();

        $rawValue = $translation?->value;
        if ($rawValue === null || $rawValue === '') {
            $rawValue = $setting->value;
        }

        $value = static::castValue($rawValue, $setting->type);
        static::$cache[$cacheKey] = $value;

        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        $setting = static::where('key', $key)->first();

        if ($setting) {
            if ($setting->type === 'password' && is_string($value) && $value !== '') {
                $value = Crypt::encryptString($value);
            }
            $setting->update(['value' => $value]);
        }

        unset(static::$cache[$key]);
        foreach (array_keys(static::$cache) as $cacheKey) {
            if (str_starts_with($cacheKey, $key.':')) {
                unset(static::$cache[$cacheKey]);
            }
        }
    }

    public static function setLocalized(string $key, string $locale, mixed $value): void
    {
        $setting = static::where('key', $key)->first();

        if (! $setting) {
            return;
        }

        $setting->translations()->updateOrCreate(
            ['locale' => $locale],
            ['value' => $value]
        );

        unset(static::$cache[$key.':'.$locale]);
    }

    public static function getGroup(string $group): Collection
    {
        return static::where('group', $group)->get();
    }

    public static function allGrouped(): Collection
    {
        return static::all()->groupBy('group');
    }

    public static function clearCache(): void
    {
        static::$cache = [];
    }

    protected static function castValue(?string $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $value,
            'media' => $value ? Media::find((int) $value) : null,
            'password' => static::decryptPassword($value),
            default => $value,
        };
    }

    protected static function decryptPassword(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }

    public static function hasValue(string $key): bool
    {
        return (bool) static::where('key', $key)->value('value');
    }
}
