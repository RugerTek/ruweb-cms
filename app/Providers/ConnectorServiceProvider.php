<?php

namespace App\Providers;

use App\Connectors\ConnectorRegistry;
use App\Connectors\Meta\Connect\HttpRuwebConnectClient;
use App\Connectors\Meta\Graph\HttpMetaGraphClient;
use App\Contracts\Connectors\MetaGraphClient;
use App\Contracts\Connectors\RuwebConnectClient;
use App\Listeners\Connectors\MetaCatalogChanges;
use App\Models\Connector;
use App\Models\Media;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Connectors\ConnectorOperations;
use App\Services\Media\FeedImage;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Connectors (Admin > Connectors, public feeds; PLAN.md §4). Always on: the section exists on every site and each
 * connector states its own requirements. Routes live in routes/connectors.php (public feed, before the page
 * catch-all) and routes/connectors-admin.php (inside the admin-only panel group).
 */
class ConnectorServiceProvider extends ServiceProvider
{
    /** Connector columns whose change alters a feed; fetch logging and counters do not. */
    private const FEED_COLUMNS = ['enabled', 'settings_json', 'token_hash'];

    public function register(): void
    {
        $this->app->singleton(ConnectorRegistry::class);
        // Meta v2 (plan-meta-v2): Graph API and RugerTek Connect clients; tests swap them for Tests\Support fakes.
        $this->app->bind(MetaGraphClient::class, HttpMetaGraphClient::class);
        $this->app->bind(RuwebConnectClient::class, HttpRuwebConnectClient::class);
    }

    public function boot(): void
    {
        Gate::define(ConnectorOperations::ABILITY, fn (User $user): bool => $user->isAdmin());

        RateLimiter::for('feeds', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        $touch = fn () => app(ConnectorOperations::class)->touchFeeds();
        foreach ([Product::class, ProductVariant::class, Media::class] as $model) {
            $model::saved($touch);
            $model::deleted($touch);
        }
        // Like the medium's own files (Media::booted()), its feed JPEG derivatives go once the delete commits.
        Media::deleted(fn (Media $media) => DB::afterCommit(fn () => app(FeedImage::class)->forget($media)));
        Connector::created($touch);
        Connector::updated(function (Connector $connector) use ($touch) {
            if ($connector->wasChanged(self::FEED_COLUMNS)) {
                $touch();
            }
        });
        Connector::deleted($touch);
        // Meta v2: the same changes, published at once to the connected catalog (items_batch, plan-meta-v2 track 3a).
        Event::subscribe(MetaCatalogChanges::class);
    }
}
