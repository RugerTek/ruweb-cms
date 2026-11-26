<?php

namespace Tests\Feature;

use App\Connectors\ConnectorRegistry;
use App\Connectors\ConnectorStatus;
use App\Connectors\Meta\Connect\MetaConnection;
use App\Connectors\Meta\Connect\MetaConnectionStore;
use App\Connectors\Meta\Connect\RuwebConnectHandoffReceiver;
use App\Http\Controllers\Admin\MetaConnectController;
use App\Models\Connector;
use App\Models\McpApiKey;
use App\Models\User;
use App\Services\Connectors\ConnectorOperations;
use App\Services\Connectors\Meta\MetaCatalogSync;
use App\Services\Connectors\Meta\MetaCatalogSyncException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Support\FakeMetaGraph;
use Tests\Support\FakeRuwebConnect;
use Tests\TestCase;

/**
 * Meta v2 «Conectar con Facebook» (plan-meta-v2, track 2b, contract v2): registration with the broker, nonce, the
 * broker's signed delivery of the token to /_ruweb/connect/meta/handoff, catalog choice, disconnect, states on the
 * card and the detail, and MCP without the token. The RugerTek Connect broker is FakeRuwebConnect (Http::fake for its
 * endpoints, FakeRuwebConnect::deliver() for its POST to the site) and MetaCatalogSync (track 2c) is replaced by a
 * recording double, so these tests check only what this track decides. No request leaves the test.
 */
class MetaConnectTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private FakeRuwebConnect $broker;

    private FakeMetaGraph $graph;

    /** @var object{provisioned: list<string>, teardowns: list<bool>, failProvision: ?\Throwable, failTeardown: bool} */
    private object $sync;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useShop('catalog');
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Ana Admin']);
        $this->broker = FakeRuwebConnect::install();
        $this->broker->challengeCheck = fn (string $path, string $token): bool => $this->store()->challengeFor(basename($path)) === $token;
        // The sync panel of 2c reads Meta on the detail page: it gets the spike answers too.
        $this->graph = FakeMetaGraph::install();
        Http::preventStrayRequests();
        $this->sync = $this->fakeSync();
        $this->app->instance(MetaCatalogSync::class, $this->sync);
    }

    private function store(): MetaConnectionStore
    {
        return app(MetaConnectionStore::class);
    }

    /** Double of MetaCatalogSync with the fixed signatures of the brief: records provision/teardown, stores what 2c stores. */
    private function fakeSync(): object
    {
        return new class
        {
            /** @var list<string> */
            public array $provisioned = [];

            /** @var list<bool> */
            public array $teardowns = [];

            public ?\Throwable $failProvision = null;

            public bool $failTeardown = false;

            public function provision(string $catalogId, User $actor): MetaConnection
            {
                $this->provisioned[] = $catalogId;
                if ($this->failProvision !== null) {
                    throw $this->failProvision;
                }
                app(ConnectorOperations::class)->activate($actor, 'meta');
                $store = app(MetaConnectionStore::class);
                $names = [FakeMetaGraph::CATALOG_ID => 'Ruweb spike v2 commerce', FakeMetaGraph::OTHER_CATALOG_ID => 'Ruweb prueba feed'];

                return $store->save($store->get()->withCatalog($catalogId, $names[$catalogId] ?? $catalogId)->withProductFeed(FakeMetaGraph::FEED_ID));
            }

            public function teardown(bool $deleteFeed, User $actor): void
            {
                $this->teardowns[] = $deleteFeed;
                if ($this->failTeardown) {
                    throw new RuntimeException('Meta did not answer');
                }
                app(MetaConnectionStore::class)->forget();
            }

            /** Anything else (status() for the sync panel of 2c) goes to the real service. */
            public function __call(string $method, array $arguments): mixed
            {
                return app()->build(MetaCatalogSync::class)->{$method}(...$arguments);
            }
        };
    }

    /** «Conectar con Facebook» pressed: returns the nonce the site sent to the broker. */
    private function startConnect(): string
    {
        $response = $this->actingAs($this->admin)->get(route('admin.connectors.meta.connect'));
        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertTrue(FakeRuwebConnect::validStartUrl($location), 'Signed start URL for the registered site.');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query['nonce'];
    }

    /** The broker delivers the token to the site (it must be accepted), then sends the browser back with the nonce. */
    private function returnFromMeta(array $handoff = [], ?string $nonce = null): TestResponse
    {
        $nonce ??= $this->startConnect();
        $this->broker->deliver($this, $nonce, $handoff)->assertOk()->assertExactJson(['ok' => true]);

        return $this->actingAs($this->admin)->get(route('admin.connectors.meta.connected', ['nonce' => $nonce]));
    }

    private function oneCatalog(string $id = FakeMetaGraph::CATALOG_ID): array
    {
        return ['catalogs' => [['id' => $id, 'name' => 'Ruweb spike v2 commerce', 'vertical' => 'commerce', 'product_count' => 0]]];
    }

    private function detail(): TestResponse
    {
        return $this->actingAs($this->admin)->get(route('admin.connectors.show', ['connector' => 'meta']))->assertOk();
    }

    private function brokerCalls(): array
    {
        return array_map(fn (array $call): string => $call['method'].' '.$call['path'], $this->broker->calls());
    }

    public function test_connect_registers_the_site_once_and_sends_the_admin_to_the_broker_with_a_session_nonce(): void
    {
        $nonce = $this->startConnect();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{16,128}$/', $nonce, 'Nonce format the broker accepts.');
        $this->assertSame(['POST /v1/sites/register', 'POST /v1/sites/register/'.FakeRuwebConnect::REGISTRATION_ID.'/verify'], $this->brokerCalls());
        $this->assertSame(['origin' => 'http://localhost:8000', 'name' => json_decode($this->broker->calls()[0]['body'], true)['name']], json_decode($this->broker->calls()[0]['body'], true));
        $this->assertSame(FakeRuwebConnect::SITE_ID, $this->store()->connectSiteId());
        $this->assertNull($this->store()->registrationId(), 'The challenge is no longer served once verified.');
        $flow = session(MetaConnectController::SESSION_FLOW);
        $this->assertSame([$nonce, $this->admin->id], [$flow['nonce'], $flow['user_id']]);
        $this->assertTrue($this->store()->get()->isManual(), 'Nothing is connected until Meta answers.');

        // Registered: the second click goes straight to the broker with a new nonce.
        $second = $this->startConnect();
        $this->assertNotSame($nonce, $second);
        $this->assertCount(2, $this->broker->calls());
    }

    public function test_connect_without_a_broker_or_with_the_shop_off_explains_and_keeps_the_manual_guide(): void
    {
        config(['cms.meta.connect_url' => null]);
        $this->actingAs($this->admin)->get(route('admin.connectors.meta.connect'))
            ->assertRedirect(route('admin.connectors.show', ['connector' => 'meta']).'#meta-connection')
            ->assertSessionHasErrors(['meta_connect' => __('connectors.meta_connect_unavailable')]);
        $page = $this->detail();
        $page->assertSee('data-meta-connect="disabled"', false)->assertSee(__('connectors.meta_connect_unavailable'));
        $page->assertSee(__('connectors.guide_title'));
        $this->assertSame([], $this->broker->calls());

        config(['cms.meta.connect_url' => FakeRuwebConnect::URL]);
        $this->useShop('off', 'off');
        $this->actingAs($this->admin)->get(route('admin.connectors.meta.connect'))->assertSessionHasErrors('meta_connect');
        $this->assertSame([], $this->broker->calls());
    }

    public function test_a_registration_the_broker_cannot_verify_says_why_and_serves_no_challenge(): void
    {
        $this->broker->challengeCheck = fn (): bool => false;
        $this->actingAs($this->admin)->get(route('admin.connectors.meta.connect'))
            ->assertSessionHasErrors(['meta_connect' => __('connectors.meta_register_unreachable', ['origin' => 'http://localhost:8000'])]);
        $this->assertNull($this->store()->connectSiteId());
        $this->assertNull($this->store()->registrationId());
        $this->assertNull(session(MetaConnectController::SESSION_FLOW));
    }

    public function test_the_detail_offers_connect_with_the_requirement_and_a_confirmation(): void
    {
        $page = $this->detail();
        $page->assertSee('id="meta-connection"', false)->assertSee('data-meta-connection="manual"', false);
        $page->assertSee('data-meta-connect="connect"', false);
        $page->assertSee('action="'.route('admin.connectors.meta.connect').'"', false);
        $page->assertSee('data-confirm="'.e(__('connectors.meta_connect_confirm')).'"', false);
        $page->assertSee(__('connectors.meta_connect_warning_create'))->assertSee(__('connectors.meta_connect_warning_portfolios'));
        $page->assertSee(MetaConnectController::COMMERCE_MANAGER_URL, false);
        $page->assertSee(__('connectors.guide_title'), false);
    }

    public function test_only_administrators_reach_the_connect_routes(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        foreach ([['get', 'admin.connectors.meta.connect'], ['get', 'admin.connectors.meta.connected'], ['get', 'admin.connectors.meta.catalog'], ['post', 'admin.connectors.meta.catalog.select'], ['post', 'admin.connectors.meta.disconnect']] as [$method, $name]) {
            $this->actingAs($editor)->{$method}(route($name))->assertForbidden();
        }
        $this->assertSame([], $this->broker->calls());
    }

    public function test_a_single_commerce_catalog_is_chosen_at_once_and_provisioned(): void
    {
        $response = $this->returnFromMeta($this->oneCatalog());

        $response->assertRedirect(route('admin.connectors.show', ['connector' => 'meta']).'#meta-connection')
            ->assertSessionHas('success', __('connectors.meta_connected', ['catalog' => 'Ruweb spike v2 commerce']));
        $this->assertSame([FakeMetaGraph::CATALOG_ID], $this->sync->provisioned);
        $connection = $this->store()->get();
        $this->assertTrue($connection->canSync());
        $this->assertSame([FakeMetaGraph::BUSINESS_ID, $this->admin->id, substr(FakeMetaGraph::TOKEN, -4)], [$connection->businessId, $connection->connectedBy, $connection->tokenSuffix]);
        $this->assertSame(['catalog_management', 'public_profile'], $connection->grantedScopes);
        $this->assertSame(FakeMetaGraph::TOKEN, $this->store()->accessToken());
        $this->assertTrue(app(ConnectorRegistry::class)->record('meta')->enabled);
        $this->assertSame(ConnectorStatus::Connected, app(ConnectorRegistry::class)->status('meta'));
        $this->assertNull(session(MetaConnectController::SESSION_FLOW), 'The nonce is single use.');
        $this->assertStringNotContainsString('handoff', (string) $response->headers->get('Location'));
    }

    public function test_the_nonce_must_match_belong_to_the_admin_and_be_fresh(): void
    {
        $this->actingAs($this->admin)->get(route('admin.connectors.meta.connected', ['nonce' => 'n0nce-from-somewhere-else']))
            ->assertSessionHasErrors(['meta_connect' => __('connectors.meta_connect_expired')]);

        $nonce = $this->startConnect();
        $this->broker->deliver($this, $nonce, $this->oneCatalog())->assertOk();
        $this->actingAs($this->admin)->get(route('admin.connectors.meta.connected', ['nonce' => $nonce.'x']))->assertSessionHasErrors('meta_connect');
        $this->actingAs($this->admin)->get(route('admin.connectors.meta.connected', ['nonce' => $nonce]))->assertSessionHasErrors('meta_connect');

        $nonce = $this->startConnect();
        $this->broker->deliver($this, $nonce, $this->oneCatalog())->assertOk();
        $this->travel(MetaConnectController::FLOW_TTL + 1)->seconds();
        $this->actingAs($this->admin)->get(route('admin.connectors.meta.connected', ['nonce' => $nonce]))->assertSessionHasErrors('meta_connect');

        $this->travelBack();
        $nonce = $this->startConnect();
        $this->broker->deliver($this, $nonce, $this->oneCatalog())->assertOk();
        $other = User::factory()->create(['role' => 'admin']);
        $this->actingAs($other)->withSession([MetaConnectController::SESSION_FLOW => ['nonce' => $nonce, 'expires_at' => now()->getTimestamp() + 60, 'user_id' => $other->id]])
            ->get(route('admin.connectors.meta.connected', ['nonce' => $nonce]))
            ->assertSessionHasErrors(['meta_connect' => __('connectors.meta_connect_retry')]);

        $this->assertTrue($this->store()->get()->isManual());
        $this->assertNull($this->store()->accessToken());
        $this->assertSame([], $this->sync->provisioned);
    }

    public function test_the_delivery_endpoint_accepts_only_a_signed_delivery_for_a_pending_nonce(): void
    {
        $nonce = $this->startConnect();
        $payload = json_encode(FakeRuwebConnect::payload($nonce, $this->oneCatalog()));
        $post = fn (array $server, ?string $body = null): TestResponse => $this->call('POST', RuwebConnectHandoffReceiver::PATH, [], [], [], $server, $body ?? $payload);

        $refusals = [
            'unsigned' => $post(['CONTENT_TYPE' => 'application/json']),
            'other secret' => $post(FakeRuwebConnect::signedServer($payload, secret: 'not-the-site-secret')),
            'other site' => $post(FakeRuwebConnect::signedServer($payload, siteId: '01JOTHERSITE000000000000000')),
            'too old' => $post(FakeRuwebConnect::signedServer($payload, now()->getTimestamp() - 301)),
            'body changed' => $post(FakeRuwebConnect::signedServer($payload), str_replace(FakeMetaGraph::TOKEN, 'EAAForgedToken123', $payload)),
            'unknown nonce' => $this->broker->deliver($this, 'n0nce-nobody-started-0123456789'),
            'no token' => $this->broker->deliver($this, $nonce, ['access_token' => '']),
            'bad business' => $this->broker->deliver($this, $nonce, ['business_id' => 'x<script>']),
        ];
        foreach ($refusals as $case => $response) {
            $this->assertSame(404, $response->status(), $case);
            $this->assertSame(['message' => 'Not Found'], $response->json(), $case);
        }
        $this->assertNull($this->store()->pullDelivery($nonce, $this->admin->id), 'Nothing was kept from a refused delivery.');

        // The real one is accepted once: the same signed request again, or a new one for the used nonce, is refused.
        $server = FakeRuwebConnect::signedServer($payload);
        $post($server)->assertOk()->assertExactJson(['ok' => true])->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(404, $post($server)->status(), 'replayed');
        $this->assertSame(404, $this->broker->deliver($this, $nonce, [], now()->getTimestamp() + 1)->status(), 'nonce used');

        $this->actingAs($this->admin)->get(route('admin.connectors.meta.connected', ['nonce' => $nonce]))->assertSessionHasNoErrors();
        $this->assertSame(FakeMetaGraph::TOKEN, $this->store()->accessToken());
    }

    public function test_the_delivery_endpoint_is_stateless_and_never_logs_the_token(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });
        $nonce = $this->startConnect();

        $response = $this->broker->deliver($this, $nonce, $this->oneCatalog());
        $response->assertOk();
        $this->assertSame([], $response->headers->getCookies(), 'No session or CSRF cookie on the delivery.');
        $this->broker->deliver($this, $nonce, ['access_token' => 'EAAOtherTokenNeverLogged0123'])->assertNotFound();

        $this->actingAs($this->admin)->get(route('admin.connectors.meta.connected', ['nonce' => $nonce]))->assertSessionHasNoErrors();
        $this->assertNotEmpty($logged);
        foreach ($logged as $line) {
            $this->assertStringNotContainsString(FakeMetaGraph::TOKEN, $line);
            $this->assertStringNotContainsString('EAAOtherTokenNeverLogged0123', $line);
            $this->assertStringNotContainsString(FakeRuwebConnect::SITE_SECRET, $line);
        }
        $this->assertStringNotContainsString(FakeMetaGraph::TOKEN, (string) DB::table('connectors')->where('key', 'meta')->value('secrets'), 'Stored encrypted.');
    }

    public function test_a_delivery_for_an_admin_who_lost_the_role_is_refused(): void
    {
        $nonce = $this->startConnect();
        $this->admin->forceFill(['role' => 'editor'])->save();

        $this->broker->deliver($this, $nonce, $this->oneCatalog())->assertNotFound();
        $this->assertNull($this->store()->pullDelivery($nonce, $this->admin->id));
    }

    public function test_an_unclaimed_delivery_leaves_with_the_flow(): void
    {
        $nonce = $this->startConnect();
        $this->broker->deliver($this, $nonce, $this->oneCatalog())->assertOk();
        $this->travel(MetaConnectController::FLOW_TTL + 1)->seconds();
        $this->store()->startFlow('another-flow-nonce-0123456789', $this->admin->id, MetaConnectController::FLOW_TTL);

        $secrets = Connector::query()->where('key', 'meta')->firstOrFail()->secretValue(MetaConnectionStore::DELIVERIES);
        $this->assertNull($secrets, 'The expired delivery (and its token) is dropped on the next write.');
        $this->assertNull($this->store()->pullDelivery($nonce, $this->admin->id));
    }

    public function test_cancelling_in_facebook_is_a_friendly_message_and_changes_nothing(): void
    {
        $nonce = $this->startConnect();
        $this->actingAs($this->admin)->get(route('admin.connectors.meta.connected', ['error' => 'cancelled', 'nonce' => $nonce]))
            ->assertSessionHas('success', __('connectors.meta_connect_cancelled'))
            ->assertSessionHasNoErrors();
        $this->broker->deliver($this, $nonce, $this->oneCatalog())->assertNotFound();
        $this->assertTrue($this->store()->get()->isManual());
        $this->assertFalse((bool) app(ConnectorRegistry::class)->record('meta')?->enabled);
    }

    public function test_several_catalogs_show_a_choice_of_commerce_ones_only(): void
    {
        $this->returnFromMeta()->assertRedirect(route('admin.connectors.meta.catalog'));
        $this->assertSame([], $this->sync->provisioned);
        $this->assertTrue($this->store()->get()->isConnected());
        $this->assertNull($this->store()->get()->catalogId);

        $page = $this->actingAs($this->admin)->get(route('admin.connectors.meta.catalog'))->assertOk();
        $page->assertSee('data-meta-catalog-option="'.FakeMetaGraph::OTHER_CATALOG_ID.'"', false)
            ->assertSee('data-meta-catalog-option="'.FakeMetaGraph::CATALOG_ID.'"', false)
            ->assertDontSee(FakeMetaGraph::GENERIC_CATALOG_ID)
            ->assertSee('Ruweb prueba feed');

        $this->actingAs($this->admin)->post(route('admin.connectors.meta.catalog.select'), ['catalog_id' => FakeMetaGraph::GENERIC_CATALOG_ID])
            ->assertRedirect(route('admin.connectors.meta.catalog'))->assertSessionHasErrors('catalog_id');
        $this->assertSame([], $this->sync->provisioned);

        $this->actingAs($this->admin)->post(route('admin.connectors.meta.catalog.select'), ['catalog_id' => FakeMetaGraph::OTHER_CATALOG_ID])
            ->assertSessionHas('success', __('connectors.meta_connected', ['catalog' => 'Ruweb prueba feed']));
        $this->assertSame([FakeMetaGraph::OTHER_CATALOG_ID], $this->sync->provisioned);
        $this->assertSame(FakeMetaGraph::OTHER_CATALOG_ID, $this->store()->get()->catalogId);
        $this->assertNull(session(MetaConnectController::SESSION_CATALOGS));
    }

    public function test_changing_the_catalog_later_reads_the_shared_catalogs_from_meta(): void
    {
        $this->returnFromMeta($this->oneCatalog());
        $graph = $this->graph;
        $before = count($graph->calls());

        $this->actingAs($this->admin)->get(route('admin.connectors.meta.catalog'))->assertOk()
            ->assertSee('data-meta-catalog-option="'.FakeMetaGraph::OTHER_CATALOG_ID.'"', false)
            ->assertDontSee('data-meta-catalog-option="'.FakeMetaGraph::GENERIC_CATALOG_ID.'"', false);
        $this->assertSame('/me/assigned_product_catalogs', '/'.ltrim($graph->calls()[$before]['path'], '/'));
        $this->assertSame('Bearer '.FakeMetaGraph::TOKEN, $graph->calls()[$before]['authorization']);

        $graph->removeApp();
        $this->actingAs($this->admin)->get(route('admin.connectors.meta.catalog'))->assertSessionHasErrors('meta_connect');
        $this->assertTrue($this->store()->get()->needsReconnect(), 'A 190 while reading catalogs asks to reconnect.');
    }

    public function test_no_commerce_catalog_keeps_nothing_and_explains_the_steps(): void
    {
        $this->returnFromMeta(['catalogs' => [['id' => FakeMetaGraph::GENERIC_CATALOG_ID, 'name' => 'Catalog', 'vertical' => 'generic', 'product_count' => 0]]])
            ->assertSessionHas('meta_connect_no_catalog', 'generic');
        $this->assertTrue($this->store()->get()->isManual());
        $this->assertNull($this->store()->accessToken(), 'An unusable token is not kept.');
        $this->assertSame([], $this->sync->provisioned);

        $page = $this->actingAs($this->admin)->withSession(['meta_connect_no_catalog' => 'generic'])->get(route('admin.connectors.show', ['connector' => 'meta']))->assertOk();
        $page->assertSee(__('connectors.meta_no_catalog_title'))->assertSee(__('connectors.meta_no_catalog_generic'))
            ->assertSee('data-meta-connect="connect"', false);

        $this->returnFromMeta(['catalogs' => []])->assertSessionHas('meta_connect_no_catalog', 'none');
        $this->assertTrue($this->store()->get()->isManual());
    }

    public function test_a_delivery_is_used_once(): void
    {
        $nonce = $this->startConnect();
        $this->broker->deliver($this, $nonce, $this->oneCatalog())->assertOk();
        $this->actingAs($this->admin)->get(route('admin.connectors.meta.connected', ['nonce' => $nonce]))->assertSessionHasNoErrors();

        // The same return again (back button): the flow is gone and the connection stays as it was.
        $this->actingAs($this->admin)->get(route('admin.connectors.meta.connected', ['nonce' => $nonce]))
            ->assertSessionHasErrors(['meta_connect' => __('connectors.meta_connect_expired')]);
        $this->assertTrue($this->store()->get()->canSync());
    }

    public function test_returning_without_a_delivery_asks_to_connect_again_and_changes_nothing(): void
    {
        $this->returnFromMeta($this->oneCatalog());
        $before = $this->store()->get()->toArray();

        // The broker could not deliver (it shows «Volvé a intentar») but the browser came back anyway.
        $nonce = $this->startConnect();
        $this->actingAs($this->admin)->get(route('admin.connectors.meta.connected', ['nonce' => $nonce]))
            ->assertSessionHasErrors(['meta_connect' => __('connectors.meta_connect_retry')]);
        $this->assertSame($before, $this->store()->get()->toArray());
        $this->assertSame(FakeMetaGraph::TOKEN, $this->store()->accessToken());
    }

    public function test_reconnecting_the_same_business_keeps_its_catalog_without_asking(): void
    {
        $this->returnFromMeta($this->oneCatalog());
        $this->store()->markNeedsReconnect('Error validating access token (190/464)');
        $this->assertSame(ConnectorStatus::NeedsReconnect, app(ConnectorRegistry::class)->status('meta'));

        $this->returnFromMeta(['access_token' => 'EAANewTokenAfterReconnect0123456789'])
            ->assertRedirect(route('admin.connectors.show', ['connector' => 'meta']).'#meta-connection');
        $this->assertSame([FakeMetaGraph::CATALOG_ID, FakeMetaGraph::CATALOG_ID], $this->sync->provisioned);
        $connection = $this->store()->get();
        $this->assertFalse($connection->needsReconnect());
        $this->assertSame(['6789', FakeMetaGraph::CATALOG_ID], [$connection->tokenSuffix, $connection->catalogId]);
        $this->assertSame(ConnectorStatus::Connected, app(ConnectorRegistry::class)->status('meta'));
    }

    public function test_a_provision_failure_keeps_the_connection_and_says_what_happened(): void
    {
        $this->sync->failProvision = MetaCatalogSyncException::for(MetaCatalogSyncException::CATALOG_NOT_COMMERCE, ['catalog' => 'X']);
        $this->returnFromMeta($this->oneCatalog())
            ->assertSessionHasErrors(['meta_connect' => __('meta_sync.error_catalog_not_commerce', ['catalog' => 'X'])]);
        $this->assertTrue($this->store()->get()->isConnected());

        $this->sync->failProvision = new RuntimeException('boom');
        $this->returnFromMeta($this->oneCatalog())->assertSessionHasErrors(['meta_connect' => __('connectors.meta_provision_failed')]);
        $this->assertTrue($this->store()->get()->isConnected());
    }

    public function test_connected_state_on_card_and_detail_hides_the_manual_guide(): void
    {
        $this->returnFromMeta($this->oneCatalog());

        $page = $this->detail();
        $page->assertSee('data-meta-connection="connected"', false)
            ->assertSee(__('connectors.meta_mode_connected'))
            ->assertSee('Ruweb spike v2 commerce')
            ->assertSee(FakeMetaGraph::BUSINESS_ID)
            ->assertSee('Ana Admin')
            ->assertSee(__('connectors.meta_connected_access_value', ['suffix' => substr(FakeMetaGraph::TOKEN, -4)]))
            ->assertSee('action="'.route('admin.connectors.meta.disconnect').'"', false)
            ->assertSee('name="delete_feed"', false)
            ->assertSee(MetaConnectController::BUSINESS_SETTINGS_URL, false)
            ->assertDontSee(__('connectors.guide_title'))
            ->assertDontSee('data-meta-connect="connect"', false);
        $this->assertStringNotContainsString(FakeMetaGraph::TOKEN, $page->getContent());

        $grid = $this->actingAs($this->admin)->get(route('admin.connectors.index'))->assertOk();
        $grid->assertSee('data-meta-card="connected"', false)
            ->assertSee(e(__('connectors.meta_card_connected', ['catalog' => 'Ruweb spike v2 commerce'])), false);
    }

    public function test_regenerating_the_feed_url_while_connected_asks_to_paste_nothing(): void
    {
        $this->returnFromMeta($this->oneCatalog());

        $this->detail()
            ->assertSee(__('connectors.feed_url_help_connected'))
            ->assertSee('data-confirm="'.e(__('connectors.confirm_rotate_connected')).'"', false)
            ->assertDontSee('data-confirm="'.e(__('connectors.confirm_rotate')).'"', false)
            ->assertDontSee(__('connectors.feed_url_help'));

        $this->actingAs($this->admin)->post(route('admin.connectors.rotate', ['connector' => 'meta']))
            ->assertRedirect(route('admin.connectors.show', ['connector' => 'meta']))
            ->assertSessionHas('success', __('connectors.token_rotated_connected'));

        $this->store()->markNeedsReconnect('Error validating access token (190/464)');
        $this->detail()->assertSee('data-confirm="'.e(__('connectors.confirm_rotate')).'"', false);
    }

    public function test_needs_reconnect_offers_a_direct_button_on_card_and_detail(): void
    {
        $this->returnFromMeta($this->oneCatalog());
        $this->store()->markNeedsReconnect('Error validating access token (190/464)');

        $grid = $this->actingAs($this->admin)->get(route('admin.connectors.index'))->assertOk();
        $grid->assertSee('data-status="needs_reconnect"', false)
            ->assertSee(__('connectors.status_needs_reconnect'))
            ->assertSee(__('connectors.meta_card_reconnect'))
            ->assertSee('href="'.route('admin.connectors.meta.connect').'"', false);

        $page = $this->detail();
        $page->assertSee('data-meta-connection="needs_reconnect"', false)
            ->assertSee(__('connectors.meta_reconnect_title'))
            ->assertSee('data-meta-connect="reconnect"', false)
            ->assertSee('action="'.route('admin.connectors.meta.disconnect').'"', false);
    }

    public function test_disconnect_always_drops_the_token_even_when_meta_fails_and_never_touches_the_catalog(): void
    {
        $this->returnFromMeta($this->oneCatalog());
        $this->actingAs($this->admin)->post(route('admin.connectors.meta.disconnect'), ['delete_feed' => '1'])
            ->assertSessionHas('success', __('connectors.meta_disconnected'))->assertSessionHasNoErrors();
        $this->assertSame([true], $this->sync->teardowns);
        $this->assertTrue($this->store()->get()->isManual());
        $this->assertNull($this->store()->accessToken());
        $this->assertSame(FakeRuwebConnect::SITE_ID, $this->store()->connectSiteId(), 'The broker registration stays for the next connection.');
        $this->assertTrue(app(ConnectorRegistry::class)->record('meta')->enabled, 'Back to the manual feed, which keeps answering.');

        $page = $this->actingAs($this->admin)->withSession(['meta_disconnected' => true])->get(route('admin.connectors.show', ['connector' => 'meta']))->assertOk();
        $page->assertSee('data-meta-remove-app', false)->assertSee(__('connectors.guide_title'))->assertSee('data-meta-connect="connect"', false);

        // Meta fails: the token and ids go anyway.
        $this->returnFromMeta($this->oneCatalog());
        $this->sync->failTeardown = true;
        $this->actingAs($this->admin)->post(route('admin.connectors.meta.disconnect'))
            ->assertSessionHasErrors(['meta_connect' => __('connectors.meta_disconnect_meta_failed')]);
        $this->assertSame([true, false], $this->sync->teardowns);
        $this->assertTrue($this->store()->get()->isManual());
        $this->assertNull($this->store()->accessToken());
    }

    public function test_disconnect_with_the_real_sync_never_deletes_the_catalog(): void
    {
        $this->app->forgetInstance(MetaCatalogSync::class);
        $graph = $this->graph;
        $this->store()->save(MetaConnection::connected(FakeMetaGraph::BUSINESS_ID, 'v27.0', ['catalog_management'], $this->admin->id)
            ->withCatalog(FakeMetaGraph::CATALOG_ID, 'Ruweb spike v2 commerce')->withProductFeed(FakeMetaGraph::FEED_ID), FakeMetaGraph::TOKEN);

        $this->actingAs($this->admin)->post(route('admin.connectors.meta.disconnect'), ['delete_feed' => '1'])->assertSessionHas('success');
        $deletes = array_values(array_filter($graph->calls(), fn (array $call): bool => $call['method'] === 'DELETE'));
        $this->assertSame([FakeMetaGraph::FEED_ID], array_column($deletes, 'path'), 'Only the feed Ruweb created.');
        $this->assertTrue($this->store()->get()->isManual());
    }

    public function test_mcp_reports_the_connection_but_never_the_token(): void
    {
        $this->returnFromMeta($this->oneCatalog());
        $this->withToken(McpApiKey::issue($this->admin, 'Meta', 'full', null));

        $rpc = $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'connectors_get', 'arguments' => ['connector' => 'meta']]])->assertOk();
        $data = json_decode($rpc->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR)['data'];
        $meta = $data['meta_connection'];
        $this->assertSame(['connected', true, false, FakeMetaGraph::BUSINESS_ID], [$meta['mode'], $meta['connected'], $meta['needs_reconnect'], $meta['business_id']]);
        $this->assertSame(['id' => FakeMetaGraph::CATALOG_ID, 'name' => 'Ruweb spike v2 commerce'], $meta['catalog']);
        $this->assertSame(substr(FakeMetaGraph::TOKEN, -4), $meta['token_ends_in']);
        $this->assertNotNull($meta['connected_at']);

        $list = $this->getJson('/mcp/connectors')->assertOk();
        $this->assertSame('connected', collect($list->json('data'))->firstWhere('key', 'meta')['meta_connection']['mode']);
        $secret = (string) Connector::where('key', 'meta')->firstOrFail()->getRawOriginal('secrets');
        foreach ([$rpc, $list, $this->getJson('/mcp/connectors/meta')->assertOk()] as $response) {
            $body = (string) $response->getContent();
            foreach ([FakeMetaGraph::TOKEN, FakeRuwebConnect::SITE_SECRET, $secret] as $leak) {
                $this->assertStringNotContainsString($leak, $body);
            }
        }

        $this->store()->forget();
        $manual = $this->getJson('/mcp/connectors/meta')->assertOk()->json('data.meta_connection');
        $this->assertSame(['manual', false, null, null], [$manual['mode'], $manual['connected'], $manual['catalog'], $manual['token_ends_in']]);
    }
}
