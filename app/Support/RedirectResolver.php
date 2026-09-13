<?php

namespace App\Support;

use App\Models\NotFoundHit;
use App\Models\Redirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Single read path for the redirect rules. Both entry points use it so a rule behaves the same
 * whether the old URL still matches a route (ApplyRedirects middleware) or no longer does
 * (the 404 handler in bootstrap/app.php). See docs/operations/redirects.md.
 */
class RedirectResolver
{
    /** Builds the response for the request, or null when no active rule applies. */
    public static function resolve(Request $request): ?RedirectResponse
    {
        if (! static::handles($request)) {
            return null;
        }

        $path = Redirect::normalizePath('/'.$request->path());

        $redirect = Redirect::query()->active()->where('source_path', $path)->first();

        if (! $redirect) {
            return null;
        }

        $target = static::target($redirect, $request);

        // A rule that resolves to the URL being requested would loop forever.
        if ($target === $request->getRequestUri() || $target === $path) {
            return null;
        }

        $redirect->forceFill([
            'hit_count' => $redirect->hit_count + 1,
            'last_used_at' => now(),
        ])->save();

        return redirect()->to($target, $redirect->status_code);
    }

    /** Counts a 404 for the redirects screen, unless the path is excluded by config. */
    public static function recordNotFound(Request $request): void
    {
        if (! config('cms.redirects.monitor_not_found', true) || ! static::handles($request)) {
            return;
        }

        NotFoundHit::record(
            '/'.$request->path(),
            $request->method(),
            $request->headers->get('referer'),
            $request->userAgent(),
            $request->ip(),
        );
    }

    /** Only public GET/HEAD traffic is redirected or monitored. */
    public static function handles(Request $request): bool
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return false;
        }

        foreach ((array) config('cms.redirects.ignore_paths', []) as $pattern) {
            if ($request->is($pattern)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The destination to send the browser to. The incoming query string is carried over when the
     * rule does not define one of its own, so campaign parameters survive the redirect.
     */
    private static function target(Redirect $redirect, Request $request): string
    {
        $target = trim($redirect->target_url);
        $query = $request->getQueryString();

        if ($query === null || $query === '' || Str::contains($target, '?')) {
            return $target;
        }

        return $target.'?'.$query;
    }
}
