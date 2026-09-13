<?php

namespace App\Providers;

use App\Models\Menu;
use App\Models\Post;
use App\Models\Setting;
use App\Models\Taxonomy;
use App\Policies\MenuPolicy;
use App\Policies\PostPolicy;
use App\Policies\TaxonomyPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The ledger coalesces writes per request and can be muted for bulk
        // imports, so every caller must share one instance.
        $this->app->singleton(\App\Services\Revisions\ContentRevisionLedger::class);
    }

    public function boot(): void
    {
        Gate::policy(Menu::class, MenuPolicy::class);
        Gate::policy(Post::class, PostPolicy::class);
        Gate::policy(Taxonomy::class, TaxonomyPolicy::class);

        ResetPassword::createUrlUsing(function ($user, string $token) {
            return route('admin.password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()]);
        });

        View::composer('layouts.public', function ($view) {
            $siteLogo = Setting::getLocalized('site_logo');

            $view->with([
                'siteName' => Setting::getLocalized('site_name') ?: config('app.name'),
                'siteLogo' => $siteLogo?->url(),
                'siteLogoMedia' => $siteLogo,
            ]);
        });

        View::composer(['admin.layouts.app', 'admin.layouts.guest', 'admin.layouts.editor', 'admin.partials.sidebar', 'admin.partials.navbar', 'admin.auth.*', 'oauth.*'], function ($view) {
            $view->with('siteName', Setting::get('site_name') ?: config('app.name'));
        });
    }
}
