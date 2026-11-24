<?php

namespace Tests\Feature;

use App\Connectors\ConnectorRegistry;
use App\Connectors\ConnectorStatus;
use App\Connectors\Meta\Connect\MetaConnection;
use App\Connectors\Meta\Connect\MetaConnectionStore;
use App\Connectors\Meta\MetaFeedWriter;
use App\Connectors\Sources\ShopProductSource;
use App\Jobs\CheckMetaItemsBatch;
use App\Jobs\PublishMetaItems;
use App\Models\Connector;
use App\Models\Locale;
use App\Models\Media;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Taxonomy;
use App\Models\User;
use App\Services\Connectors\ConnectorOperations;
use App\Services\Connectors\Meta\MetaCatalogSync;
use App\Services\Connectors\Meta\MetaItemsPublisher;
use App\Services\Connectors\Meta\MetaSyncIssue;
use App\Services\Shop\PaymentOperations;
use App\Support\ShopModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;
use LogicException;
use SimpleXMLElement;
use Tests\Support\CheckoutFixtures;
use Tests\Support\FakeMetaGraph;
use Tests\TestCase;

/**
 * Meta v2 track 3a: catalog changes published at once with items_batch (MetaItemsPublisher, PublishMetaItems,
 * CheckMetaItemsBatch, MetaCatalogChanges) against FakeMetaGraph, which answers what Graph v27 answered in the spike
 * (§3.3: an invalid UPDATE comes back in `warnings`). No request reaches Meta.
 */
class MetaItemsBatchTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://mitienda.com.py';

    private const ITEMS_BATCH = FakeMetaGraph::CATALOG_ID.'/items_batch';

    private const BATCH_STATUS = FakeMetaGraph::CATALOG_ID.'/check_batch_request_status';

    private User $admin;

    /** @var array<int, true> jobs already run by runJobs() */
    private array $ran = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => self::ORIGIN, 'cms.shop.currency' => 'PYG']);
        Locale::query()->firstOrCreate(['code' => 'es'], ['name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
        Setting::updateOrCreate(['key' => 'site_name'], ['value' => 'Mates del Sur']);
        Setting::clearCache();
        $this->useShop('catalog');
        Storage::fake('public');
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_several_price_changes_go_out_as_one_batch_after_the_debounce(): void
    {
        $mate = $this->product(['title' => 'Mate imperial', 'slug' => 'mate-imperial', 'sku' => 'MATE', 'price_amount' => 100000]);
        $fake = $this->connectedWithQueue();
        $this->assertSame([], $this->pushed(), 'Changes before connecting are not remembered (the feed carries them).');
        $this->assertSame(['products' => [], 'gone' => []], app(MetaItemsPublisher::class)->pending());

        foreach ([110000, 120000, 130000] as $price) {
            $mate->update(['price_amount' => $price]);
        }

        $pushed = $this->pushed(PublishMetaItems::class);
        $this->assertCount(1, $pushed, 'One job per debounce window, whatever the number of changes.');
        $this->assertEqualsWithDelta(now()->addSeconds(MetaItemsPublisher::DEBOUNCE_SECONDS)->getTimestamp(), $pushed[0]->delay->getTimestamp(), 2);
        $this->assertSame([], $this->callsTo($fake, 'POST', self::ITEMS_BATCH), 'Nothing is sent before the debounce ends.');
        $this->assertSame([$mate->id], app(MetaItemsPublisher::class)->pending()['products']);

        $this->runJobs();

        $batches = $this->callsTo($fake, 'POST', self::ITEMS_BATCH);
        $this->assertCount(1, $batches);
        $this->assertSame('PRODUCT_ITEM', $batches[0]['form']['item_type']);
        $requests = $this->requests($batches[0]);
        $this->assertCount(1, $requests);
        $this->assertSame('UPDATE', $requests[0]['method']);
        $this->assertSame('MATE', $requests[0]['data']['id']);
        $this->assertSame('130000 PYG', $requests[0]['data']['price'], 'The last price, read when the batch goes out.');
        $this->assertCount(1, $this->callsTo($fake, 'GET', self::BATCH_STATUS), 'Then check_batch_request_status.');
        $this->assertSame(['products' => [], 'gone' => []], app(MetaItemsPublisher::class)->pending());

        foreach ([...$this->pushed(PublishMetaItems::class), ...$this->pushed(CheckMetaItemsBatch::class)] as $job) {
            $this->assertStringNotContainsString(FakeMetaGraph::TOKEN, serialize($job), 'The token never sits in the queue.');
        }
        foreach ($fake->calls() as $call) {
            $this->assertStringNotContainsString(FakeMetaGraph::TOKEN, $call['url']);
        }
    }

    public function test_on_the_sync_driver_the_changes_of_a_request_go_out_once_after_the_response(): void
    {
        $fake = $this->connected();
        $mate = $this->product(['title' => 'Mate', 'slug' => 'mate', 'sku' => 'MATE', 'price_amount' => 100000]);
        $bombilla = $this->product(['title' => 'Bombilla', 'slug' => 'bombilla', 'sku' => 'BOM', 'price_amount' => 30000]);
        $this->app->terminate();
        $before = count($this->callsTo($fake, 'POST', self::ITEMS_BATCH));

        $mate->update(['price_amount' => 90000]);
        $bombilla->update(['price_amount' => 25000]);
        $mate->update(['price_amount' => 85000]);
        $this->assertCount($before, $this->callsTo($fake, 'POST', self::ITEMS_BATCH), 'Not during the request.');

        $this->app->terminate();

        $batches = $this->callsTo($fake, 'POST', self::ITEMS_BATCH);
        $this->assertCount($before + 1, $batches);
        $prices = [];
        foreach ($this->requests(end($batches)) as $request) {
            $prices[$request['data']['id']] = $request['data']['price'];
        }
        $this->assertSame(['MATE' => '85000 PYG', 'BOM' => '25000 PYG'], $prices);
    }

    public function test_the_json_of_items_batch_matches_the_feed_xml_field_by_field(): void
    {
        $fake = $this->connectedWithQueue();
        $ropa = Taxonomy::create(['type' => 'category', 'name' => 'Ropa', 'slug' => 'ropa', 'locale' => 'es']);
        $remeras = Taxonomy::create(['type' => 'category', 'name' => 'Remeras', 'slug' => 'remeras', 'locale' => 'es', 'parent_id' => $ropa->id]);
        $termo = $this->product(['title' => 'Termo', 'slug' => 'termo', 'sku' => 'TER', 'price_amount' => 200000, 'compare_at_price_amount' => 250000,
            'description' => '<p>Acero <strong>inoxidable</strong> &amp; tapa.</p>', 'track_stock' => true, 'stock' => 0]);
        $termo->attachMedia($this->jpeg('termo-2.jpg'), 'gallery', 1);
        $remera = $this->product(['title' => 'Remera', 'slug' => 'remera', 'sku' => 'REM', 'has_variants' => true, 'price_amount' => null], 'remera.jpg');
        $remera->taxonomies()->attach($remeras->id);
        $remera->variants()->create(['sku' => 'REM-R', 'name' => 'Roja M', 'attributes_json' => ['color' => 'Rojo', 'talle' => 'M', 'Estilo' => 'Cuello V'], 'price_amount' => 90000, 'order' => 0]);
        $remera->variants()->create(['sku' => '', 'name' => 'Azul L', 'attributes_json' => ['color' => 'Azul', 'gender' => 'Unisex'], 'price_amount' => 95000, 'order' => 1]);
        $this->runJobs();

        $json = [];
        foreach ($this->callsTo($fake, 'POST', self::ITEMS_BATCH) as $call) {
            foreach ($this->requests($call) as $request) {
                $this->assertSame('UPDATE', $request['method']);
                $json[$request['data']['id']] = $request['data'];
            }
        }
        $xml = $this->feedItems();

        $this->assertEqualsCanonicalizing(array_keys($xml), array_keys($json));
        $this->assertCount(3, $json);
        foreach ($xml as $id => $fields) {
            $this->assertSame($fields, $json[$id], 'Same fields, values and order for '.$id.'.');
        }
        $this->assertSame('250000 PYG', $json['TER']['price']);
        $this->assertSame('200000 PYG', $json['TER']['sale_price']);
        $this->assertSame('out of stock', $json['TER']['availability']);
        $this->assertSame('REM', $json['REM-R']['item_group_id']);
        $this->assertSame('Ropa > Remeras', $json['REM-R']['product_type']);
        $this->assertSame('Estilo:Cuello V', $json['REM-R']['additional_variant_attribute']);
    }

    public function test_a_batch_reads_only_the_changed_products_never_the_whole_catalog(): void
    {
        $fake = $this->connected();
        $mate = $this->product(['title' => 'Mate', 'slug' => 'mate', 'sku' => 'MATE', 'price_amount' => 100000]);
        $this->product(['title' => 'Bombilla', 'slug' => 'bombilla', 'sku' => 'BOM', 'price_amount' => 30000]);
        $this->app->terminate();
        $this->app->bind(ShopProductSource::class, fn () => new class(app(ShopModule::class)) extends ShopProductSource
        {
            public function items(): LazyCollection
            {
                throw new LogicException('A change batch must not walk the whole catalog.');
            }
        });

        $mate->update(['price_amount' => 90000]);
        $this->app->terminate();

        $this->assertSame([['UPDATE', 'MATE']], $this->lastBatch($fake));
        $this->assertSame('90000 PYG', $this->requests($this->lastCall($fake))[0]['data']['price']);
    }

    public function test_items_for_keeps_the_rules_of_the_whole_source(): void
    {
        $mate = $this->product(['title' => 'Mate', 'slug' => 'mate', 'sku' => 'MATE', 'price_amount' => 100000]);
        $remera = $this->product(['title' => 'Remera', 'slug' => 'remera', 'sku' => 'REM', 'has_variants' => true, 'price_amount' => 90000]);
        $remera->variants()->create(['sku' => 'REM-R', 'name' => 'Roja', 'attributes_json' => ['color' => 'Rojo'], 'price_amount' => 90000, 'order' => 0]);
        $remera->variants()->create(['sku' => null, 'name' => 'Azul', 'attributes_json' => ['color' => 'Azul'], 'price_amount' => 95000, 'order' => 1]);
        $draft = $this->product(['title' => 'Borrador', 'slug' => 'borrador', 'sku' => 'DRAFT', 'price_amount' => 1000]);
        $draft->update(['status' => 'draft']);
        $other = $this->product(['title' => 'Otro', 'slug' => 'otro', 'sku' => 'OTRO', 'price_amount' => 1000]);
        $source = app(ShopProductSource::class);

        $wanted = [(string) $remera->id, $mate->id, $draft->id, $mate->id, 999999, 0];
        $expected = $source->items()->filter(fn ($item) => in_array($item->groupId ?? $item->id, ['MATE', 'REM'], true))->values()->all();

        $this->assertEquals($expected, $source->itemsFor($wanted)->values()->all());
        $this->assertSame(['MATE', 'REM-R', 'v'.$remera->variants()->where('name', 'Azul')->value('id')], $source->itemsFor($wanted)->map(fn ($item) => $item->id)->all());
        $this->assertSame([], $source->itemsFor([])->all());
        $this->assertNotContains('OTRO', $source->itemsFor([$mate->id])->map(fn ($item) => $item->id)->all());
        $this->assertSame(['OTRO'], $source->itemsFor([$other->id])->map(fn ($item) => $item->id)->all());
    }

    public function test_unpublishing_or_losing_the_price_sends_delete_and_publishing_again_sends_update(): void
    {
        $fake = $this->connectedWithQueue();
        $mate = $this->product(['title' => 'Mate', 'slug' => 'mate', 'sku' => 'MATE', 'price_amount' => 100000]);
        $bombilla = $this->product(['title' => 'Bombilla', 'slug' => 'bombilla', 'price_amount' => 30000]);
        $this->runJobs();

        $mate->update(['status' => 'draft']);
        $bombilla->update(['price_amount' => null]);
        $this->runJobs();
        $this->assertEqualsCanonicalizing([['DELETE', 'MATE'], ['DELETE', 'p'.$bombilla->id]], $this->lastBatch($fake), 'Unpublished, and skipped by check() (no price).');

        $mate->update(['status' => 'published']);
        $this->runJobs();
        $this->assertSame([['UPDATE', 'MATE']], $this->lastBatch($fake));

        $mate->update(['sku' => 'MATE-2']);
        $this->runJobs();
        $this->assertEqualsCanonicalizing([['UPDATE', 'MATE-2'], ['DELETE', 'MATE']], $this->lastBatch($fake), 'A new SKU deletes the old retailer id.');

        $mate->delete();
        $this->runJobs();
        $this->assertSame([['DELETE', 'MATE-2']], $this->lastBatch($fake));
    }

    public function test_variants_go_out_with_their_own_ids_and_a_deleted_variant_is_deleted(): void
    {
        $fake = $this->connectedWithQueue();
        $remera = $this->product(['title' => 'Remera', 'slug' => 'remera', 'sku' => 'REM', 'has_variants' => true, 'price_amount' => null], 'remera.jpg');
        $red = $remera->variants()->create(['sku' => 'REM-R', 'name' => 'Roja', 'attributes_json' => ['color' => 'Rojo'], 'price_amount' => 90000, 'order' => 0]);
        $blue = $remera->variants()->create(['sku' => null, 'name' => 'Azul', 'attributes_json' => ['color' => 'Azul'], 'price_amount' => 90000, 'order' => 1]);
        $this->runJobs();

        $red->update(['price_amount' => 80000]);
        $this->runJobs();
        $requests = $this->requests($this->lastCall($fake));
        $this->assertEqualsCanonicalizing(['REM-R', 'v'.$blue->id], array_map(fn (array $request): string => $request['data']['id'], $requests), 'Variant ids, never the product id.');
        foreach ($requests as $request) {
            $this->assertSame('UPDATE', $request['method']);
            $this->assertSame('REM', $request['data']['item_group_id']);
        }
        $this->assertNotContains('REM', array_column(array_column($requests, 'data'), 'id'));

        $blue->delete();
        $this->runJobs();
        $this->assertEqualsCanonicalizing([['UPDATE', 'REM-R'], ['DELETE', 'v'.$blue->id]], $this->lastBatch($fake));

        $red->update(['sku' => 'REM-ROJA']);
        $this->runJobs();
        $this->assertEqualsCanonicalizing([['UPDATE', 'REM-ROJA'], ['DELETE', 'REM-R']], $this->lastBatch($fake));
    }

    public function test_stock_taken_by_a_paid_order_is_published(): void
    {
        $this->useCheckout();
        $fake = $this->connectedWithQueue();
        $mate = $this->product(['title' => 'Mate', 'slug' => 'mate', 'sku' => 'MATE', 'price_amount' => 100000, 'track_stock' => true, 'stock' => 1]);
        $this->runJobs();
        $order = CheckoutFixtures::order([[$mate, 1]]);

        app(PaymentOperations::class)->confirm($this->admin, $order->payments->first());
        $this->runJobs();

        $requests = $this->requests($this->lastCall($fake));
        $this->assertSame('MATE', $requests[0]['data']['id']);
        $this->assertSame('out of stock', $requests[0]['data']['availability'], 'The sale left it without stock (StockLedger writes without model events).');
    }

    public function test_meta_warnings_show_in_the_panel_per_product_until_it_is_sent_again(): void
    {
        $fake = $this->connectedWithQueue();
        $fake->batchOutcome(FakeMetaGraph::BATCH_WARNINGS);
        $mate = $this->product(['title' => 'Mate imperial', 'slug' => 'mate-imperial', 'sku' => 'SPIKE-B', 'price_amount' => 100000]);
        $this->runJobs();

        $status = app(MetaCatalogSync::class)->status();
        $this->assertCount(1, $status->issues);
        $this->assertSame(MetaSyncIssue::SEVERITY_WARNING, $status->issues[0]->severity, 'An invalid value comes back as a warning, not an error.');
        $this->assertSame('SPIKE-B', $status->issues[0]->retailerId);
        $this->actingAs($this->admin)->get(route('admin.connectors.show', ['connector' => 'meta']))
            ->assertOk()
            ->assertSee('Valores no válidos')
            ->assertSee('Mate imperial')
            ->assertSee(route('admin.products.edit', $mate, false), false);

        // Another product goes out cleanly: the warning of SPIKE-B stays (it was not sent again).
        $fake->batchOutcome(FakeMetaGraph::BATCH_OK);
        $this->product(['title' => 'Bombilla', 'slug' => 'bombilla', 'sku' => 'BOM', 'price_amount' => 30000]);
        $this->runJobs();
        $this->assertCount(1, app(MetaCatalogSync::class)->status()->issues);

        $mate->update(['price_amount' => 95000]);
        $this->runJobs();
        $this->assertSame([], app(MetaCatalogSync::class)->status()->issues, 'Sent again without problems: the warning goes away.');
    }

    public function test_errors_of_meta_are_shown_and_problems_of_deletes_are_not(): void
    {
        $fake = $this->connectedWithQueue();
        $fake->batchOutcome(FakeMetaGraph::BATCH_ERRORS);
        $this->product(['title' => 'Spike A', 'slug' => 'spike-a', 'sku' => 'SPIKE-A', 'price_amount' => 100000]);
        $gone = $this->product(['title' => 'Spike B', 'slug' => 'spike-b', 'sku' => 'SPIKE-B', 'price_amount' => 100000]);
        $gone->update(['status' => 'draft']);
        $this->runJobs();

        $issues = app(MetaCatalogSync::class)->status()->issues;
        $ids = array_map(fn (MetaSyncIssue $issue): ?string => $issue->retailerId, $issues);
        $this->assertContains('SPIKE-A', $ids);
        $this->assertNotContains('SPIKE-B', $ids, 'Deleting is best effort: a problem with a DELETE is not the client\'s to fix.');
        $this->assertSame(MetaSyncIssue::SEVERITY_ERROR, $issues[0]->severity);
    }

    public function test_a_removed_app_asks_to_reconnect_and_stops_publishing(): void
    {
        $fake = $this->connectedWithQueue();
        $mate = $this->product(['title' => 'Mate', 'slug' => 'mate', 'sku' => 'MATE', 'price_amount' => 100000]);
        $fake->removeApp();

        $this->runJobs();

        $connection = app(MetaConnectionStore::class)->get();
        $this->assertTrue($connection->needsReconnect());
        $this->assertSame(MetaCatalogSync::text('meta_sync.error_revoked'), $connection->lastError);
        $this->assertNull(app(MetaConnectionStore::class)->accessToken(), 'The revoked token is dropped.');
        $this->assertSame(ConnectorStatus::NeedsReconnect, app(ConnectorRegistry::class)->status('meta'));
        $this->assertSame([], $this->pushed(CheckMetaItemsBatch::class));
        $this->assertSame(['products' => [], 'gone' => []], app(MetaItemsPublisher::class)->pending());

        $calls = count($fake->calls());
        $jobs = count($this->pushed());
        $mate->update(['price_amount' => 90000]);
        $this->assertCount($jobs, $this->pushed(), 'Nothing is queued while it needs reconnecting.');
        $this->assertCount($calls, $fake->calls());
    }

    public function test_an_unshared_catalog_asks_to_reconnect_saying_which(): void
    {
        $fake = $this->connectedWithQueue();
        $this->product(['title' => 'Mate', 'slug' => 'mate', 'sku' => 'MATE', 'price_amount' => 100000]);
        $fake->unshareCatalog();

        $this->runJobs();

        $connection = app(MetaConnectionStore::class)->get();
        $this->assertTrue($connection->needsReconnect());
        $this->assertSame(MetaCatalogSync::text('meta_sync.error_catalog_unavailable', ['catalog' => 'Ruweb spike v2 commerce']), $connection->lastError);
    }

    public function test_manual_mode_remembers_nothing_and_calls_nothing(): void
    {
        $fake = FakeMetaGraph::install();
        config(['queue.default' => 'database']);
        Queue::fake();
        app(ConnectorOperations::class)->activate($this->admin, 'meta');

        $mate = $this->product(['title' => 'Mate', 'slug' => 'mate', 'sku' => 'MATE', 'price_amount' => 100000]);
        $mate->update(['price_amount' => 90000]);
        $mate->update(['status' => 'draft']);

        Queue::assertNothingPushed();
        $this->assertSame([], $fake->calls());
        $this->assertSame(['products' => [], 'gone' => []], app(MetaItemsPublisher::class)->pending());
        $this->assertNull(app(MetaItemsPublisher::class)->publishPending());
        $this->assertSame([], $fake->calls());
    }

    public function test_big_batches_are_split(): void
    {
        config(['cms.meta.items_batch_size' => 2]);
        $fake = $this->connectedWithQueue();
        foreach (['A', 'B', 'C'] as $sku) {
            $this->product(['title' => 'Producto '.$sku, 'slug' => 'producto-'.strtolower($sku), 'sku' => $sku, 'price_amount' => 10000]);
        }

        $this->runJobs();

        $batches = $this->callsTo($fake, 'POST', self::ITEMS_BATCH);
        $this->assertSame([2, 1], array_map(fn (array $call): int => count($this->requests($call)), $batches));
        $this->assertCount(2, $this->callsTo($fake, 'GET', self::BATCH_STATUS), 'Every handle is checked.');
        config(['cms.meta.items_batch_size' => 5000]);
        $this->assertSame(MetaItemsPublisher::MAX_ITEMS_PER_BATCH, (fn () => $this->chunkSize())->call(app(MetaItemsPublisher::class)), 'Never more than 3000 per call.');
    }

    public function test_rate_limits_keep_the_changes_and_back_off(): void
    {
        $limited = true;
        $usage = 0;
        Http::fake(['graph.facebook.com/*/'.self::ITEMS_BATCH.'*' => function () use (&$limited, &$usage) {
            if ($limited) {
                return Http::response(['error' => ['message' => '(#80014) There have been too many calls for the batch uploads to this catalog account.', 'type' => 'OAuthException', 'code' => 80014, 'fbtrace_id' => 'test']], 400);
            }
            if ($usage > 0) {
                return Http::response(['handles' => ['h-usage']], 200, ['x-business-use-case-usage' => json_encode([FakeMetaGraph::CATALOG_ID => [['type' => 'catalog_batch_catalog_level_per_minute', 'call_count' => $usage, 'total_cputime' => 1, 'total_time' => 1, 'estimated_time_to_regain_access' => 0]]])]);
            }

            return null;
        }]);
        $fake = $this->connectedWithQueue();
        $mate = $this->product(['title' => 'Mate', 'slug' => 'mate', 'sku' => 'MATE', 'price_amount' => 100000]);
        $publisher = app(MetaItemsPublisher::class);

        $this->assertSame(MetaItemsPublisher::BACKOFF[0], $publisher->publishPending(1), '80014: wait and retry.');
        $this->assertSame([$mate->id], $publisher->pending()['products'], 'The change is kept for the retry.');
        $this->assertFalse(app(MetaConnectionStore::class)->get()->needsReconnect());

        $limited = false;
        $usage = 90;
        $this->assertNull($publisher->publishPending(2), 'Sent; Meta says 90 % of the batch limit is used.');
        $this->assertSame([], $publisher->pending()['products']);

        $mate->update(['price_amount' => 90000]);
        $calls = count($this->callsTo($fake, 'POST', self::ITEMS_BATCH));
        $wait = $publisher->publishPending();
        $this->assertGreaterThanOrEqual(MetaItemsPublisher::MIN_PAUSE_SECONDS - 1, $wait, 'MetaUsage::shouldSlowDown(): the next batch waits.');
        $this->assertCount($calls, $this->callsTo($fake, 'POST', self::ITEMS_BATCH));
        $this->assertSame([$mate->id], $publisher->pending()['products']);

        $this->travel(MetaItemsPublisher::MIN_PAUSE_SECONDS + 1)->seconds();
        $usage = 0;
        $this->assertNull($publisher->publishPending());
        $this->assertSame([], $publisher->pending()['products']);
    }

    public function test_a_job_that_runs_out_of_retries_records_that_meta_did_not_answer(): void
    {
        Http::fake(['graph.facebook.com/*/'.self::ITEMS_BATCH.'*' => Http::response(['error' => ['message' => 'Service unavailable', 'code' => 2, 'is_transient' => true]], 503)]);
        $this->connectedWithQueue();
        $mate = $this->product(['title' => 'Mate', 'slug' => 'mate', 'sku' => 'MATE', 'price_amount' => 100000]);

        $this->runJobs();

        $this->assertSame(MetaCatalogSync::text('meta_sync.error_meta_unavailable'), app(MetaConnectionStore::class)->get()->lastError);
        $this->assertFalse(app(MetaConnectionStore::class)->get()->needsReconnect());
        $this->assertSame([$mate->id], app(MetaItemsPublisher::class)->pending()['products'], 'Kept for the next change; the hourly feed carries it anyway.');
    }

    /** Connected with the spike's commerce catalog and its feed provisioned (2c), on the sync queue driver. */
    private function connected(): FakeMetaGraph
    {
        $fake = FakeMetaGraph::install();
        $store = app(MetaConnectionStore::class);
        $store->save(MetaConnection::connected(FakeMetaGraph::BUSINESS_ID, 'v27.0', ['catalog_management'], $this->admin->id), FakeMetaGraph::TOKEN);
        app(MetaCatalogSync::class)->provision(FakeMetaGraph::CATALOG_ID, $this->admin);

        return $fake;
    }

    /** connected() on a real (faked) queue: jobs are kept with their delay and run by runJobs(). */
    private function connectedWithQueue(): FakeMetaGraph
    {
        $fake = $this->connected();
        config(['queue.default' => 'database']);
        Queue::fake();

        return $fake;
    }

    /** Run the queued PublishMetaItems, then the CheckMetaItemsBatch they queued, until nothing new is pushed. */
    private function runJobs(): void
    {
        do {
            $ran = false;
            foreach ([PublishMetaItems::class, CheckMetaItemsBatch::class] as $class) {
                foreach ($this->pushed($class) as $job) {
                    if (isset($this->ran[spl_object_id($job)])) {
                        continue;
                    }
                    $this->ran[spl_object_id($job)] = true;
                    $job->handle(app(MetaItemsPublisher::class));
                    $ran = true;
                }
            }
        } while ($ran);
    }

    /** @return list<object> */
    private function pushed(?string $class = null): array
    {
        $jobs = [];
        foreach (Queue::pushedJobs() as $name => $entries) {
            if ($class === null || $name === $class) {
                foreach ($entries as $entry) {
                    $jobs[] = $entry['job'];
                }
            }
        }

        return $jobs;
    }

    /** @return list<array<string, mixed>> */
    private function callsTo(FakeMetaGraph $fake, string $method, string $path): array
    {
        return array_values(array_filter($fake->calls(), fn (array $call): bool => $call['method'] === $method && $call['path'] === $path));
    }

    /** @return array<string, mixed> */
    private function lastCall(FakeMetaGraph $fake): array
    {
        $calls = $this->callsTo($fake, 'POST', self::ITEMS_BATCH);
        $this->assertNotSame([], $calls, 'An items_batch was sent.');

        return end($calls);
    }

    /** @return list<array{0: string, 1: string}> [method, retailer id] of the last items_batch */
    private function lastBatch(FakeMetaGraph $fake): array
    {
        return array_map(fn (array $request): array => [$request['method'], $request['data']['id']], $this->requests($this->lastCall($fake)));
    }

    /** @return list<array{method: string, data: array<string, string>}> */
    private function requests(array $call): array
    {
        $requests = $call['form']['requests'] ?? [];

        return array_values(is_string($requests) ? (array) json_decode($requests, true) : (array) $requests);
    }

    /** @return array<string, array<string, string>> g: fields per item id of the public feed */
    private function feedItems(): array
    {
        $token = Connector::query()->where('key', 'meta')->firstOrFail()->plainToken();
        $xml = simplexml_load_string($this->get('/feeds/meta/'.$token.'.xml')->assertOk()->streamedContent());
        $this->assertInstanceOf(SimpleXMLElement::class, $xml);
        $items = [];
        foreach ($xml->channel->item as $item) {
            $fields = [];
            foreach ($item->children(MetaFeedWriter::NAMESPACE_G) as $name => $value) {
                $fields[$name] = (string) $value;
            }
            $items[$fields['id']] = $fields;
        }

        return $items;
    }

    /** A published base-language product with a JPEG featured image (what check() needs to let it in). */
    private function product(array $attributes, string $image = 'mate.jpg'): Product
    {
        $product = Product::create($attributes + ['locale' => 'es', 'status' => 'published', 'published_at' => now()->subHour()]);
        $product->attachMedia($this->jpeg($image), 'featured_image');

        return $product;
    }

    private function jpeg(string $filename): int
    {
        return Media::create([
            'filename' => $filename,
            'original_filename' => $filename,
            'path' => 'media/2026/11/'.$filename,
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'size' => 1000,
            'width' => 800,
            'height' => 800,
            'disk' => 'public',
        ])->id;
    }
}
