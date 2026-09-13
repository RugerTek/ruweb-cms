<?php

namespace App\Support;

/**
 * Helpers for keeping stored URLs independent of the domain the site runs on.
 *
 * Content moves between installations (local → staging → production) through
 * snapshots, so anything the system itself writes into the database must not
 * embed the current origin. Runtime code builds absolute URLs when it needs
 * them; storage keeps root-relative paths. See docs/operations/snapshots.md.
 */
final class SiteUrl
{
    /** Current origin without trailing slash, e.g. `https://example.com`. */
    public static function origin(): string
    {
        return self::normalizeOrigin((string) config('app.url'));
    }

    public static function normalizeOrigin(string $url): string
    {
        return rtrim(trim($url), '/');
    }

    /**
     * Turn a URL that points at this installation into a root-relative one.
     * External URLs, anchors and already relative values are returned as is.
     */
    public static function toRelative(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $trimmed = trim($url);
        $origin = self::origin();

        if ($origin === '' || $trimmed === '') {
            return $url;
        }

        if (! self::startsWithOrigin($trimmed, $origin)) {
            return $url;
        }

        $rest = substr($trimmed, strlen($origin));

        if ($rest === '' || $rest[0] === '?' || $rest[0] === '#') {
            return '/'.$rest;
        }

        return $rest;
    }

    /**
     * Case-insensitive origin match that refuses partial host matches:
     * `https://example.com.evil.net/x` does not start with `https://example.com`.
     */
    public static function startsWithOrigin(string $url, string $origin): bool
    {
        $origin = self::normalizeOrigin($origin);

        if ($origin === '' || strncasecmp($url, $origin, strlen($origin)) !== 0) {
            return false;
        }

        $next = substr($url, strlen($origin), 1);

        return $next === '' || $next === '/' || $next === '?' || $next === '#';
    }
}
