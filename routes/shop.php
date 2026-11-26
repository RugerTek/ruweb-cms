<?php

/*
 * Public shop catalog routes. Required by routes/web.php whatever the shop mode (route:cache compiles them once),
 * before the page catch-all and twice: once in the base group and once inside the `{locale}` group. While the
 * catalog is off, EnsureShopCatalog hands these URLs to the page catch-all (`/productos` is a page slug again).
 *
 * Variables in scope: `$shopPath` (public prefix from cms.shop.path, `productos` by default) and `$localized`
 * (true inside `{locale}`: name routes `localized.shop.*` there and `shop.*` in the base group).
 */

use App\Http\Controllers\ShopController;
use App\Http\Middleware\EnsureShopCatalog;
use Illuminate\Support\Facades\Route;

$shopRouteName = $localized ? 'localized.shop.' : 'shop.';

Route::middleware(EnsureShopCatalog::class.':page')->group(function () use ($shopPath, $shopRouteName) {
    Route::get('/'.$shopPath, [ShopController::class, 'index'])->name($shopRouteName.'index');
    Route::get('/'.$shopPath.'/{slug}', [ShopController::class, 'show'])
        ->where('slug', '[a-zA-Z0-9\-]+')
        ->name($shopRouteName.'show');
});
