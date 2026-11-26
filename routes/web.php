<?php

use App\Http\Controllers\Admin\Auth\LoginController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\EditorialPreviewController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\Public\ContactFormController;
use App\Http\Controllers\SitemapController;
use App\Models\Locale;
use App\Support\AdminLoginPath;
use App\Support\ShopModule;
use Illuminate\Support\Facades\Route;

Route::get('/draft-preview/{token}', EditorialPreviewController::class)->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:60,1')->name('editorial.preview');

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

// Public connector feeds, before the page catch-all (routes/connectors.php).
require __DIR__.'/connectors.php';

// RügerTek support entry with a signed pass, before the page catch-all (routes/support.php).
require __DIR__.'/support.php';

Route::middleware(['guest', 'admin.locale'])->group(function () {
    $loginPath = AdminLoginPath::segment();

    Route::get('/'.$loginPath, [LoginController::class, 'showLoginForm'])->name('admin.login');
    Route::post('/'.$loginPath, [LoginController::class, 'login'])->name('admin.login.submit');
});

$catalogLocales = Locale::catalogCodes();
$localePattern = implode('|', array_map(
    fn (string $code): string => preg_quote($code, '/'),
    $catalogLocales
));
$shopPath = app(ShopModule::class)->path();
// Whole first segments only: `administracion` or `mcp-guide` are ordinary page slugs. The shop prefix is not
// reserved here: its routes come first and, with the shop off, hand `/productos` back to the page catch-all.
$reservedSlugs = '(?:admin|mcp|feeds|webhooks)(?:/|$)';
$reservedLocaleSlugs = implode('|', array_map(
    fn (string $code): string => preg_quote($code, '/').'$',
    $catalogLocales
));

// `redirects` runs first so a rule wins over a live page: see docs/operations/redirects.md.
Route::middleware(['redirects', 'public.locale'])->group(function () use ($localePattern, $reservedSlugs, $reservedLocaleSlugs, $shopPath) {
    Route::get('/', [PageController::class, 'home'])->name('home');
    Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
    Route::get('/blog/{slug}', [BlogController::class, 'show'])->name('blog.show');
    // Public forms: POST only, registered before the page catch-all. See docs/content/forms.md.
    Route::post('/forms/contact', [ContactFormController::class, 'store'])->middleware('throttle:forms')->name('forms.contact.store');
    // Optional shop catalog: always registered (route:cache), before the catch-all, in the base group and again
    // under {locale}; EnsureShopCatalog decides per request whether the catalog or a page answers. The checkout
    // routes come first so `/productos/carrito` never reaches the product route (routes/shop-checkout.php).
    $localized = false;
    require __DIR__.'/shop-checkout.php';
    require __DIR__.'/shop.php';

    Route::prefix('{locale}')
        ->where(['locale' => $localePattern])
        ->group(function () use ($reservedSlugs, $shopPath) {
            Route::get('/', [PageController::class, 'home'])->name('localized.home');
            Route::get('/blog', [BlogController::class, 'index'])->name('localized.blog.index');
            Route::get('/blog/{slug}', [BlogController::class, 'show'])->name('localized.blog.show');
            Route::post('/forms/contact', [ContactFormController::class, 'store'])->middleware('throttle:forms')->name('localized.forms.contact.store');
            $localized = true;
            require __DIR__.'/shop-checkout.php';
            require __DIR__.'/shop.php';
            Route::get('/{slug}', [PageController::class, 'show'])
                ->name('localized.page.show')
                ->where('slug', '^(?!('.$reservedSlugs.'))[a-zA-Z0-9\-\/]+$');
        });

    Route::get('/{slug}', [PageController::class, 'show'])
        ->name('page.show')
        ->where('slug', '^(?!('.$reservedSlugs.'|'.$reservedLocaleSlugs.'))[a-zA-Z0-9\-\/]+$');
});
