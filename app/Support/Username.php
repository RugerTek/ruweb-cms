<?php

namespace App\Support;

use Illuminate\Validation\Rule;

class Username
{
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $username = strtolower(trim($value));

        return $username === '' ? null : $username;
    }

    public static function rules(?int $userId = null): array
    {
        return ['required', 'string', 'min:3', 'max:32', 'regex:/\A[a-z0-9._-]+\z/', Rule::unique('users', 'username')->ignore($userId)];
    }
}
