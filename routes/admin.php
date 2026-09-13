<?php

use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\Auth\ForgotPasswordController;
use App\Http\Controllers\Admin\Auth\LoginController;
use App\Http\Controllers\Admin\Auth\ResetPasswordController;
use App\Http\Controllers\Admin\CacheController;
use App\Http\Controllers\Admin\ClaudeMcpController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeveloperToolsController;
use App\Http\Controllers\Admin\SubmissionController;
use App\Http\Controllers\Admin\EmailController;
use App\Http\Controllers\Admin\FooterController;
use App\Http\Controllers\Admin\HeaderController;
use App\Http\Controllers\Admin\EditorialControlController;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\Admin\MediaHealthController;
use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\Admin\PageController;
use App\Http\Controllers\Admin\PostController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\TaxonomyController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

// Authenticated admin routes
Route::middleware(['auth', 'role:admin,editor', 'admin.locale'])->prefix('admin')->name('admin.')->group(function () {
    Route::post('logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('claude-mcp', [ClaudeMcpController::class, 'index'])->name('claude-mcp.index');
    Route::get('editorial-control', [EditorialControlController::class, 'index'])->name('editorial-control.index');
    Route::post('claude-mcp/api-key', [ClaudeMcpController::class, 'generateMcpApiKey'])->name('claude-mcp.api-key.generate');
    Route::delete('claude-mcp/api-key', [ClaudeMcpController::class, 'revokeMcpApiKey'])->name('claude-mcp.api-key.revoke');
    Route::get('profile', [ProfileController::class, 'index'])->name('profile.index');
    Route::put('profile/information', [ProfileController::class, 'updateInformation'])->name('profile.information.update');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');

    Route::resource('media', MediaController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->parameter('media', 'media');
    Route::get('media-health', [MediaHealthController::class, 'index'])->name('media.health');

    Route::resource('pages', PageController::class)
        ->except(['show']);
    Route::post('pages/{page}/translations/{locale}', [PageController::class, 'translate'])->name('pages.translate');

    Route::resource('posts', PostController::class)
        ->except(['show']);
    Route::post('posts/{post}/translations/{locale}', [PostController::class, 'translate'])->name('posts.translate');

    Route::prefix('taxonomies/{type}')->name('taxonomies.')->group(function () {
        Route::get('/', [TaxonomyController::class, 'index'])->name('index');
        Route::get('/create', [TaxonomyController::class, 'create'])->name('create');
        Route::post('/', [TaxonomyController::class, 'store'])->name('store');
        Route::get('/{taxonomy}/edit', [TaxonomyController::class, 'edit'])->name('edit');
        Route::put('/{taxonomy}', [TaxonomyController::class, 'update'])->name('update');
        Route::delete('/{taxonomy}', [TaxonomyController::class, 'destroy'])->name('destroy');
        Route::post('/{taxonomy}/translations/{locale}', [TaxonomyController::class, 'translate'])->name('translate');
    });

    Route::resource('menus', MenuController::class)->except(['show']);
    Route::post('menus/{menu}/translations/{locale}', [MenuController::class, 'translate'])->name('menus.translate');
    Route::put('menus/{menu}/items', [MenuController::class, 'syncItems'])->name('menus.items.sync');

    Route::post('pages/{page}/preview', [PageController::class, 'previewRender'])->name('pages.preview');

    // Form submissions (admins and editors). One tab per form in config('cms.forms').
    Route::get('submissions/{form}', [SubmissionController::class, 'index'])->name('submissions.index')->where('form', '[a-z0-9-]+');
    Route::get('submissions/{form}/{submission}', [SubmissionController::class, 'show'])->name('submissions.show')->where(['form' => '[a-z0-9-]+', 'submission' => '[0-9]+']);
    Route::delete('submissions/{form}/{submission}', [SubmissionController::class, 'destroy'])->name('submissions.destroy')->where(['form' => '[a-z0-9-]+', 'submission' => '[0-9]+']);
    Route::post('posts/{post}/preview', [PostController::class, 'previewRender'])->name('posts.preview');

    // Settings (admin only)
    Route::middleware('role:admin')->group(function () {
        Route::resource('users', UserController::class)
            ->except(['show']);

        Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
        Route::put('analytics', [AnalyticsController::class, 'update'])->name('analytics.update');
        Route::post('cache/refresh', [CacheController::class, 'refresh'])->name('cache.refresh');
        Route::get('developer-tools', [DeveloperToolsController::class, 'index'])->name('developer-tools.index');
        Route::post('developer-tools/download', [DeveloperToolsController::class, 'download'])->name('developer-tools.download');
        Route::post('developer-tools/upload', [DeveloperToolsController::class, 'upload'])->name('developer-tools.upload');
        Route::get('developer-tools/review/{token}', [DeveloperToolsController::class, 'review'])->name('developer-tools.review')->where('token', '[A-Za-z0-9]{40}');
        Route::post('developer-tools/review/{token}/restore', [DeveloperToolsController::class, 'restore'])->name('developer-tools.restore')->where('token', '[A-Za-z0-9]{40}');
        Route::delete('developer-tools/review/{token}', [DeveloperToolsController::class, 'discard'])->name('developer-tools.discard')->where('token', '[A-Za-z0-9]{40}');
        Route::post('developer-tools/prune', [DeveloperToolsController::class, 'prune'])->name('developer-tools.prune');
        Route::get('email', [EmailController::class, 'index'])->name('email.index');
        Route::put('email', [EmailController::class, 'update'])->name('email.update');
        Route::post('email/test', [EmailController::class, 'sendTestEmail'])->name('email.test');
        Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
        Route::put('settings', [SettingController::class, 'update'])->name('settings.update');
        // Header and footer: one screen each centralising what the client can edit (docs/layout-header-footer.md).
        Route::get('header', [HeaderController::class, 'index'])->name('header.index');
        Route::put('header', [HeaderController::class, 'update'])->name('header.update');
        Route::get('footer', [FooterController::class, 'index'])->name('footer.index');
        Route::put('footer', [FooterController::class, 'update'])->name('footer.update');
        Route::get('languages', [LanguageController::class, 'index'])->name('languages.index');
        Route::post('languages', [LanguageController::class, 'store'])->name('languages.store');
        Route::put('languages', [LanguageController::class, 'update'])->name('languages.update');
    });
});

// Guest routes
Route::middleware(['guest', 'admin.locale'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('password/reset', [ForgotPasswordController::class, 'showLinkRequestForm'])->name('password.request');
    Route::post('password/email', [ForgotPasswordController::class, 'sendResetLinkEmail'])->name('password.email');
    Route::get('password/reset/{token}', [ResetPasswordController::class, 'showResetForm'])->name('password.reset');
    Route::post('password/reset', [ResetPasswordController::class, 'reset'])->name('password.update');
});
