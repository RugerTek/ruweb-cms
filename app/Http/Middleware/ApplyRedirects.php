<?php

namespace App\Http\Middleware;

use App\Support\RedirectResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies a redirect rule before the public routes render, so an old URL that still resolves to a
 * live page (a renamed slug kept in place, a page moved under another section) is redirected too.
 * Paths that match no route are handled by the 404 renderer in bootstrap/app.php.
 */
class ApplyRedirects
{
    public function handle(Request $request, Closure $next): Response
    {
        return RedirectResolver::resolve($request) ?? $next($request);
    }
}
