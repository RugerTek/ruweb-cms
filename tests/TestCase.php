<?php

namespace Tests;

use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\ShippingZone;
use App\Providers\ShopServiceProvider;
use App\Support\AdminLoginPath;
use App\Support\SettingDefaults;
use App\Support\ShopModule;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\Support\CheckoutFixtures;
use Tests\Support\FakeBancard;
use Tests\Support\FakeCatalogSource;
use Tests\Support\FakeFeedConnector;
use Tests\Support\FakePaymentProvider;

abstract class TestCase extends BaseTestCase
{
    /** Isolated snapshot root for this test, never the developer's real storage. */
    protected ?string $snapshotsRoot = null;

    protected function setUp(): void
    {
        // Setting caches values statically and the app reads shop_mode while booting: never boot on a previous test's cache.
        Setting::clearCache();
        parent::setUp();

        Setting::clearCache();
        AdminLoginPath::clearCache();

        $this->snapshotsRoot = sys_get_temp_dir().'/ruweb-cms-tests/'.uniqid('snapshots-', true);
        config(['cms.snapshots.root' => $this->snapshotsRoot, 'cms.snapshots.maintenance_mode' => false]);
    }

    protected function tearDown(): void
    {
        if ($this->snapshotsRoot !== null && is_dir($this->snapshotsRoot)) {
            File::deleteDirectory($this->snapshotsRoot);
        }

        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function adminProfiles(): array
    {
        return ['full' => ['full'], 'essential' => ['essential']];
    }

    /** Select a profile explicitly, including its route registration contract. */
    protected function useAdminProfile(string $profile): void
    {
        config(['cms.admin_profile' => $profile]);
        $this->rebuildRoutes();
    }

    /**
     * Select a shop module mode (off, catalog, checkout) as the administrator would, under a config limit
     * (`cms.shop.mode`, no limit by default). Writes the `shop_mode` setting directly, so `checkout` works before
     * it is delivered, and re-syncs the registries the provider merges. Routes need no rebuild: the shop routes are
     * always registered and follow the mode per request. The shop tables are ordinary migrations.
     */
    protected function useShop(string $mode, string $limit = 'checkout'): void
    {
        config(['cms.shop.mode' => $limit]);
        Setting::updateOrCreate(['key' => ShopModule::SETTING], ['value' => $mode] + SettingDefaults::for(ShopModule::SETTING));
        Setting::clearCache();
        ShopServiceProvider::syncRegistries();
    }

    /**
     * Replace the registered connectors and catalog sources with one shared FakeFeedConnector and FakeCatalogSource
     * (PLAN.md §5, stage 2 P0): `cms.connectors` / `cms.catalog_sources` point at classes the parallel tracks
     * deliver, so tests of one track never depend on another. Configure them through app(FakeFeedConnector::class)
     * and app(FakeCatalogSource::class); the default keys match the real ones (`meta`, `products`).
     */
    protected function useFakeConnectors(string $connector = 'meta', string $source = 'products'): FakeFeedConnector
    {
        $fake = new FakeFeedConnector;
        $fake->key = $connector;
        $fake->sourceKey = $source;
        $catalog = new FakeCatalogSource;
        $catalog->key = $source;
        $this->app->instance(FakeFeedConnector::class, $fake);
        $this->app->instance(FakeCatalogSource::class, $catalog);
        config(['cms.connectors' => [$connector => FakeFeedConnector::class], 'cms.catalog_sources' => [$source => FakeCatalogSource::class]]);

        return $fake;
    }

    /**
     * Selling online, ready to buy (PLAN.md E3.5): shop mode `checkout`, bank transfer and cash on delivery enabled
     * with their data (CheckoutFixtures), store pickup on, one delivery zone and an orders email. Sets the stored
     * mode directly, like useShop(), so it does not depend on the readiness checks of Settings > Shop.
     */
    protected function useCheckout(): ShippingZone
    {
        $this->useShop('checkout');
        CheckoutFixtures::enableManualMethods();
        foreach (['shop_pickup_enabled' => '1', 'shop_pickup_address' => 'Av. España 123, Asunción', 'shop_orders_email' => 'pedidos@example.com'] as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value] + SettingDefaults::for($key));
        }
        Setting::clearCache();

        return CheckoutFixtures::shippingZone();
    }

    /**
     * Fake the Bancard API (tests/Support/FakeBancard.php) and store its keys in the `bancard` method, enabled in
     * $environment and bound to this origin. The same instance is in the container (app(FakeBancard::class)).
     * Whether the method is offered still depends on cms.shop.payment_providers having a `bancard` entry.
     */
    protected function useFakeBancard(string $environment = PaymentMethod::ENVIRONMENT_STAGING): FakeBancard
    {
        $fake = (new FakeBancard)->install();
        $this->app->instance(FakeBancard::class, $fake);
        $fake->configure(PaymentMethod::query()->where('key', 'bancard')->firstOrFail(), $environment);

        return $fake;
    }

    /**
     * Register FakePaymentProvider (an iframe provider) under `fake` with an enabled, ready `fake` payment method, so
     * the checkout and order page can be tested without the Bancard tracks. Configure it through the returned
     * instance (also app(FakePaymentProvider::class)).
     */
    protected function useFakePaymentProvider(): FakePaymentProvider
    {
        $fake = new FakePaymentProvider;
        $this->app->instance(FakePaymentProvider::class, $fake);
        config(['cms.shop.payment_providers.'.FakePaymentProvider::KEY => FakePaymentProvider::class]);
        PaymentMethod::query()->updateOrCreate(
            ['key' => FakePaymentProvider::KEY],
            ['provider' => FakePaymentProvider::KEY, 'enabled' => true, 'sort_order' => 10],
        );

        return $fake;
    }

    /**
     * Re-evaluate the HTTP route files so the login route reflects the current
     * admin_login_path. Production re-runs route files on every request; the
     * test process boots once, so a mid-test path change needs an explicit
     * rebuild. Mirrors the route loading in bootstrap/app.php.
     */
    protected function rebuildRoutes(): void
    {
        AdminLoginPath::clearCache();

        $router = $this->app['router'];
        $router->setRoutes(new RouteCollection);

        Route::middleware('web')->group(base_path('routes/web.php'));
        Route::middleware('web')->group(base_path('routes/admin.php'));
        Route::prefix('mcp')->middleware('mcp')->group(base_path('routes/mcp.php'));

        // Names are assigned fluently after the route is added, so the
        // collection's name lookup must be rebuilt for route()/getByName().
        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }
}
