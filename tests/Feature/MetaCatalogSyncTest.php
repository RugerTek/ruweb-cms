<?php

namespace Tests\Feature;

use App\Connectors\ConnectorRegistry;
use App\Connectors\ConnectorStatus;
use App\Connectors\Meta\Connect\MetaConnection;
use App\Connectors\Meta\Connect\MetaConnectionStore;
use App\Connectors\Meta\Graph\MetaBatchIssue;
use App\Connectors\Meta\Graph\MetaBatchStatus;
use App\Jobs\SyncMetaCatalogFeed;
use App\Models\Connector;
use App\Models\Locale;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Connectors\ConnectorOperations;
use App\Services\Connectors\Meta\MetaCatalogSync;
use App\Services\Connectors\Meta\MetaCatalogSyncException;
use App\Services\Connectors\Meta\MetaSyncStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeMetaGraph;
use Tests\TestCase;

/**
 * Meta v2 track 2c: the scheduled feed created by API (MetaCatalogSync) against FakeMetaGraph, which answers what Graph
 * v27 answered in the spike (§3.3). No request reaches Meta.
 */
class MetaCatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://mitienda.com.py';

    private const OTHER_FEED_ID = '1666548451688052';

    private User $admin;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => self::ORIGIN, 'cms.shop.currency' => 'PYG']);
        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
        Setting::updateOrCreate(['key' => 'site_name'], ['value' => 'Mates del Sur']);
        Setting::clearCache();
        $this->useShop('catalog');
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->editor = User::factory()->create(['role' => 'editor']);
    }

    private function sync(): MetaCatalogSync
    {
        return app(MetaCatalogSync::class);
    }

    private function store(): MetaConnectionStore
    {
        return app(MetaConnectionStore::class);
    }

    /** Connected through the broker (2b), no catalog chosen yet. */
    private function connect(?string $catalogId = null): MetaConnection
    {
        $connection = MetaConnection::connected(FakeMetaGraph::BUSINESS_ID, 'v27.0', ['catalog_management'], $this->admin->id);
        if ($catalogId !== null) {
            $connection = $connection->withCatalog($catalogId, 'Ruweb spike v2 commerce');
        }

        return $this->store()->save($connection, FakeMetaGraph::TOKEN);
    }

    private function provisioned(?FakeMetaGraph $fake = null): FakeMetaGraph
    {
        $fake ??= FakeMetaGraph::install();
        $this->connect();
        $this->sync()->provision(FakeMetaGraph::CATALOG_ID, $this->admin);

        return $fake;
    }

    /** @return list<array<string, mixed>> */
    private function callsTo(FakeMetaGraph $fake, string $method, string $path): array
    {
        return array_values(array_filter($fake->calls(), fn (array $call): bool => $call['method'] === $method && $call['path'] === $path));
    }

    /** @return array<string, mixed> */
    private function schedule(array $call): array
    {
        $schedule = $call['form']['schedule'] ?? [];

        return is_string($schedule) ? (array) json_decode($schedule, true) : (array) $schedule;
    }

    private function feedUrl(): string
    {
        $token = Connector::query()->where('key', 'meta')->firstOrFail()->plainToken();

        return self::ORIGIN.'/feeds/meta/'.$token.'.xml';
    }

    public function test_provision_creates_the_hourly_feed_with_the_site_currency_and_asks_meta_to_read_it_now(): void
    {
        $fake = FakeMetaGraph::install();
        $this->connect();

        $connection = $this->sync()->provision(FakeMetaGraph::CATALOG_ID, $this->admin);

        $this->assertSame(FakeMetaGraph::CATALOG_ID, $connection->catalogId);
        $this->assertSame('Ruweb spike v2 commerce', $connection->catalogName, 'The name comes from GET /{catalog}.');
        $this->assertSame(FakeMetaGraph::FEED_ID, $connection->productFeedId);
        $this->assertTrue(Connector::query()->where('key', 'meta')->firstOrFail()->enabled, 'Provisioning turns the connector on (its feed URL needs the token).');

        $create = $this->callsTo($fake, 'POST', FakeMetaGraph::CATALOG_ID.'/product_feeds');
        $this->assertCount(1, $create);
        $this->assertSame('Ruweb – Mates del Sur', $create[0]['form']['name']);
        $this->assertSame('PYG', $create[0]['form']['default_currency']);
        $this->assertSame(['interval' => 'HOURLY', 'url' => $this->feedUrl()], $this->schedule($create[0]));
        $this->assertStringStartsWith(self::ORIGIN.'/feeds/meta/', $this->feedUrl(), 'Absolute URL on APP_URL.');

        $uploads = $this->callsTo($fake, 'POST', FakeMetaGraph::FEED_ID.'/uploads');
        $this->assertCount(1, $uploads, 'An immediate upload after creating the feed.');
        $this->assertSame($this->feedUrl(), $uploads[0]['form']['url']);
        $this->assertSame([], $this->callsTo($fake, 'DELETE', FakeMetaGraph::CATALOG_ID), 'The catalog is never deleted.');

        $this->get($this->feedUrl())->assertOk();
    }

    public function test_provision_reuses_a_ruweb_feed_already_in_the_catalog_and_points_it_at_the_current_url(): void
    {
        // The spike's existing feed (product_feeds) points at /feeds/meta/ of https://tienda.example: same site, old token.
        config(['app.url' => 'https://tienda.example']);
        Http::fake(['graph.facebook.com/*/'.self::OTHER_FEED_ID.'*' => fn ($request) => Http::response(str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/uploads') ? ['id' => '777'] : ['success' => true])]);
        $fake = FakeMetaGraph::install();
        $this->connect();

        $connection = $this->sync()->provision(FakeMetaGraph::CATALOG_ID, $this->admin);

        $this->assertSame(self::OTHER_FEED_ID, $connection->productFeedId);
        $this->assertSame([], $this->callsTo($fake, 'POST', FakeMetaGraph::CATALOG_ID.'/product_feeds'), 'No second feed is created.');
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/'.self::OTHER_FEED_ID)
            && str_contains(urldecode($request->body()), 'https://tienda.example/feeds/meta/'.Connector::query()->where('key', 'meta')->firstOrFail()->plainToken().'.xml'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/'.self::OTHER_FEED_ID.'/uploads'));
    }

    public function test_a_generic_catalog_is_refused_and_nothing_is_stored(): void
    {
        $fake = FakeMetaGraph::install();
        $this->connect();

        try {
            $this->sync()->provision(FakeMetaGraph::GENERIC_CATALOG_ID, $this->admin);
            $this->fail('A generic catalog must be refused.');
        } catch (MetaCatalogSyncException $e) {
            $this->assertSame(MetaCatalogSyncException::CATALOG_NOT_COMMERCE, $e->reason);
            $this->assertStringContainsString('Productos online', $e->getMessage());
        }
        $this->assertNull($this->store()->get()->catalogId);
        $this->assertSame([], $this->callsTo($fake, 'POST', FakeMetaGraph::GENERIC_CATALOG_ID.'/product_feeds'));
    }

    public function test_only_admins_provision_and_a_manual_site_cannot(): void
    {
        $fake = FakeMetaGraph::install();
        try {
            $this->sync()->provision(FakeMetaGraph::CATALOG_ID, $this->admin);
            $this->fail('A manual (v1) site has nothing to provision.');
        } catch (MetaCatalogSyncException $e) {
            $this->assertSame(MetaCatalogSyncException::NOT_CONNECTED, $e->reason);
        }

        $this->connect();
        try {
            $this->sync()->provision(FakeMetaGraph::CATALOG_ID, $this->editor);
            $this->fail('An editor cannot provision.');
        } catch (AuthorizationException) {
            $this->assertNull($this->store()->get()->catalogId);
        }
        $this->assertSame([], $fake->calls());
    }

    public function test_app_removed_marks_needs_reconnect_and_drops_the_token(): void
    {
        $fake = FakeMetaGraph::install();
        $this->connect();
        $fake->removeApp();

        try {
            $this->sync()->provision(FakeMetaGraph::CATALOG_ID, $this->admin);
            $this->fail('190 must stop provisioning.');
        } catch (MetaCatalogSyncException $e) {
            $this->assertSame(MetaCatalogSyncException::REVOKED, $e->reason);
        }
        $connection = $this->store()->get();
        $this->assertTrue($connection->needsReconnect());
        $this->assertNull($this->store()->accessToken());
        $this->assertSame(ConnectorStatus::NeedsReconnect, app(ConnectorRegistry::class)->status('meta'));
        $this->assertSame(MetaSyncStatus::NEEDS_RECONNECT, $this->sync()->status()->state);
    }

    public function test_a_catalog_the_client_did_not_share_is_refused_without_flagging_the_connection(): void
    {
        $fake = FakeMetaGraph::install();
        $this->connect();
        $fake->unshareCatalog(FakeMetaGraph::OTHER_CATALOG_ID);

        try {
            $this->sync()->provision(FakeMetaGraph::OTHER_CATALOG_ID, $this->admin);
            $this->fail('100/33 must stop provisioning.');
        } catch (MetaCatalogSyncException $e) {
            $this->assertSame(MetaCatalogSyncException::CATALOG_UNAVAILABLE, $e->reason);
        }
        $this->assertFalse($this->store()->get()->needsReconnect());
        $this->assertNotNull($this->store()->accessToken());
    }

    public function test_unsharing_the_chosen_catalog_asks_to_reconnect_saying_why(): void
    {
        $fake = $this->provisioned();
        $fake->unshareCatalog();
        // Without a feed id the sync looks for the Ruweb feed in the catalog: GET /{catalog}/product_feeds → 100/33.
        $this->store()->save($this->store()->get()->withProductFeed(null));

        try {
            $this->sync()->syncNow($this->admin);
            $this->fail('100/33 on the stored catalog must stop the sync.');
        } catch (MetaCatalogSyncException $e) {
            $this->assertSame(MetaCatalogSyncException::CATALOG_UNAVAILABLE, $e->reason);
        }
        $connection = $this->store()->get();
        $this->assertTrue($connection->needsReconnect());
        $this->assertStringContainsString('Quitaste el acceso al catálogo «Ruweb spike v2 commerce»', (string) $connection->lastError);
    }

    public function test_meta_down_while_provisioning_keeps_the_catalog_and_queues_the_feed(): void
    {
        Queue::fake();
        Http::fake(['graph.facebook.com/*/'.FakeMetaGraph::CATALOG_ID.'?*' => Http::response(['error' => ['message' => 'Service temporarily unavailable', 'code' => 2, 'is_transient' => true]], 503)]);
        $fake = FakeMetaGraph::install();
        $this->connect();

        $connection = $this->sync()->provision(FakeMetaGraph::CATALOG_ID, $this->admin);

        $this->assertSame(FakeMetaGraph::CATALOG_ID, $connection->catalogId);
        $this->assertNull($connection->productFeedId);
        $this->assertFalse($connection->needsReconnect());
        Queue::assertPushed(SyncMetaCatalogFeed::class, fn (SyncMetaCatalogFeed $job): bool => $job->action === SyncMetaCatalogFeed::PROVISION);
        $this->assertSame([], $this->callsTo($fake, 'POST', FakeMetaGraph::CATALOG_ID.'/product_feeds'));
        $status = $this->sync()->status();
        $this->assertSame(MetaSyncStatus::NO_FEED, $status->state);
        $this->assertStringContainsString('Meta no respondió', (string) $status->message);
    }

    public function test_the_queued_job_finishes_the_provisioning_when_meta_answers_and_never_queues_the_token(): void
    {
        $fake = FakeMetaGraph::install();
        $this->connect(FakeMetaGraph::CATALOG_ID);
        $this->app->make(ConnectorOperations::class)->activate($this->admin, 'meta');
        $job = new SyncMetaCatalogFeed(SyncMetaCatalogFeed::PROVISION);
        $this->assertStringNotContainsString(FakeMetaGraph::TOKEN, serialize($job));

        $job->handle($this->sync());

        $this->assertSame(FakeMetaGraph::FEED_ID, $this->store()->get()->productFeedId);
        $this->assertCount(1, $this->callsTo($fake, 'POST', FakeMetaGraph::FEED_ID.'/uploads'));
        $this->assertNull($this->store()->get()->lastError);
    }

    public function test_the_job_gives_up_quietly_on_the_sync_driver_when_meta_stays_down(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Service unavailable', 'code' => 2, 'is_transient' => true]], 503)]);
        $this->connect(FakeMetaGraph::CATALOG_ID);
        $this->app->make(ConnectorOperations::class)->activate($this->admin, 'meta');

        SyncMetaCatalogFeed::dispatch(SyncMetaCatalogFeed::UPLOAD);

        $this->assertStringContainsString('Meta no respondió', (string) $this->store()->get()->lastError);
        $this->assertFalse($this->store()->get()->needsReconnect());
    }

    public function test_regenerating_the_feed_token_updates_the_schedule_url_in_meta(): void
    {
        $fake = $this->provisioned();
        $old = $this->feedUrl();

        $this->app->make(ConnectorOperations::class)->rotateToken($this->admin, 'meta');

        $new = $this->feedUrl();
        $this->assertNotSame($old, $new);
        $updates = $this->callsTo($fake, 'POST', FakeMetaGraph::FEED_ID);
        $this->assertCount(1, $updates);
        $this->assertSame(['interval' => 'HOURLY', 'url' => $new], $this->schedule($updates[0]));
    }

    public function test_regenerating_in_manual_mode_calls_nothing_and_a_meta_failure_never_undoes_the_rotation(): void
    {
        $fake = FakeMetaGraph::install();
        $operations = $this->app->make(ConnectorOperations::class);
        $operations->activate($this->admin, 'meta');
        $operations->rotateToken($this->admin, 'meta');
        $this->assertSame([], $fake->calls(), 'A v1 (manual) site never calls Meta.');

        $this->provisioned($fake);
        $fake->removeApp();
        $token = $operations->rotateToken($this->admin, 'meta');
        $this->assertSame($token, Connector::query()->where('key', 'meta')->firstOrFail()->plainToken());
        $this->assertTrue($this->store()->get()->needsReconnect());
    }

    public function test_teardown_deletes_only_the_feed_and_forgets_the_connection_even_if_meta_fails(): void
    {
        $fake = $this->provisioned();

        $this->sync()->teardown(true, $this->admin);

        $this->assertCount(1, $this->callsTo($fake, 'DELETE', FakeMetaGraph::FEED_ID));
        $this->assertSame([], $this->callsTo($fake, 'DELETE', FakeMetaGraph::CATALOG_ID));
        $this->assertTrue($this->store()->get()->isManual());
        $this->assertNull($this->store()->accessToken());

        $fake = $this->provisioned();
        $fake->removeApp();
        $this->sync()->teardown(true, $this->admin);
        $this->assertTrue($this->store()->get()->isManual(), 'A Meta error never stops the disconnection.');

        $fake = $this->provisioned();
        $this->sync()->teardown(false, $this->admin);
        $this->assertSame([], $this->callsTo($fake, 'DELETE', FakeMetaGraph::FEED_ID));
        $this->assertTrue(Connector::query()->where('key', 'meta')->firstOrFail()->enabled, 'The manual feed (v1) keeps working.');
    }

    public function test_status_reads_the_last_upload_and_is_cached(): void
    {
        $fake = $this->provisioned();
        $before = count($fake->calls());

        $status = $this->sync()->status();

        $this->assertSame(MetaSyncStatus::SYNCED, $status->state);
        $this->assertSame(3, $status->persistedItems);
        $this->assertSame(3, $status->detectedItems);
        $this->assertSame(3, $status->productCount);
        $this->assertSame(FakeMetaGraph::UPLOAD_ID, $status->uploadId);
        $this->assertSame('2026-11-24T22:09:23+00:00', $status->uploadFinishedAt?->format(DATE_ATOM));
        $this->assertSame([], $status->issues);
        $reads = count($fake->calls()) - $before;
        $this->sync()->status();
        $this->assertSame($reads, count($fake->calls()) - $before, 'The second read comes from the cache.');
        $this->assertStringNotContainsString(FakeMetaGraph::TOKEN, json_encode($status->toArray()));
    }

    public function test_status_lists_the_upload_errors_of_meta(): void
    {
        Http::fake(['graph.facebook.com/*/'.FakeMetaGraph::FEED_ID.'?*' => fn ($request) => $request->method() === 'GET'
            ? Http::response(['id' => FakeMetaGraph::FEED_ID, 'product_count' => 3, 'latest_upload' => ['id' => FakeMetaGraph::FAILED_UPLOAD_ID]])
            : null]);
        $this->provisioned();

        $status = $this->sync()->status();

        $this->assertSame(MetaSyncStatus::WITH_ERRORS, $status->state);
        $this->assertCount(1, $status->issues);
        $this->assertSame('feed_file_not_found', $status->issues[0]->type);
        $this->assertTrue($status->issues[0]->isError());
        $this->assertSame('No se ha podido acceder al archivo de fuente de datos', $status->issues[0]->summary);
    }

    public function test_a_feed_deleted_in_commerce_manager_is_created_again_with_sync_now(): void
    {
        $fake = FakeMetaGraph::install();
        $this->connect();
        $this->store()->save($this->store()->get()->withCatalog(FakeMetaGraph::CATALOG_ID, 'Ruweb spike v2 commerce')->withProductFeed('2403704960370610'));
        $this->app->make(ConnectorOperations::class)->activate($this->admin, 'meta');

        $status = $this->sync()->status();
        $this->assertSame(MetaSyncStatus::NO_FEED, $status->state, 'The deleted feed answers 100/33 while the catalog answers.');
        $this->assertNull($this->store()->get()->productFeedId);
        $this->assertFalse($this->store()->get()->needsReconnect());

        $this->sync()->syncNow($this->admin);
        $this->assertSame(FakeMetaGraph::FEED_ID, $this->store()->get()->productFeedId);
        $this->assertCount(1, $this->callsTo($fake, 'POST', FakeMetaGraph::CATALOG_ID.'/product_feeds'));
    }

    public function test_item_problems_link_to_the_product_by_retailer_id(): void
    {
        $this->provisioned();
        $product = Product::create(['title' => 'Mate imperial', 'slug' => 'mate-imperial', 'locale' => 'es', 'status' => 'published', 'sku' => 'SPIKE-B']);
        $other = Product::create(['title' => 'Bombilla', 'slug' => 'bombilla', 'locale' => 'es', 'status' => 'published']);
        $this->assertTrue($product->is($this->sync()->productForRetailerId('SPIKE-B')));
        $this->assertTrue($other->is($this->sync()->productForRetailerId('p'.$other->id)));
        $this->assertNull($this->sync()->productForRetailerId('v999'));

        $this->sync()->recordItemIssues(new MetaBatchStatus('h', MetaBatchStatus::FINISHED, 0, [], [
            new MetaBatchIssue('SPIKE-B', 3, 'Valores no válidos: Para mostrarse en tu tienda y anuncios, los valores deben ser correctos.'),
            new MetaBatchIssue('BORRADO-1', 4, 'Valores no válidos'),
        ]));
        $status = $this->sync()->status();

        $this->assertSame(MetaSyncStatus::SYNCED, $status->state, 'Warnings do not turn the sync red.');
        $this->assertCount(2, $status->issues);
        $this->assertSame('Mate imperial', $status->issues[0]->productTitle);
        $this->assertSame(route('admin.products.edit', $product, false), $status->issues[0]->editUrl);
        $this->assertNull($status->issues[1]->editUrl);

        $this->actingAs($this->admin)->get(route('admin.connectors.show', ['connector' => 'meta']))
            ->assertOk()
            ->assertSee('Sincronizado con Meta')
            ->assertSee('Mate imperial')
            ->assertSee(route('admin.products.edit', $product, false), false)
            ->assertSee('No encontramos ese producto en Ruweb');
    }

    public function test_panel_shows_the_sync_and_sync_now_is_throttled_and_admin_only(): void
    {
        $fake = $this->provisioned();
        $show = route('admin.connectors.show', ['connector' => 'meta']);

        $html = $this->actingAs($this->admin)->get($show)->assertOk()
            ->assertSee('Sincronización con Meta')
            ->assertSee('Sincronizado con Meta')
            ->assertSee('3 de 3')
            ->assertSee('Sincronizar ahora')
            ->assertSee('business_id='.FakeMetaGraph::BUSINESS_ID, false)
            ->getContent();
        $this->assertStringNotContainsString(FakeMetaGraph::TOKEN, (string) $html);

        $uploads = count($this->callsTo($fake, 'POST', FakeMetaGraph::FEED_ID.'/uploads'));
        $this->post(route('admin.connectors.meta.sync'))->assertRedirect($show.'#meta-sync')->assertSessionHas('success');
        $this->assertCount($uploads + 1, $this->callsTo($fake, 'POST', FakeMetaGraph::FEED_ID.'/uploads'));
        $this->post(route('admin.connectors.meta.sync'))->assertRedirect($show.'#meta-sync')->assertSessionHasErrors('meta_sync');
        $this->assertCount($uploads + 1, $this->callsTo($fake, 'POST', FakeMetaGraph::FEED_ID.'/uploads'), 'Cooldown: no second upload.');

        $this->actingAs($this->editor)->post(route('admin.connectors.meta.sync'))->assertForbidden();
    }

    public function test_manual_sites_see_no_sync_section_and_status_calls_nothing(): void
    {
        $fake = FakeMetaGraph::install();
        $this->app->make(ConnectorOperations::class)->activate($this->admin, 'meta');

        $this->assertSame(MetaSyncStatus::NOT_CONNECTED, $this->sync()->status()->state);
        $this->actingAs($this->admin)->get(route('admin.connectors.show', ['connector' => 'meta']))->assertOk()->assertDontSee('id="meta-sync"', false);
        $this->assertSame([], $fake->calls());
        $this->post(route('admin.connectors.meta.sync'))->assertSessionHasErrors('meta_sync');
    }
}
