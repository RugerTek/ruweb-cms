<?php

namespace App\Http\Middleware;

use App\Support\EditorialTypes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 404 unless the route's `{type}` is registered right now. Route constraints on `{type}` are compiled once
 * (route:cache) and include the types an optional module may register (App\Support\ShopModule::editorialTypes(),
 * taxonomyTypes()); this checks the live registry, which follows the module's current mode.
 *
 * Parameter: `editorial` (App\Support\EditorialTypes) or `taxonomy` (`cms.taxonomy_types`).
 */
class RequireRegisteredType
{
    public function handle(Request $request, Closure $next, string $registry): Response
    {
        $type = (string) $request->route('type');
        $registered = match ($registry) {
            'editorial' => EditorialTypes::has($type),
            'taxonomy' => in_array($type, config('cms.taxonomy_types', []), true),
        };
        abort_unless($registered, 404);

        return $next($request);
    }
}
