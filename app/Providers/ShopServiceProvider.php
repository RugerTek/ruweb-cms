<?php

namespace App\Providers;

use App\Listeners\Shop\SendOrderMails;
use App\Support\ShopModule;
use App\View\Composers\CartComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * Optional shop module (docs/shop/catalog.md). The tables always exist (database/migrations); the effective mode
 * (App\Support\ShopModule, a panel setting capped by config) decides the registry entries here, and the shop
 * routes are always registered but answer only while the catalog is on (App\Http\Middleware\EnsureShopCatalog).
 * Selling online (PLAN.md, stage 3) adds the `cart` and `webhooks` rate limits, the header cart composer and the
 * order mail subscriber; the last two are wired only once their classes exist.
 */
class ShopServiceProvider extends ServiceProvider
{
    /** @var array{collection: bool, schema: bool, index: bool, taxonomy_types: list<string>} what syncRegistries() merged */
    private static array $merged = ['collection' => false, 'schema' => false, 'index' => false, 'taxonomy_types' => []];

    public function register(): void
    {
        // A fresh application starts from config/cms.php, which never contains the merged entries.
        self::$merged = ['collection' => false, 'schema' => false, 'index' => false, 'taxonomy_types' => []];
    }

    /** Header partial that shows the cart icon (track 3A); CartComposer itself stays silent unless the mode is `checkout`. */
    public const CART_VIEWS = ['partials.header'];

    public function boot(): void
    {
        // The mode is a setting: read it once Eloquent is booted, before the route files and any request walk the registries.
        self::syncRegistries();

        // Cart changes: generous for a person, a wall for a script filling carts.
        RateLimiter::for('cart', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        // Payment provider notifications: Bancard's empty monitoring ping every 5 minutes fits with room to spare.
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));

        if (class_exists(CartComposer::class)) {
            View::composer(self::CART_VIEWS, CartComposer::class);
        }
        // An event subscriber (subscribe(Dispatcher)), not `handle*` methods: Laravel's listener discovery scans
        // app/Listeners for those and would register them a second time.
        if (class_exists(SendOrderMails::class)) {
            Event::subscribe(SendOrderMails::class);
        }
    }

    /**
     * Mirror the current mode into the registries everything else already walks (`cms.collections`,
     * `cms.content_schemas`, `cms.taxonomy_types`, `cms.custom_code.indexes`). Idempotent; turning the module off removes only what this
     * provider added, so a site that registers `brand` itself keeps it.
     */
    public static function syncRegistries(): void
    {
        $catalog = app(ShopModule::class)->catalog();
        $collections = config('cms.collections', []);
        $schemas = config('cms.content_schemas', []);
        $types = config('cms.taxonomy_types', []);
        $indexes = config('cms.custom_code.indexes', []);

        if ($catalog) {
            if (! isset($collections['products']) || self::$merged['collection']) {
                $collections['products'] = config('cms.shop.collection');
                self::$merged['collection'] = true;
            }
            if (! isset($schemas['product']) || self::$merged['schema']) {
                $schemas['product'] = config('cms.shop.content_schema');
                self::$merged['schema'] = true;
            }
            if (! isset($indexes['shop']) || self::$merged['index']) {
                $indexes['shop'] = config('cms.shop.custom_code_index');
                self::$merged['index'] = true;
            }
            foreach (config('cms.shop.taxonomy_types', []) as $type) {
                if (! in_array($type, $types, true)) {
                    $types[] = $type;
                    self::$merged['taxonomy_types'][] = $type;
                }
            }
        } else {
            if (self::$merged['collection']) {
                unset($collections['products']);
            }
            if (self::$merged['schema']) {
                unset($schemas['product']);
            }
            if (self::$merged['index']) {
                unset($indexes['shop']);
            }
            $types = array_values(array_diff($types, self::$merged['taxonomy_types']));
            self::$merged = ['collection' => false, 'schema' => false, 'index' => false, 'taxonomy_types' => []];
        }

        config(['cms.collections' => $collections, 'cms.content_schemas' => $schemas, 'cms.taxonomy_types' => $types, 'cms.custom_code.indexes' => $indexes]);
    }
}
