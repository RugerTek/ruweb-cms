<?php

namespace App\Http\Middleware;

use App\Support\ShopModule;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public cart and checkout routes (routes/shop-checkout.php) answer only while the effective shop mode is
 * `checkout`; otherwise a 404, as if they did not exist (PLAN.md E3.3). They run after EnsureShopCatalog, so with
 * the shop off the page catch-all already took the request. The order page, the payment webhook and the orders
 * panel do not use this middleware: orders placed before the switch keep working while the catalog is on.
 */
class EnsureShopCheckout
{
    public function __construct(private readonly ShopModule $shop) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->shop->checkout(), 404);

        return $next($request);
    }
}
