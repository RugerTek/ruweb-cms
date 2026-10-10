<?php

namespace App\Services\Editorial;

use App\Models\User;
use Closure;

/** Explicit operation identity takes precedence over legacy HTTP identity. */
final class EditorialActor
{
    private static ?User $actor = null;

    public static function current(): ?User
    {
        return self::$actor ?? request()->user() ?? auth()->user();
    }

    public static function run(User $actor, Closure $operation): mixed
    {
        $previous = self::$actor;
        self::$actor = $actor;
        try {
            return $operation();
        } finally {
            self::$actor = $previous;
        }
    }
}
