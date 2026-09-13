<?php

use App\Http\Controllers\BlogController;
use App\Http\Controllers\Admin\Auth\LoginController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\Public\ContactFormController;
use App\Http\Controllers\SitemapController;
use App\Models\Locale;
use App\Support\AdminLoginPath;
use Illuminate\Support\Facades\Route;

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

Route::middleware(['guest', 'admin.locale'])->group(function () {
    $loginPath = AdminLoginPath::segment();

    Route::get('/' . $loginPath, [LoginController::class, 'showLoginForm'])->name('admin.login');
    Route::post('/' . $loginPath, [LoginController::class, 'login'])->name('admin.login.submit');
});

$catalogLocales = Locale::catalogCodes();
$localePattern = implode('|', array_map(
    fn (string $code): string => preg_quote($code, '/'),
    $catalogLocales
));
$reservedSlugs = 'admin|mcp|authorize|token|\.well-known';
$reservedLocaleSlugs = implode('|', array_map(
    fn (string $code): string => preg_quote($code, '/').'$',
    $catalogLocales
));

// `redirects` runs first so a rule wins over a live page: see docs/operations/redirects.md.
Route::middleware(['redirects', 'public.locale'])->group(function () use ($localePattern, $reservedSlugs, $reservedLocaleSlugs) {
    Route::get('/', [PageController::class, 'home'])->name('home');
    Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
    Route::get('/blog/{slug}', [BlogController::class, 'show'])->name('blog.show');
    // Public forms: POST only, registered before the page catch-all. See docs/content/forms.md.
    Route::post('/forms/contact', [ContactFormController::class, 'store'])->middleware('throttle:forms')->name('forms.contact.store');

    Route::prefix('{locale}')
        ->where(['locale' => $localePattern])
        ->group(function () use ($reservedSlugs) {
            Route::get('/', [PageController::class, 'home'])->name('localized.home');
            Route::get('/blog', [BlogController::class, 'index'])->name('localized.blog.index');
            Route::get('/blog/{slug}', [BlogController::class, 'show'])->name('localized.blog.show');
            Route::post('/forms/contact', [ContactFormController::class, 'store'])->middleware('throttle:forms')->name('localized.forms.contact.store');
            Route::get('/{slug}', [PageController::class, 'show'])
                ->name('localized.page.show')
                ->where('slug', '^(?!('.$reservedSlugs.'))[a-zA-Z0-9\-\/]+$');
        });

    Route::get('/{slug}', [PageController::class, 'show'])
        ->name('page.show')
        ->where('slug', '^(?!('.$reservedSlugs.'|'.$reservedLocaleSlugs.'))[a-zA-Z0-9\-\/]+$');
});
