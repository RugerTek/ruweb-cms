<?php

use App\Http\Controllers\Admin\AccountSessionsController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\Auth\ForgotPasswordController;
use App\Http\Controllers\Admin\Auth\LoginController;
use App\Http\Controllers\Admin\Auth\ResetPasswordController;
use App\Http\Controllers\Admin\CacheController;
use App\Http\Controllers\Admin\ClaudeMcpController;
use App\Http\Controllers\Admin\ContentStatusController;
use App\Http\Controllers\Admin\CustomCodeController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeveloperToolsController;
use App\Http\Controllers\Admin\EditorialControlController;
use App\Http\Controllers\Admin\EditorialController;
use App\Http\Controllers\Admin\EditorLockController;
use App\Http\Controllers\Admin\EmailController;
use App\Http\Controllers\Admin\FooterController;
use App\Http\Controllers\Admin\HeaderController;
use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\Admin\MediaHealthController;
use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\Admin\PageController;
use App\Http\Controllers\Admin\PostController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\RedirectController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\SubmissionController;
use App\Http\Controllers\Admin\TaxonomyController;
use App\Http\Controllers\Admin\TwoFactorController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Middleware\EnsureAccountSession;
use App\Http\Middleware\ProtectEditorialMutation;
use App\Http\Middleware\ProtectLiveEditor;
use App\Http\Middleware\RequireLiveEditor;
use Illuminate\Support\Facades\Route;

