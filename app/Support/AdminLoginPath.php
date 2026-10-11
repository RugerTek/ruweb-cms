<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AdminLoginPath
{
    public const DEFAULT_SEGMENT = 'login';
    protected static ?string $resolved = null;

    /**
     * @return array<int, string>
     */
    public static function reservedRootSegments(): array
    {
        return [
            '',
            'login',
            'admin',
            'blog',
            'mcp',
            'up',
            'sitemap-xml',
        ];
    }

    public static function segment(): string
    {
        if (static::$resolved !== null) {
            return static::$resolved;
        }

        try {
            if (! Schema::hasTable('settings')) {
                return static::$resolved = self::DEFAULT_SEGMENT;
            }

            $value = DB::table('settings')
                ->where('key', 'admin_login_path')
                ->value('value');
        } catch (\Throwable) {
            return static::$resolved = self::DEFAULT_SEGMENT;
        }

        return static::$resolved = self::normalize($value);
    }

    public static function url(): string
    {
        return '/' . self::segment();
    }

    public static function normalize(mixed $value): string
    {
        $segment = trim((string) $value);
        $segment = trim($segment, '/');

        if ($segment === '') {
            return self::DEFAULT_SEGMENT;
        }

        $segment = Str::of($segment)
            ->lower()
            ->replaceMatches('/[^a-z0-9-]+/', '-')
            ->trim('-')
            ->value();

        if ($segment === '' || in_array($segment, self::reservedRootSegments(), true)) {
            return self::DEFAULT_SEGMENT;
        }

        return $segment;
    }

    public static function isCustomized(): bool
    {
        return self::segment() !== self::DEFAULT_SEGMENT;
    }

    public static function clearCache(): void
    {
        static::$resolved = null;
    }
}
