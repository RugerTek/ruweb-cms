<?php

namespace App\Providers;

use App\Models\Menu;
use App\Models\Post;
use App\Models\Setting;
use App\Models\Taxonomy;
use App\Policies\MenuPolicy;
use App\Policies\PostPolicy;
use App\Policies\TaxonomyPolicy;
use App\Services\Forms\Recaptcha;
use App\Services\Revisions\ContentRevisionLedger;
use App\Support\AdminCapabilities;
use App\Support\LinkDestination;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The ledger coalesces writes per request and can be muted for bulk
        // imports, so every caller must share one instance.
        $this->app->singleton(ContentRevisionLedger::class);
        // The reCAPTCHA rule stores the verified score for the submission that follows.
        $this->app->singleton(Recaptcha::class);
    }

    public function boot(): void
    {
        // Fail explicitly on ambiguous deployment configuration, also when caches are used.
        app(AdminCapabilities::class)->profile();

        // Public forms: per-IP limit shared by every form route. See docs/content/forms.md.
        RateLimiter::for('forms', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        Gate::policy(Menu::class, MenuPolicy::class);
        Gate::policy(Post::class, PostPolicy::class);
        Gate::policy(Taxonomy::class, TaxonomyPolicy::class);

        ResetPassword::createUrlUsing(function ($user, string $token) {
            return route('admin.password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()]);
        });

        View::composer('layouts.public', function ($view) {
            $siteLogo = Setting::getLocalized('site_logo');

            // Header / footer fields edited from their admin screens (docs/admin/layout.md).
            $view->with([
                'siteName' => Setting::getLocalized('site_name') ?: config('app.name'),
                'siteLogo' => $siteLogo?->url(),
                'siteLogoMedia' => $siteLogo,
                'headerCtaLabel' => Setting::get('header_cta_enabled') ? Setting::getLocalized('header_cta_label') : null,
                'headerCtaUrl' => Setting::get('header_cta_enabled')
                    ? LinkDestination::resolve(Setting::get('header_cta_page'), Setting::get('header_cta_url'))
                    : null,
                'headerCtaNewTab' => (bool) Setting::get('header_cta_new_tab'),
                'footerTagline' => Setting::getLocalized('footer_tagline'),
                'footerCopyright' => Setting::getLocalized('footer_copyright'),
                'footerCredit' => Setting::get('footer_credit_enabled') ? Setting::getLocalized('footer_credit') : null,
            ]);
        });

        View::composer(['admin.layouts.app', 'admin.layouts.guest', 'admin.layouts.editor', 'admin.partials.sidebar', 'admin.partials.navbar', 'admin.auth.*', 'oauth.*'], function ($view) {
            $view->with('siteName', Setting::get('site_name') ?: config('app.name'));
        });
    }
}
