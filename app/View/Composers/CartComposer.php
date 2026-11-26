<?php

namespace App\View\Composers;

use App\Models\Cart;
use App\Services\Shop\CartService;
use App\Support\ShopModule;
use Illuminate\View\View;

/**
 * Cart icon of the public header (partials.header, wired by ShopServiceProvider): `cartEnabled` only while the shop
 * mode is `checkout`, with `cartCount` (units) and `cartUrl`. It reads the database only in that mode and only when
 * the request carries a `ruweb_cart` cookie of the right length; otherwise the count is 0 without a query.
 */
class CartComposer
{
    public function __construct(
        private readonly ShopModule $shop,
        private readonly CartService $carts,
    ) {}

    public function compose(View $view): void
    {
        if (! $this->shop->checkout()) {
            $view->with(['cartEnabled' => false, 'cartCount' => 0, 'cartUrl' => null]);

            return;
        }

        $request = request();
        $token = $request->cookie(CartService::COOKIE);
        $count = is_string($token) && strlen($token) === Cart::TOKEN_LENGTH
            ? $this->carts->count($this->carts->current($request))
            : 0;

        $view->with(['cartEnabled' => true, 'cartCount' => $count, 'cartUrl' => CartService::route('cart.index')]);
    }
}
