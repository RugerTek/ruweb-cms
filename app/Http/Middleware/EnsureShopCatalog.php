<?php

namespace App\Http\Middleware;

use App\Support\ShopModule;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shop routes are always registered (route:cache compiles them once) and answer only while the catalog is on.
 * Off, panel and MCP routes are a 404, as if they did not exist. Public catalog routes (`shop.*`,
 * `localized.shop.*`, parameter `page`) hand the request to the page catch-all instead, so `/productos` is an
 * ordinary page slug again: same route, middleware already run, same controller (docs/shop/catalog.md).
 */
class EnsureShopCatalog
{
    public function __construct(private readonly ShopModule $shop, private readonly Router $router) {}

    public function handle(Request $request, Closure $next, ?string $fallback = null): Response
    {
        if ($this->shop->catalog()) {
            return $next($request);
        }

        abort_unless($fallback === 'page', 404);

        $name = str_starts_with((string) $request->route()?->getName(), 'localized.') ? 'localized.page.show' : 'page.show';
        $page = $this->router->getRoutes()->getByName($name);
        abort_unless($page !== null && $page->matches($request), 404);

        // Both routes live in the same middleware groups (`redirects`, `public.locale`), which already ran.
        $page->bind($request)->setContainer(app());
        $request->setRouteResolver(fn () => $page);

        return $this->router->prepareResponse($request, $page->run());
    }
}
