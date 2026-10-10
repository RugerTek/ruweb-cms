<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class SecurityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('oauth-login', fn (Request $request) => [
            Limit::perMinute(20)->by('oauth-ip:'.$request->ip()),
            Limit::perMinute(5)->by('oauth-account:'.hash('sha256', mb_strtolower(trim((string) $request->input('email'))))),
        ]);
        RateLimiter::for('oauth-token', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('password-recovery', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('two-factor', fn (Request $request) => [
            Limit::perMinute(5)->by('2fa:'.$request->session()->get('two_factor_user', $request->user()?->id).':'.$request->ip()),
            Limit::perMinute(20)->by('2fa-ip:'.$request->ip()),
        ]);
    }
}
