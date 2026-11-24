<?php

use App\Http\Controllers\ConnectorFeedController;
use App\Http\Controllers\RuwebConnectChallengeController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
 * Public connector feeds (PLAN.md §4.7), required from routes/web.php before the page catch-all and outside the
 * redirects/locale group. Always registered (route:cache): ConnectorFeedController answers 404 for an unknown or
 * disabled connector, a wrong token or an unmet requirement (App\Connectors\ConnectorRegistry::feedRecord()).
 * `feeds` is a reserved first segment for pages and `feeds/*` is ignored by the 404 monitor (config/cms.php).
 * A platform downloads it without cookies: no session, no CSRF cookie, so nothing stops a shared cache from
 * keeping the public answer and no session is created per download. The throttle (cache) still applies.
 */
Route::get('/feeds/{connector}/{token}.xml', [ConnectorFeedController::class, 'show'])
    ->where(['connector' => '[a-z0-9_]+', 'token' => '[A-Za-z0-9]{48}'])
    ->middleware('throttle:feeds')
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, EncryptCookies::class, AddQueuedCookiesToResponse::class])
    ->name('connectors.feed');

// RugerTek Connect site challenge (plan-meta-v2): the broker fetches it once while registering this origin. Same
// stateless middleware as the feeds; 404 unless a registration is in progress (RuwebConnectChallengeController).
Route::get('/.well-known/ruweb-connect/{token}', RuwebConnectChallengeController::class)
    ->where('token', '[A-Za-z0-9_-]{16,128}')
    ->middleware('throttle:30,1')
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, EncryptCookies::class, AddQueuedCookiesToResponse::class])
    ->name('connectors.ruweb-connect.challenge');
