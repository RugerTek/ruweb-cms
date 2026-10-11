<?php

use App\Http\Controllers\OAuthController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

// OAuth metadata discovery
Route::get('/.well-known/oauth-authorization-server', [OAuthController::class, 'metadata'])
    ->withoutMiddleware([PreventRequestForgery::class]);

// Authorization endpoint (GET renders form, POST processes it)
Route::get('/authorize', [OAuthController::class, 'showAuthorize'])->name('oauth.authorize');
Route::post('/authorize', [OAuthController::class, 'handleAuthorize'])->middleware('throttle:oauth-login')->name('oauth.authorize.submit');

// Token endpoint — no CSRF (claude.ai POSTs machine-to-machine)
Route::post('/token', [OAuthController::class, 'token'])
    ->middleware('throttle:oauth-token')
    ->name('oauth.token')
    ->withoutMiddleware([PreventRequestForgery::class]);
