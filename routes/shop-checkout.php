<?php

/*
 * Public checkout routes (PLAN.md E3.2, E3.5). Required by routes/web.php whatever the shop mode (route:cache
 * compiles them once), before routes/shop.php so `/productos/carrito` never reaches the product route, and twice:
 * once in the base group and once inside `{locale}`. Its existence is what ShopModule::checkoutAvailable() checks.
 *
 * - Cart and checkout: EnsureShopCatalog (off: the page catch-all answers) + EnsureShopCheckout (404 unless the
 *   mode is `checkout`).
 * - Order page by token and its payment retry: catalog only, so orders placed before leaving `checkout` keep
 *   their page. Never looked up by order number (R3).
 * - Payment provider webhook: base group only, whatever the mode (payments in flight must not be lost), without
 *   redirect rules, public locale, session, cookies or CSRF (bootstrap/app.php also exempts `webhooks/*`); `webhooks` is a reserved first segment
 *   for pages and `webhooks/*` is ignored by the 404 monitor (config/cms.php).
 *
 * Variables in scope: `$shopPath` (public prefix, `productos` by default) and `$localized` (true inside `{locale}`:
 * routes are named `localized.shop.*` there and `shop.*` in the base group).
 */

use App\Http\Controllers\Shop\BancardWebhookController;
use App\Http\Controllers\Shop\CartController;
use App\Http\Controllers\Shop\CheckoutController;
use App\Http\Controllers\Shop\OrderPageController;
use App\Http\Middleware\EnsureShopCatalog;
use App\Http\Middleware\EnsureShopCheckout;
use App\Models\Order;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

$checkoutRouteName = $localized ? 'localized.shop.' : 'shop.';

Route::middleware(EnsureShopCatalog::class.':page')->group(function () use ($shopPath, $checkoutRouteName) {
    Route::middleware(EnsureShopCheckout::class)->group(function () use ($shopPath, $checkoutRouteName) {
        Route::get('/'.$shopPath.'/carrito', [CartController::class, 'index'])->name($checkoutRouteName.'cart.index');
        Route::post('/'.$shopPath.'/carrito', [CartController::class, 'add'])
            ->middleware('throttle:cart')->name($checkoutRouteName.'cart.add');
        Route::patch('/'.$shopPath.'/carrito/{item}', [CartController::class, 'update'])
            ->whereNumber('item')->middleware('throttle:cart')->name($checkoutRouteName.'cart.update');
        Route::delete('/'.$shopPath.'/carrito/{item}', [CartController::class, 'remove'])
            ->whereNumber('item')->middleware('throttle:cart')->name($checkoutRouteName.'cart.remove');

        Route::get('/'.$shopPath.'/checkout', [CheckoutController::class, 'show'])->name($checkoutRouteName.'checkout.show');
        Route::post('/'.$shopPath.'/checkout', [CheckoutController::class, 'store'])
            ->middleware('throttle:forms')->name($checkoutRouteName.'checkout.store');
    });

    $token = '[A-Za-z0-9]{'.Order::ACCESS_TOKEN_LENGTH.'}';
    Route::get('/'.$shopPath.'/pedido/{token}', [OrderPageController::class, 'show'])
        ->where('token', $token)->name($checkoutRouteName.'order.show');
    Route::post('/'.$shopPath.'/pedido/{token}/pagar', [OrderPageController::class, 'pay'])
        ->where('token', $token)->middleware('throttle:forms')->name($checkoutRouteName.'order.pay');
});

if (! $localized) {
    Route::post('/webhooks/bancard', [BancardWebhookController::class, 'handle'])
        ->middleware('throttle:webhooks')
        ->withoutMiddleware(['redirects', 'public.locale', StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, EncryptCookies::class, AddQueuedCookiesToResponse::class])
        ->name('webhooks.bancard');
}
