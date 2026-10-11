<?php

namespace App\Traits;

/**
 * MySQL JSON columns store object keys in their own order, so a decoded value rarely matches a submitted one
 * key-for-key. Equal content is not a change: no write, no `updated_at` touch, no new version.
 */
trait ComparesJsonByContent
{
    public function originalIsEquivalent($key)
    {
        if (parent::originalIsEquivalent($key)) {
            return true;
        }
        if (! $this->hasCast($key, ['array', 'json']) || ! array_key_exists($key, $this->original) || ! isset($this->attributes[$key])) {
            return false;
        }

        return self::canonicalJson($this->castAttribute($key, $this->attributes[$key]))
            === self::canonicalJson($this->castAttribute($key, $this->original[$key]));
    }

    private static function canonicalJson(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonicalJson(...), $value);
    }
}
