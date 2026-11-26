<?php

namespace Tests\Feature;

use App\Connectors\CatalogItem;
use App\Connectors\ConnectorRegistry;
use App\Connectors\ConnectorRequirement;
use App\Connectors\ConnectorStatus;
use App\Connectors\FeedResult;
use App\Connectors\FeedWarning;
use App\Exceptions\RevisionConflict;
use App\Models\Connector;
use App\Models\Product;
use App\Models\User;
use App\Services\Connectors\ConnectorOperations;
use App\Support\AdminLoginPath;
use App\Support\ShopModule;
use App\ValueObjects\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\FakeCatalogSource;
use Tests\TestCase;

/** Shared contracts of Admin > Connectors (PLAN.md §4.2, stage 2 P0), exercised with the test fakes. */
class ConnectorContractsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function editor(): User
    {
        return User::factory()->create(['role' => 'editor']);
    }

    private function operations(): ConnectorOperations
    {
        return app(ConnectorOperations::class);
    }

    private function registry(): ConnectorRegistry
    {
        return app(ConnectorRegistry::class);
    }

    public function test_config_registers_meta_and_the_product_source(): void
    {
        $this->assertSame(['meta', 'bancard', 'meta_pixel', 'amiba_chat'], array_keys(config('cms.connectors')));
        $this->assertSame('App\Connectors\AmibaChat\AmibaChatConnector', config('cms.connectors.amiba_chat'));
        $this->assertSame('App\\Connectors\\MetaPixel\\MetaPixelConnector', config('cms.connectors.meta_pixel'));
        $this->assertSame('App\Connectors\Bancard\BancardConnector', config('cms.connectors.bancard'));
        $this->assertSame('App\Connectors\Meta\MetaCatalogConnector', config('cms.connectors.meta'));
        $this->assertSame('App\Connectors\Sources\ShopProductSource', config('cms.catalog_sources.products'));
        $this->assertContains('feeds/*', config('cms.redirects.ignore_paths'));
    }

    public function test_registry_resolves_fakes_and_rejects_unknown_or_mismatched_entries(): void
    {
        $fake = $this->useFakeConnectors();

        $this->assertSame(['meta' => $fake], $this->registry()->all());
        $this->assertSame($fake, $this->registry()->feed('meta'));
        $this->assertSame(app(FakeCatalogSource::class), $this->registry()->source('products'));
        $this->assertFalse($this->registry()->has('google'));
        $this->assertNull($this->registry()->record('meta'));
        $this->assertSame(['brand' => 'Default brand', 'condition' => 'new'], $this->registry()->settings('meta'));

        try {
            $this->registry()->get('google');
            $this->fail('An unknown connector must be a 404.');
        } catch (NotFoundHttpException) {
        }

        config(['cms.connectors.other' => get_class($fake)]);
        $this->expectException(LogicException::class);
        $this->registry()->get('other');
    }

    public function test_status_follows_the_shop_catalog_and_the_stored_row(): void
    {
        $this->useFakeConnectors();
        $this->useShop('off');

        $this->assertSame(ConnectorStatus::NeedsAction, $this->registry()->status('meta'));
        [$requirement] = $this->registry()->unmet('meta');
        $this->assertSame(ConnectorRequirement::SHOP_CATALOG, $requirement->key);
        $this->assertSame('connectors.requirement_shop_catalog', $requirement->message);
        $this->assertSame('admin.connectors.enable-catalog', $requirement->action->route);
        $this->assertSame(route('admin.connectors.enable-catalog', ['connector' => 'meta']), $requirement->action->url('meta'));

        $this->useShop('off', 'off');
        [$requirement] = $this->registry()->unmet('meta');
        $this->assertSame('connectors.requirement_shop_unavailable', $requirement->message);
        $this->assertNull($requirement->action);

        $this->useShop('catalog');
        $this->assertSame(ConnectorStatus::Available, $this->registry()->status('meta'));
        $this->operations()->activate($this->admin(), 'meta');
        $this->assertSame(ConnectorStatus::Connected, $this->registry()->status('meta'));

        // Turning the shop off later pauses the connector without touching its row.
        $this->useShop('off');
        $this->assertSame(ConnectorStatus::NeedsAction, $this->registry()->status('meta'));
        $this->assertTrue($this->registry()->record('meta')->enabled);
        $this->assertSame('connectors.status_needs_action', ConnectorStatus::NeedsAction->label());
    }

    public function test_editors_cannot_run_any_connector_operation(): void
    {
        $this->useFakeConnectors();
        $this->useShop('catalog');
        $editor = $this->editor();
        $calls = [
            fn () => $this->operations()->activate($editor, 'meta'),
            fn () => $this->operations()->deactivate($editor, 'meta'),
            fn () => $this->operations()->updateSettings($editor, 'meta', [], null),
            fn () => $this->operations()->rotateToken($editor, 'meta'),
            fn () => $this->operations()->enableCatalog($editor),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('An editor must be refused.');
            } catch (AuthorizationException) {
            }
        }
        $this->assertSame(0, Connector::count());
        $this->assertSame('catalog', app(ShopModule::class)->mode());
    }

    public function test_activate_requires_the_catalog_and_creates_the_token_once(): void
    {
        $this->useFakeConnectors();
        $this->useShop('off');
        $admin = $this->admin();

        try {
            $this->operations()->activate($admin, 'meta');
            $this->fail('Activation must fail while a requirement is unmet.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('connector', $exception->errors());
        }
        $this->assertSame(0, Connector::count());

        $this->useShop('catalog');
        $connector = $this->operations()->activate($admin, 'meta');
        $token = $connector->plainToken();
        $this->assertTrue($connector->enabled);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{48}$/', $token);
        $this->assertSame(hash('sha256', $token), DB::table('connectors')->value('token_hash'));
        $this->assertNotSame($token, DB::table('connectors')->value('token'), 'The token copy is stored encrypted.');
        $this->assertArrayNotHasKey('token', $connector->toArray());
        $this->assertArrayNotHasKey('token_hash', $connector->toArray());
        $this->assertSame(route('connectors.feed', ['connector' => 'meta', 'token' => $token]), $connector->feedUrl());
        $this->assertSame($connector->id, $this->registry()->feedRecord('meta', $token)?->id);

        $this->operations()->deactivate($admin, 'meta');
        $this->assertNull($this->registry()->feedRecord('meta', $token));
        $again = $this->operations()->activate($admin, 'meta');
        $this->assertSame($token, $again->plainToken(), 'Reactivating keeps the URL.');
        $this->assertSame(1, Connector::count());
    }

    public function test_deactivate_keeps_settings_and_token_and_never_creates_a_row(): void
    {
        $this->useFakeConnectors();
        $this->useShop('catalog');
        $admin = $this->admin();

        $this->assertFalse($this->operations()->deactivate($admin, 'meta')->exists);
        $this->assertSame(0, Connector::count());

        $this->operations()->activate($admin, 'meta');
        $this->operations()->updateSettings($admin, 'meta', ['brand' => 'Casa'], null);
        $hash = Connector::first()->token_hash;
        $connector = $this->operations()->deactivate($admin, 'meta');

        $this->assertFalse($connector->fresh()->enabled);
        $this->assertSame($hash, $connector->fresh()->token_hash);
        $this->assertSame('Casa', $this->registry()->settings('meta')['brand']);
        $this->assertSame(ConnectorStatus::Available, $this->registry()->status('meta'));
    }

    public function test_update_settings_validates_merges_and_checks_the_revision(): void
    {
        $this->useFakeConnectors();
        $this->useShop('catalog');
        $admin = $this->admin();
        $connector = $this->operations()->activate($admin, 'meta');
        $revision = $this->operations()->revision($connector);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $revision);

        $updated = $this->operations()->updateSettings($admin, 'meta', ['condition' => 'used', 'unknown' => 'x'], $revision);
        $this->assertSame(['brand' => 'Default brand', 'condition' => 'used'], $updated->fresh()->settings_json);
        $this->assertNotSame($revision, $this->operations()->revision($updated->fresh()));

        // Partial: only brand changes, condition stays.
        $this->operations()->updateSettings($admin, 'meta', ['brand' => 'Mi marca'], null);
        $this->assertSame(['brand' => 'Mi marca', 'condition' => 'used'], $this->registry()->settings('meta'));

        try {
            $this->operations()->updateSettings($admin, 'meta', ['condition' => 'broken'], null);
            $this->fail('Invalid settings must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('condition', $exception->errors());
        }

        try {
            $this->operations()->updateSettings($admin, 'meta', ['brand' => 'Otra'], $revision);
            $this->fail('A stale revision must conflict.');
        } catch (RevisionConflict $conflict) {
            $this->assertSame(409, $conflict->getStatusCode());
        }
        $this->assertSame('Mi marca', $this->registry()->settings('meta')['brand']);

        $this->expectException(ValidationException::class);
        $this->operations()->updateSettings($admin, 'meta', [], 'not-a-revision');
    }

    public function test_update_settings_before_activation_creates_a_disabled_row_without_token(): void
    {
        $this->useFakeConnectors();
        $this->useShop('off');

        $connector = $this->operations()->updateSettings($this->admin(), 'meta', ['brand' => 'Casa'], null);

        $this->assertTrue($connector->exists);
        $this->assertFalse($connector->enabled);
        $this->assertNull($connector->token_hash);
        $this->assertNull($connector->feedUrl());
    }

    public function test_rotate_token_invalidates_the_previous_url(): void
    {
        $this->useFakeConnectors();
        $this->useShop('catalog');
        $admin = $this->admin();

        try {
            $this->operations()->rotateToken($admin, 'meta');
            $this->fail('A connector never activated has no token to rotate.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('connector', $exception->errors());
        }

        $old = $this->operations()->activate($admin, 'meta')->plainToken();
        $this->travel(5)->minutes();
        $new = $this->operations()->rotateToken($admin, 'meta');
        $connector = Connector::first();

        $this->assertNotSame($old, $new);
        $this->assertSame(hash('sha256', $new), $connector->token_hash);
        $this->assertFalse($connector->matchesToken($old));
        $this->assertSame($new, $connector->plainToken());
        $this->assertNull($this->registry()->feedRecord('meta', $old));
        $this->assertNotNull($this->registry()->feedRecord('meta', $new));
        $this->assertTrue($connector->token_rotated_at->isAfter(now()->subMinute()));
    }

    public function test_feed_record_is_null_while_the_shop_is_off_or_for_unknown_connectors(): void
    {
        $this->useFakeConnectors();
        $this->useShop('catalog');
        $token = $this->operations()->activate($this->admin(), 'meta')->plainToken();

        $this->assertNotNull($this->registry()->feedRecord('meta', $token));
        $this->assertNull($this->registry()->feedRecord('google', $token));
        $this->useShop('off');
        $this->assertNull($this->registry()->feedRecord('meta', $token));
    }

    public function test_a_token_encrypted_with_another_key_is_not_shown_but_still_validates(): void
    {
        $this->useFakeConnectors();
        $this->useShop('catalog');
        $token = $this->operations()->activate($this->admin(), 'meta')->plainToken();
        DB::table('connectors')->update(['token' => 'eyJpdiI6ImJyb2tlbiJ9']);

        $connector = Connector::first();
        $this->assertNull($connector->plainToken());
        $this->assertNull($connector->feedUrl());
        $this->assertTrue($connector->matchesToken($token));
    }

    public function test_enable_catalog_switches_the_shop_through_its_operation_and_respects_the_limit(): void
    {
        $this->useFakeConnectors();
        $admin = $this->admin();

        $this->useShop('off', 'off');
        try {
            $this->operations()->enableCatalog($admin);
            $this->fail('The config limit must win.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('mode', $exception->errors());
        }
        $this->assertSame('off', app(ShopModule::class)->stored());

        $this->useShop('off');
        $this->operations()->enableCatalog($admin);
        $this->assertSame('catalog', app(ShopModule::class)->mode());
        $this->assertSame(ConnectorStatus::Available, $this->registry()->status('meta'));

        // Never lowers a shop that already sells.
        $this->useShop('checkout');
        $this->operations()->enableCatalog($admin);
        $this->assertSame('checkout', app(ShopModule::class)->stored());
    }

    public function test_preview_walks_the_whole_source_and_samples_included_items(): void
    {
        $this->useFakeConnectors();
        $this->useShop('catalog');
        $source = app(FakeCatalogSource::class);
        $source->items = [
            FakeCatalogSource::item('p1'),
            FakeCatalogSource::item('p2', ['price' => null]),
            FakeCatalogSource::item('p3', ['brand' => null]),
            FakeCatalogSource::item('p4', ['imageMediaId' => null, 'price' => null]),
            FakeCatalogSource::item('p5', ['price' => 200000, 'salePrice' => 150000]),
        ];

        $result = $this->operations()->preview('meta', 2);

        $this->assertSame(3, $result->included);
        $this->assertSame(2, $result->skipped);
        $this->assertSame(['p1', 'p3'], array_map(fn (CatalogItem $item) => $item->id, $result->sample));
        $this->assertSame(
            [['p2', 'missing_price'], ['p4', 'missing_price'], ['p4', 'missing_image']],
            array_map(fn (FeedWarning $warning) => [$warning->itemId, $warning->code], $result->skippedWarnings())
        );
        [$notice] = $result->notices();
        $this->assertSame(['p3', FeedWarning::DEFAULT_BRAND, false], [$notice->itemId, $notice->code, $notice->skips]);
        $this->assertSame('/admin/products/p3/edit', $notice->editUrl);
        $this->assertSame('connectors.warning_default_brand', $notice->message());
        $this->assertSame(0, Connector::count(), 'Preview is read-only.');

        $source->available = false;
        $this->assertEquals(FeedResult::empty(), $this->operations()->preview('meta'));
    }

    public function test_record_fetch_and_counts(): void
    {
        $this->useFakeConnectors();
        $this->useShop('catalog');
        $connector = $this->operations()->activate($this->admin(), 'meta');

        $this->operations()->recordFetch($connector, 'Mozilla/5.0 '.str_repeat('x', 300));
        $connector->refresh();
        $this->assertNotNull($connector->last_fetched_at);
        $this->assertSame(255, mb_strlen($connector->last_fetch_agent));
        $this->assertNull($connector->last_meta_fetched_at);

        $this->travel(1)->hours();
        $this->operations()->recordFetch($connector, 'FakeBot/1.1 (+http://example.test)');
        $connector->refresh();
        $this->assertSame('FakeBot/1.1 (+http://example.test)', $connector->last_fetch_agent);
        $this->assertTrue($connector->last_meta_fetched_at->equalTo($connector->last_fetched_at));

        $this->operations()->recordCounts($connector, new FeedResult(7, 2, [], []));
        $this->assertSame([7, 2], [$connector->fresh()->last_item_count, $connector->fresh()->last_skipped_count]);
    }

    public function test_feeds_go_stale_when_catalog_records_or_connector_settings_change(): void
    {
        $this->useFakeConnectors();
        $this->useShop('catalog');
        $operations = $this->operations();
        $admin = $this->admin();

        $this->assertNull($operations->feedsTouchedAt());
        $this->assertTrue($operations->feedIsStale(time() - ConnectorOperations::FEED_TTL_SECONDS - 1));

        Product::create(['title' => 'Mate', 'slug' => 'mate']);
        $touched = $operations->feedsTouchedAt();
        $this->assertNotNull($touched);
        $this->assertTrue($operations->feedIsStale((int) floor($touched)));
        $this->assertFalse($operations->feedIsStale((int) floor($touched) + 1));

        $connector = $operations->activate($admin, 'meta');
        $afterActivate = $operations->feedsTouchedAt();
        $this->assertGreaterThan($touched, $afterActivate);

        // Fetch logging and counters do not invalidate the file.
        $operations->recordFetch($connector, 'fakebot');
        $operations->recordCounts($connector, FeedResult::empty());
        $this->assertSame($afterActivate, $operations->feedsTouchedAt());

        $operations->updateSettings($admin, 'meta', ['brand' => 'Nueva'], null);
        $this->assertGreaterThan($afterActivate, $operations->feedsTouchedAt());
    }

    public function test_catalog_items_and_warnings_reject_invalid_values(): void
    {
        $item = FakeCatalogSource::item('p1', ['price' => 200000, 'salePrice' => 150000]);
        $this->assertTrue($item->salePrice->equals(Money::fromInt(150000)));

        foreach ([
            fn () => FakeCatalogSource::item('p1', ['availability' => 'preorder']),
            fn () => FakeCatalogSource::item('p1', ['price' => 100, 'salePrice' => 100]),
            fn () => FakeCatalogSource::item('p1', ['price' => null, 'salePrice' => 100]),
            fn () => FakeCatalogSource::item(''),
            fn () => FeedWarning::for($item, 'made_up'),
        ] as $invalid) {
            try {
                $invalid();
                $this->fail('Invalid value accepted.');
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertTrue(FeedWarning::for($item, FeedWarning::NO_VARIANTS)->skips);
        $this->assertFalse(FeedWarning::for($item, FeedWarning::TITLE_TRUNCATED, ['limit' => 200])->skips);

        // A product with variants enabled but none loaded comes as `groupId === id`.
        $this->assertFalse($item->lacksVariants());
        $this->assertFalse(FakeCatalogSource::item('v1', ['groupId' => 'p1'])->lacksVariants());
        $this->assertTrue(FakeCatalogSource::item('p1', ['groupId' => 'p1'])->lacksVariants());
    }

    public function test_routes_are_registered_and_feeds_is_reserved(): void
    {
        $this->assertSame('/feeds/meta/'.str_repeat('a', 48).'.xml', route('connectors.feed', ['connector' => 'meta', 'token' => str_repeat('a', 48)], false));
        $this->assertContains('throttle:feeds', Route::getRoutes()->getByName('connectors.feed')->middleware());
        $this->assertNotNull(RateLimiter::limiter('feeds'));

        foreach (['index' => '/admin/connectors', 'show' => '/admin/connectors/meta', 'activate' => '/admin/connectors/meta/activate',
            'deactivate' => '/admin/connectors/meta/deactivate', 'settings' => '/admin/connectors/meta/settings',
            'rotate' => '/admin/connectors/meta/rotate', 'enable-catalog' => '/admin/connectors/meta/enable-catalog'] as $name => $path) {
            $route = Route::getRoutes()->getByName('admin.connectors.'.$name);
            $this->assertNotNull($route, $name);
            $this->assertContains('role:admin', $route->gatherMiddleware(), $name);
            $this->assertSame($path, route('admin.connectors.'.$name, $name === 'index' ? [] : ['connector' => 'meta'], false));
        }

        $this->assertSame('connectors.feed', Route::getRoutes()->match(request()->create('/feeds/meta/'.str_repeat('a', 48).'.xml'))->getName());
        // `feeds` is never a page slug.
        $this->assertNotSame('page.show', rescue(fn () => Route::getRoutes()->match(request()->create('/feeds/otra-cosa'))->getName(), null, false));
        $this->assertContains('feeds', AdminLoginPath::reservedRootSegments());
    }

    public function test_sidebar_shows_connectors_to_admins_whatever_the_shop_mode(): void
    {
        $this->useShop('off', 'off');
        $link = 'href="'.route('admin.connectors.index').'"';

        $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertOk()->assertSee($link, false)->assertSee(__('connectors.title'));
        $this->actingAs($this->editor())->get(route('admin.dashboard'))->assertOk()->assertDontSee($link, false);
    }

    public function test_translations_exist_in_both_languages_for_every_contract_key(): void
    {
        $es = require lang_path('es/connectors.php');
        $en = require lang_path('en/connectors.php');
        $this->assertSame(array_keys($es), array_keys($en));

        $keys = array_map(fn (ConnectorStatus $status) => $status->label(), ConnectorStatus::cases());
        $item = FakeCatalogSource::item('p1');
        foreach ([...FeedWarning::SKIPPING, ...FeedWarning::NOTICES] as $code) {
            $keys[] = FeedWarning::for($item, $code)->message();
        }
        $this->useShop('off');
        $requirement = ConnectorRequirement::shopCatalog();
        array_push($keys, $requirement->message, $requirement->action->label, $requirement->action->confirm, 'connectors.requirement_shop_unavailable', 'connectors.title');
        foreach ($keys as $key) {
            $short = substr($key, strlen('connectors.'));
            $this->assertArrayHasKey($short, $es, $key);
            $this->assertArrayHasKey($short, $en, $key);
        }
    }
}