// Authenticated admin routes
Route::middleware(['auth', 'role:admin,editor', EnsureAccountSession::class, 'admin.locale'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('content/{type}', [ContentStatusController::class, 'index'])->name('content.index');
    Route::get('content/{type}/{id}', [ContentStatusController::class, 'show'])->whereNumber('id')->name('content.show');
    Route::get('content/{type}/{id}/preview', [ContentStatusController::class, 'preview'])->whereNumber('id')->name('content.preview');
    Route::get('editorial-calendar', [EditorialController::class, 'calendar'])->name('editorial.calendar');
    Route::prefix('editorial/{type}/{id}')->whereIn('type', ['pages', 'posts'])->whereNumber('id')->name('editorial.')->group(function () {
        $controller = EditorialController::class;
        Route::get('/', [$controller, 'index'])->name('index');
        Route::middleware(RequireLiveEditor::class)->group(function () use ($controller) {
            Route::match(['get', 'post', 'delete'], 'draft', [$controller, 'draft'])->name('draft');
            Route::post('restore/{version}', [$controller, 'restore'])->name('restore')->middleware(ProtectEditorialMutation::class);
            Route::post('review', [$controller, 'review'])->name('review');
            Route::post('review/{review}/comments', [$controller, 'comment'])->name('comment');
            Route::post('review/{review}', [$controller, 'decide'])->name('decide')->middleware(ProtectEditorialMutation::class);
            Route::post('approve-field', [$controller, 'approveField'])->name('approve-field')->middleware(ProtectEditorialMutation::class);
            Route::post('share', [$controller, 'share'])->name('share');
            Route::delete('share/{link}', [$controller, 'revoke'])->name('revoke');
        });
    });
    Route::post('logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('claude-mcp', [ClaudeMcpController::class, 'index'])->name('claude-mcp.index');
    Route::get('editorial-control', [EditorialControlController::class, 'index'])->name('editorial-control.index');
    Route::post('claude-mcp/api-key', [ClaudeMcpController::class, 'generateMcpApiKey'])->name('claude-mcp.api-key.generate');
    Route::delete('claude-mcp/api-keys/{key}', [ClaudeMcpController::class, 'revokeKey'])->name('claude-mcp.api-keys.revoke');
    Route::delete('claude-mcp/api-key', [ClaudeMcpController::class, 'revokeMcpApiKey'])->name('claude-mcp.api-key.revoke');
    Route::post('profile/two-factor/enable', [TwoFactorController::class, 'enable'])->middleware('throttle:two-factor')->name('two-factor.enable');
    Route::post('profile/two-factor/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:two-factor')->name('two-factor.confirm');
    Route::delete('profile/two-factor', [TwoFactorController::class, 'disable'])->middleware('throttle:two-factor')->name('two-factor.disable');
    Route::post('profile/two-factor/recovery', [TwoFactorController::class, 'recovery'])->middleware('throttle:two-factor')->name('two-factor.recovery');
    Route::delete('profile/sessions/others', [AccountSessionsController::class, 'destroyOthers'])->name('profile.sessions.others');
    Route::delete('profile/sessions/{session}', [AccountSessionsController::class, 'destroy'])->name('profile.sessions.destroy');
    Route::get('profile', [ProfileController::class, 'index'])->name('profile.index');
    Route::put('profile/information', [ProfileController::class, 'updateInformation'])->name('profile.information.update');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');

    Route::resource('media', MediaController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->parameter('media', 'media');
    Route::get('media-health', [MediaHealthController::class, 'index'])->name('media.health');

    Route::resource('pages', PageController::class)
        ->except(['show'])
        ->middlewareFor(['create', 'store'], RequireLiveEditor::class)
        ->middlewareFor(['edit', 'update'], [RequireLiveEditor::class, ProtectLiveEditor::class])
        ->middlewareFor('destroy', [RequireLiveEditor::class, ProtectLiveEditor::class]);
    Route::post('pages/{page}/translations/{locale}', [PageController::class, 'translate'])->name('pages.translate')->middleware(RequireLiveEditor::class);

    Route::resource('posts', PostController::class)
        ->except(['show'])
        ->middlewareFor(['create', 'store'], RequireLiveEditor::class)
        ->middlewareFor(['edit', 'update'], [RequireLiveEditor::class, ProtectLiveEditor::class])
        ->middlewareFor('destroy', [RequireLiveEditor::class, ProtectLiveEditor::class]);
    Route::post('posts/{post}/translations/{locale}', [PostController::class, 'translate'])->name('posts.translate')->middleware(RequireLiveEditor::class);

    Route::post('editor-locks/{type}/{id}/heartbeat', [EditorLockController::class, 'heartbeat'])
        ->whereIn('type', ['pages', 'posts'])->whereNumber('id')->name('editor-locks.heartbeat')->middleware(RequireLiveEditor::class);
    Route::post('editor-locks/{type}/{id}/release', [EditorLockController::class, 'release'])
        ->whereIn('type', ['pages', 'posts'])->whereNumber('id')->name('editor-locks.release')->middleware(RequireLiveEditor::class);

    Route::prefix('taxonomies/{type}')->name('taxonomies.')->middleware(RequireLiveEditor::class)->group(function () {
        Route::get('/', [TaxonomyController::class, 'index'])->name('index');
        Route::get('/create', [TaxonomyController::class, 'create'])->name('create');
        Route::post('/', [TaxonomyController::class, 'store'])->name('store');
        Route::get('/{taxonomy}/edit', [TaxonomyController::class, 'edit'])->name('edit');
        Route::put('/{taxonomy}', [TaxonomyController::class, 'update'])->name('update');
        Route::delete('/{taxonomy}', [TaxonomyController::class, 'destroy'])->name('destroy');
        Route::post('/{taxonomy}/translations/{locale}', [TaxonomyController::class, 'translate'])->name('translate');
    });

    Route::resource('menus', MenuController::class)->except(['show'])->middleware(RequireLiveEditor::class);
    Route::post('menus/{menu}/translations/{locale}', [MenuController::class, 'translate'])->name('menus.translate')->middleware(RequireLiveEditor::class);
    Route::put('menus/{menu}/items', [MenuController::class, 'syncItems'])->name('menus.items.sync')->middleware(RequireLiveEditor::class);

    Route::post('pages/{page}/preview', [PageController::class, 'previewRender'])->name('pages.preview')->middleware(RequireLiveEditor::class);

    // Form submissions (admins and editors). One tab per form in config('cms.forms').
    Route::get('submissions/{form}', [SubmissionController::class, 'index'])->name('submissions.index')->where('form', '[a-z0-9-]+');
    Route::get('submissions/{form}/{submission}', [SubmissionController::class, 'show'])->name('submissions.show')->where(['form' => '[a-z0-9-]+', 'submission' => '[0-9]+']);
    Route::patch('submissions/{form}/{submission}', [SubmissionController::class, 'update'])->name('submissions.update')->where(['form' => '[a-z0-9-]+', 'submission' => '[0-9]+']);
    Route::post('submissions/{form}/{submission}/notes', [SubmissionController::class, 'note'])->name('submissions.notes')->where(['form' => '[a-z0-9-]+', 'submission' => '[0-9]+']);
    Route::post('submissions/{form}/{submission}/resend', [SubmissionController::class, 'resend'])->name('submissions.resend')->where(['form' => '[a-z0-9-]+', 'submission' => '[0-9]+']);
    Route::delete('submissions/{form}/{submission}', [SubmissionController::class, 'destroy'])->name('submissions.destroy')->where(['form' => '[a-z0-9-]+', 'submission' => '[0-9]+']);
    Route::post('posts/{post}/preview', [PostController::class, 'previewRender'])->name('posts.preview')->middleware(RequireLiveEditor::class);

    // Settings (admin only)
    Route::middleware('role:admin')->group(function () {
        Route::post('custom-code/{id}/test-preview', \App\Http\Controllers\Admin\CustomCodePreviewController::class)->whereNumber('id')->name('custom-code.test-preview');
        Route::resource('custom-code', CustomCodeController::class)->except(['show', 'destroy'])->parameters(['custom-code' => 'id']);
        Route::post('custom-code/{id}/activate', [CustomCodeController::class, 'activate'])->whereNumber('id')->name('custom-code.activate');
        Route::post('custom-code/{id}/deactivate', [CustomCodeController::class, 'deactivate'])->whereNumber('id')->name('custom-code.deactivate');
        Route::post('custom-code/{id}/evaluate', [CustomCodeController::class, 'evaluate'])->whereNumber('id')->name('custom-code.evaluate');
        Route::resource('users', UserController::class)
            ->except(['show']);

        Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
        Route::put('analytics', [AnalyticsController::class, 'update'])->name('analytics.update');
        Route::post('cache/refresh', [CacheController::class, 'refresh'])->name('cache.refresh');
        Route::get('developer-tools', [DeveloperToolsController::class, 'index'])->name('developer-tools.index');
        Route::post('developer-tools/download', [DeveloperToolsController::class, 'download'])->name('developer-tools.download')->middleware(RequireLiveEditor::class);
        Route::post('developer-tools/upload', [DeveloperToolsController::class, 'upload'])->name('developer-tools.upload')->middleware(RequireLiveEditor::class);
        Route::get('developer-tools/review/{token}', [DeveloperToolsController::class, 'review'])->name('developer-tools.review')->where('token', '[A-Za-z0-9]{40}')->middleware(RequireLiveEditor::class);
        Route::post('developer-tools/review/{token}/restore', [DeveloperToolsController::class, 'restore'])->name('developer-tools.restore')->where('token', '[A-Za-z0-9]{40}')->middleware(RequireLiveEditor::class);
        Route::delete('developer-tools/review/{token}', [DeveloperToolsController::class, 'discard'])->name('developer-tools.discard')->where('token', '[A-Za-z0-9]{40}')->middleware(RequireLiveEditor::class);
        Route::post('developer-tools/prune', [DeveloperToolsController::class, 'prune'])->name('developer-tools.prune')->middleware(RequireLiveEditor::class);
        Route::get('email', [EmailController::class, 'index'])->name('email.index');
        Route::put('email', [EmailController::class, 'update'])->name('email.update');
        Route::post('email/test', [EmailController::class, 'sendTestEmail'])->name('email.test');
        Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
        Route::put('settings', [SettingController::class, 'update'])->name('settings.update');
        // Header and footer: one screen each centralising what the client can edit (docs/admin/layout.md).
        Route::get('header', [HeaderController::class, 'index'])->name('header.index')->middleware(RequireLiveEditor::class);
        Route::put('header', [HeaderController::class, 'update'])->name('header.update')->middleware(RequireLiveEditor::class);
        Route::get('footer', [FooterController::class, 'index'])->name('footer.index')->middleware(RequireLiveEditor::class);
        Route::put('footer', [FooterController::class, 'update'])->name('footer.update')->middleware(RequireLiveEditor::class);
        // Redirects and the 404 monitor. The screen is a Developer Tools tab; these are the
        // write actions behind it (docs/operations/redirects.md).
        Route::get('redirects/sample-csv', [RedirectController::class, 'sampleCsv'])->name('redirects.sample-csv');
        Route::post('redirects/import', [RedirectController::class, 'import'])->name('redirects.import')->middleware(RequireLiveEditor::class);
        Route::post('redirects', [RedirectController::class, 'store'])->name('redirects.store')->middleware(RequireLiveEditor::class);
        Route::put('redirects/{redirect}', [RedirectController::class, 'update'])->name('redirects.update')->middleware(RequireLiveEditor::class);
        Route::delete('redirects/{redirect}', [RedirectController::class, 'destroy'])->name('redirects.destroy')->middleware(RequireLiveEditor::class);
        Route::delete('redirects/not-found/all', [RedirectController::class, 'clearNotFound'])->name('redirects.not-found.clear')->middleware(RequireLiveEditor::class);
        Route::delete('redirects/not-found/{notFoundHit}', [RedirectController::class, 'destroyNotFound'])->name('redirects.not-found.destroy')->middleware(RequireLiveEditor::class);

        Route::get('languages', [LanguageController::class, 'index'])->name('languages.index');
        Route::post('languages', [LanguageController::class, 'store'])->name('languages.store');
        Route::put('languages', [LanguageController::class, 'update'])->name('languages.update');
    });
});

// Guest routes
Route::middleware(['guest', 'admin.locale'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('password/reset', [ForgotPasswordController::class, 'showLinkRequestForm'])->name('password.request');
    Route::post('password/email', [ForgotPasswordController::class, 'sendResetLinkEmail'])->middleware('throttle:password-recovery')->name('password.email');
    Route::get('password/reset/{token}', [ResetPasswordController::class, 'showResetForm'])->name('password.reset');
    Route::post('password/reset', [ResetPasswordController::class, 'reset'])->name('password.update');
});

Route::get('admin/profile/email/verify/{user}/{token}', [ProfileController::class, 'verifyEmail'])->middleware('signed')->name('admin.profile.email.verify');

Route::middleware(['guest', 'admin.locale'])->group(function () {
    Route::get('admin/two-factor', [TwoFactorController::class, 'challenge'])->name('admin.two-factor.challenge');
    Route::post('admin/two-factor', [TwoFactorController::class, 'verifyChallenge'])->middleware('throttle:two-factor')->name('admin.two-factor.verify');
});
