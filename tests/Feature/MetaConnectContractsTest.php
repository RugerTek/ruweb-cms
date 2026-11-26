<?php

namespace Tests\Feature;

use App\Connectors\ConnectorRegistry;
use App\Connectors\ConnectorStatus;
use App\Connectors\Meta\Connect\HttpRuwebConnectClient;
use App\Connectors\Meta\Connect\MetaConnection;
use App\Connectors\Meta\Connect\MetaConnectionStore;
use App\Connectors\Meta\Connect\RuwebConnectException;
use App\Connectors\Meta\Connect\RuwebConnectHandoff;
use App\Connectors\Meta\Connect\RuwebConnectHandoffReceiver;
use App\Connectors\Meta\Connect\RuwebConnectSignature;
use App\Connectors\Meta\Graph\HttpMetaGraphClient;
use App\Connectors\Meta\Graph\MetaBatchStatus;
use App\Connectors\Meta\Graph\MetaGraphException;
use App\Contracts\Connectors\MetaGraphClient;
use App\Contracts\Connectors\RuwebConnectClient;
use App\Models\User;
use App\Services\Connectors\ConnectorOperations;
use DateTimeImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Support\FakeMetaGraph;
use Tests\Support\FakeRuwebConnect;
use Tests\TestCase;

/**
 * Meta v2 shared contracts (plan-meta-v2, P0): MetaConnection + encrypted store, MetaGraphClient against the real
 * answers of the spike, RuwebConnectClient with the exact HMAC of the contract, needs_reconnect and the routes.
 */
class MetaConnectContractsTest extends TestCase
{
    use RefreshDatabase;

    private function store(): MetaConnectionStore
    {
        return app(MetaConnectionStore::class);
    }

    private function connect(string $token = FakeMetaGraph::TOKEN): MetaConnection
    {
        $connection = MetaConnection::connected(FakeMetaGraph::BUSINESS_ID, 'v27.0', ['catalog_management', 'public_profile'], null)
            ->withCatalog(FakeMetaGraph::CATALOG_ID, 'Ruweb spike v2 commerce');

        return $this->store()->save($connection, $token);
    }

    public function test_connection_value_object_is_immutable_and_round_trips(): void
    {
        $manual = MetaConnection::manual();
        $this->assertTrue($manual->isManual());
        $this->assertFalse($manual->needsReconnect());
        $this->assertFalse($manual->canSync());

        $at = new DateTimeImmutable('2026-11-24T22:09:12+00:00');
        $connected = MetaConnection::connected('1828876861482638', 'v27.0', ['catalog_management'], 7, $at);
        $withCatalog = $connected->withCatalog('1140865528694664', 'Tienda')->withProductFeed('1449316787271140');
        $this->assertNull($connected->catalogId, 'with*() returns a copy.');
        $this->assertTrue($withCatalog->canSync());
        $this->assertSame('1449316787271140', $withCatalog->productFeedId);
        $this->assertNull($withCatalog->withCatalog('999', 'Otro')->productFeedId, 'Another catalog drops the feed of the previous one.');

        $revoked = $withCatalog->withReconnectRequired(str_repeat('x', 400), $at);
        $this->assertTrue($revoked->needsReconnect());
        $this->assertFalse($revoked->canSync());
        $this->assertSame(MetaConnection::ERROR_MAX, mb_strlen((string) $revoked->lastError));

        $copy = MetaConnection::fromArray($revoked->toArray());
        $this->assertEquals($revoked->toArray(), $copy->toArray());
        $this->assertSame(7, $copy->connectedBy);
        $this->assertTrue(MetaConnection::fromArray(['mode' => 'bogus'])->isManual(), 'A corrupt row reads as manual.');
    }

    public function test_v1_sites_stay_manual_and_nothing_is_written_on_read(): void
    {
        $this->useShop('catalog');
        $this->assertTrue($this->store()->get()->isManual());
        $this->assertNull($this->store()->accessToken());
        $this->assertNull($this->store()->connectSiteId());
        $this->assertSame(0, DB::table('connectors')->count());
        $this->assertSame(ConnectorStatus::Available, app(ConnectorRegistry::class)->status('meta'));
    }

    public function test_store_encrypts_everything_binds_it_to_the_origin_and_keeps_settings_untouched(): void
    {
        $saved = $this->connect();
        $this->store()->saveConnectSite(FakeRuwebConnect::SITE_ID, FakeRuwebConnect::SITE_SECRET);

        $this->assertSame(substr(FakeMetaGraph::TOKEN, -4), $saved->tokenSuffix);
        $row = DB::table('connectors')->where('key', 'meta')->first();
        $this->assertFalse((bool) $row->enabled, 'Saving a connection does not activate the connector.');
        $this->assertNull($row->settings_json, 'Nothing goes to settings (MCP, revision, updateSettings).');
        $this->assertSame('http://localhost:8000', $row->bound_origin);
        foreach ([FakeMetaGraph::TOKEN, FakeMetaGraph::BUSINESS_ID, FakeRuwebConnect::SITE_SECRET, FakeRuwebConnect::SITE_ID] as $plain) {
            $this->assertStringNotContainsString($plain, json_encode($row), 'Stored encrypted.');
        }

        $this->assertSame(FakeMetaGraph::TOKEN, $this->store()->accessToken());
        $this->assertSame(FakeRuwebConnect::SITE_ID, $this->store()->connectSiteId());
        $this->assertSame(FakeRuwebConnect::SITE_SECRET, $this->store()->connectSiteSecret());
        $this->assertSame(FakeMetaGraph::CATALOG_ID, $this->store()->get()->catalogId);

        // A copy restored on another domain reads as a v1 site and never uses the token.
        config(['app.url' => 'https://otro-dominio.example']);
        $this->assertTrue($this->store()->get()->isManual());
        $this->assertNull($this->store()->accessToken());
        $this->assertNull($this->store()->connectSiteSecret());
        config(['app.url' => 'http://localhost:8000']);
        $this->assertTrue($this->store()->get()->isConnected());

        // The model never serializes the secrets.
        $model = app(ConnectorRegistry::class)->record('meta');
        $this->assertStringNotContainsString(FakeMetaGraph::TOKEN, json_encode($model->toArray()));

        $this->store()->forget();
        $this->assertTrue($this->store()->get()->isManual());
        $this->assertNull($this->store()->accessToken());
        $this->assertSame(FakeRuwebConnect::SITE_ID, $this->store()->connectSiteId(), 'Disconnecting keeps the broker registration.');
    }

    public function test_connection_survives_settings_saves_and_health_checks_do_not_change_the_revision(): void
    {
        $this->useShop('catalog');
        $admin = User::factory()->create(['role' => 'admin']);
        $operations = app(ConnectorOperations::class);
        $operations->activate($admin, 'meta');
        $this->connect();

        $revision = $operations->revision(app(ConnectorRegistry::class)->record('meta'));
        $this->store()->recordHealth(null);
        $this->assertSame($revision, $operations->revision(app(ConnectorRegistry::class)->record('meta')));
        $this->assertNotNull($this->store()->get()->lastHealthAt);

        $operations->updateSettings($admin, 'meta', ['brand' => 'Otra marca'], $revision);
        $this->assertSame(FakeMetaGraph::CATALOG_ID, $this->store()->get()->catalogId);
        $this->assertSame(FakeMetaGraph::TOKEN, $this->store()->accessToken());
        $this->assertSame(ConnectorStatus::Connected, app(ConnectorRegistry::class)->status('meta'));
    }

    public function test_a_revoked_connection_drops_the_token_and_reports_needs_reconnect(): void
    {
        $this->useShop('catalog');
        $this->connect();
        $connection = $this->store()->markNeedsReconnect('Error validating access token (190/464)');

        $this->assertTrue($connection->needsReconnect());
        $this->assertNull($connection->tokenSuffix);
        $this->assertNull($this->store()->accessToken());
        $this->assertSame(FakeMetaGraph::BUSINESS_ID, $this->store()->get()->businessId, 'What was connected stays for the panel.');
        $this->assertSame(ConnectorStatus::NeedsReconnect, app(ConnectorRegistry::class)->status('meta'));
        $this->assertSame('connectors.status_needs_reconnect', ConnectorStatus::NeedsReconnect->label());
        foreach (['es', 'en'] as $locale) {
            $this->assertNotSame('connectors.status_needs_reconnect', __('connectors.status_needs_reconnect', [], $locale));
        }

        $this->useShop('off', 'off');
        $this->assertSame(ConnectorStatus::NeedsAction, app(ConnectorRegistry::class)->status('meta'), 'Unmet requirements come first.');
        $this->useShop('catalog');

        $this->store()->save($this->store()->get(), 'EAANewTokenAfterReconnect0123456789');
        $this->assertFalse($this->store()->get()->needsReconnect());
        $this->assertSame('6789', $this->store()->get()->tokenSuffix);
        $this->assertSame(ConnectorStatus::Available, app(ConnectorRegistry::class)->status('meta'));
    }

    public function test_graph_client_follows_the_spike_flow_with_the_token_only_in_the_header(): void
    {
        $fake = FakeMetaGraph::install();
        $this->connect();
        $graph = app(MetaGraphClient::class);
        $this->assertInstanceOf(HttpMetaGraphClient::class, $graph);

        $this->assertSame(FakeMetaGraph::BUSINESS_ID, $graph->me()->clientBusinessId);
        $catalogs = $graph->assignedProductCatalogs();
        $this->assertSame([FakeMetaGraph::OTHER_CATALOG_ID, FakeMetaGraph::GENERIC_CATALOG_ID, FakeMetaGraph::CATALOG_ID], array_map(fn ($catalog) => $catalog->id, $catalogs));
        $this->assertSame([true, false, true], array_map(fn ($catalog) => $catalog->isCommerce(), $catalogs));
        $this->assertSame('commerce', $graph->catalog(FakeMetaGraph::CATALOG_ID)->vertical);
        $this->assertFalse($graph->catalog(FakeMetaGraph::GENERIC_CATALOG_ID)->isCommerce());

        $url = 'https://tienda.example/feeds/meta/'.str_repeat('A', 48).'.xml';
        $feedId = $graph->createProductFeed(FakeMetaGraph::CATALOG_ID, 'Ruweb', 'PYG', $url);
        $this->assertSame(FakeMetaGraph::FEED_ID, $feedId);
        $create = collect($fake->calls())->firstWhere('path', FakeMetaGraph::CATALOG_ID.'/product_feeds');
        $this->assertSame('PYG', $create['form']['default_currency']);
        $this->assertSame(['interval' => 'HOURLY', 'url' => $url], json_decode($create['form']['schedule'], true));
        $this->assertSame('HOURLY', $graph->productFeed($feedId)->scheduleInterval);
        $graph->updateProductFeedSchedule($feedId, 'https://tienda.example/feeds/meta/'.str_repeat('B', 48).'.xml');
        $this->assertStringContainsString('TOKENREGENERADO', (string) $graph->productFeed($feedId)->scheduleUrl, 'The spike answer after updating the schedule.');

        $upload = $graph->upload($graph->createUpload($feedId, $url));
        $this->assertTrue($upload->finished());
        $this->assertSame([0, 3, 3], [$upload->errorCount, $upload->detectedItems, $upload->persistedItems]);
        $this->assertSame([], $graph->uploadErrors($upload->id));

        $handles = $graph->itemsBatch(FakeMetaGraph::CATALOG_ID, [['method' => 'UPDATE', 'data' => ['id' => 'SPIKE-A', 'price' => '89000 PYG']]]);
        $this->assertCount(1, $handles);
        $usage = $graph->lastUsage();
        $this->assertSame('catalog_batch_catalog_level_per_minute', $usage[0]->type);
        $this->assertSame(FakeMetaGraph::CATALOG_ID, $usage[0]->objectId);
        $this->assertFalse($usage[0]->shouldSlowDown());
        $batch = $graph->batchStatus(FakeMetaGraph::CATALOG_ID, $handles[0]);
        $this->assertTrue($batch->finished());
        $this->assertFalse($batch->hasIssues());
        $graph->deleteProductFeed($feedId);

        foreach ($fake->calls() as $call) {
            $this->assertSame('Bearer '.FakeMetaGraph::TOKEN, $call['authorization']);
            $this->assertStringNotContainsString(FakeMetaGraph::TOKEN, $call['url']);
            $this->assertStringStartsWith('https://graph.facebook.com/v27.0/', $call['url']);
        }
    }

    public function test_graph_client_sends_items_batch_requests_and_schedule_as_json_fields_like_the_spike(): void
    {
        $this->connect();
        Http::fake([
            'graph.facebook.com/*/items_batch' => Http::response(['handles' => ['AcxHandle1']]),
            'graph.facebook.com/*' => Http::response(['id' => FakeMetaGraph::FEED_ID]),
        ]);
        $graph = app(MetaGraphClient::class);
        $requests = [
            ['method' => 'UPDATE', 'data' => ['id' => 'SPIKE-A', 'title' => 'Remera «Azul»', 'price' => '89000 PYG', 'link' => 'https://tienda.example/productos/spike-a']],
            ['method' => 'DELETE', 'data' => ['id' => 'SPIKE-C']],
        ];
        $url = 'https://tienda.example/feeds/meta/'.str_repeat('A', 48).'.xml';

        $this->assertSame(['AcxHandle1'], $graph->itemsBatch(FakeMetaGraph::CATALOG_ID, $requests));
        $graph->createProductFeed(FakeMetaGraph::CATALOG_ID, 'Ruweb', 'PYG', $url);
        $graph->updateProductFeedSchedule(FakeMetaGraph::FEED_ID, $url);

        // Spike (03-api/g.py): form-encoded body, every non-string value as one JSON string — never requests[0][method]=….
        Http::assertSent(function ($request) use ($requests) {
            if (! str_ends_with($request->url(), '/'.FakeMetaGraph::CATALOG_ID.'/items_batch')) {
                return false;
            }
            $this->assertSame('POST', $request->method());
            $this->assertStringStartsWith('application/x-www-form-urlencoded', $request->header('Content-Type')[0]);
            $this->assertStringNotContainsString('requests%5B0%5D', $request->body());
            parse_str($request->body(), $form);
            $this->assertSame(['item_type', 'requests'], array_keys($form));
            $this->assertSame('PRODUCT_ITEM', $form['item_type']);
            $this->assertIsString($form['requests']);
            $this->assertSame($requests, json_decode($form['requests'], true, 512, JSON_THROW_ON_ERROR));
            $this->assertStringContainsString('"Remera «Azul»"', $form['requests'], 'Unicode and slashes travel unescaped, as in the spike.');
            $this->assertStringContainsString('https://tienda.example/productos/spike-a', $form['requests']);

            return true;
        });
        $schedules = Http::recorded(fn ($request) => $request->method() === 'POST' && str_contains($request->body(), 'schedule='));
        $this->assertCount(2, $schedules);
        foreach ($schedules as [$request]) {
            $this->assertStringNotContainsString('schedule%5B', $request->body());
            parse_str($request->body(), $form);
            $this->assertSame(['interval' => 'HOURLY', 'url' => $url], json_decode($form['schedule'], true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public function test_graph_client_reads_warnings_upload_errors_and_meta_failures_from_the_spike(): void
    {
        $fake = FakeMetaGraph::install();
        $this->connect();
        $graph = app(MetaGraphClient::class);

        $fake->batchOutcome(FakeMetaGraph::BATCH_WARNINGS);
        $warnings = $graph->batchStatus(FakeMetaGraph::CATALOG_ID, 'h');
        $this->assertSame(0, $warnings->errorsTotalCount);
        $this->assertSame([], $warnings->errors);
        $this->assertSame('SPIKE-B', $warnings->warnings[0]->retailerId);
        $this->assertSame(3, $warnings->warnings[0]->line);
        $errors = $graph->batchStatus(FakeMetaGraph::GENERIC_CATALOG_ID, 'h');
        $this->assertInstanceOf(MetaBatchStatus::class, $errors);
        $this->assertSame(['SPIKE-B', 'SPIKE-A', 'SPIKE-C'], $errors->invalidRetailerIds);

        $fake->failUploads();
        $failed = $graph->upload($graph->createUpload(FakeMetaGraph::FEED_ID, 'https://tienda.example/feeds/meta/x.xml'));
        $this->assertSame(1, $failed->errorCount);
        $uploadError = $graph->uploadErrors($failed->id)[0];
        $this->assertSame('feed_file_not_found', $uploadError->errorType);
        $this->assertTrue($uploadError->isFatal());

        $fake->unshareCatalog();
        $this->assertNotContains(FakeMetaGraph::CATALOG_ID, array_map(fn ($catalog) => $catalog->id, $graph->assignedProductCatalogs()));
        foreach ([fn () => $graph->catalog(FakeMetaGraph::CATALOG_ID), fn () => $graph->itemsBatch(FakeMetaGraph::CATALOG_ID, [['method' => 'DELETE', 'data' => ['id' => 'A']]])] as $call) {
            $exception = $this->graphFailure($call);
            $this->assertTrue($exception->objectUnavailable());
            $this->assertFalse($exception->revokesConnection());
            $this->assertNotNull($exception->fbtraceId);
        }

        $fake->removeApp();
        $exception = $this->graphFailure(fn () => $graph->me());
        $this->assertSame([400, 190, 464], [$exception->httpStatus, $exception->graphCode, $exception->subcode]);
        $this->assertTrue($exception->revokesConnection());
        $this->assertSame('OAuthException', $exception->type);
        $this->assertSame('ADgzFeaoZgF80OdasU3WOSF', $exception->fbtraceId);
    }

    public function test_spike_permission_errors_and_tokens_never_reach_exception_texts(): void
    {
        $fake = new FakeMetaGraph;
        $create = $fake->response('error_create_catalog');
        $exception = MetaGraphException::fromResponse($create['status'], $create['body']);
        $this->assertSame([10, 1690129], [$exception->graphCode, $exception->subcode]);
        $this->assertTrue($exception->isPermission());
        $this->assertSame('Permiso requerido para crear catálogo', $exception->userTitle);
        $this->assertStringContainsString('no eres administrador', $exception->displayMessage());
        $business = $fake->response('error_business_management');
        $this->assertTrue(MetaGraphException::fromResponse($business['status'], $business['body'])->isPermission());

        $echo = MetaGraphException::fromResponse(400, ['error' => ['message' => 'Bad token '.FakeMetaGraph::TOKEN, 'code' => 190]], FakeMetaGraph::TOKEN);
        $this->assertStringNotContainsString(FakeMetaGraph::TOKEN, $echo->getMessage());

        // Without a usable token nothing is sent; a wrong one is refused by the fake like Meta would.
        Http::preventStrayRequests();
        $graph = app(MetaGraphClient::class);
        $this->assertSame(MetaGraphException::CODE_NOT_CONNECTED, $this->graphFailure(fn () => $graph->me())->graphCode);
        $installed = FakeMetaGraph::install();
        $this->assertSame(190, $this->graphFailure(fn () => app(MetaGraphClient::class)->usingToken('EAAOtherToken000000000000')->me())->graphCode);
        $this->assertSame(FakeMetaGraph::BUSINESS_ID, app(MetaGraphClient::class)->usingToken(FakeMetaGraph::TOKEN)->me()->clientBusinessId);
        $this->assertCount(2, $installed->calls());
    }

    public function test_ruweb_connect_signatures_match_known_vectors(): void
    {
        // Vectors computed outside PHP (Python hmac/hashlib) from the formulas of plan-meta-v2.
        $this->assertSame('b5955ba3d7cc2e9d2d293d8101ff7a2a7d9efaf26f28d16f9408f1348baee1c3', RuwebConnectSignature::request('ruweb-test-secret', 'POST', '/v1/meta/handoff/abc123', 1795558125, ''));
        $this->assertSame('b6cdd6d8fba6f0c23e49fb9d9c8ab0f7748595f290385dadf183a51130027e80', RuwebConnectSignature::request('ruweb-test-secret', 'post', '/v1/sites/register', 1795558125, '{"origin":"https://tienda.example","name":"Mi tienda"}'));
        $this->assertSame('92fc6bb0a0417b936f9a0907998ff99cf3723ffc3863967e98de9cfe9bcdf452', RuwebConnectSignature::start('ruweb-test-secret', '01JFAKESITE', 'n0nce', 1795558125));
        // Contract v2, broker → site delivery: the same vector the broker's MetaCallbackTest and README check.
        $this->assertSame('d90ae0722d201b8b038496270737f8d72a88bd714756bfe9695259506a55bfc8', RuwebConnectSignature::request('test-site-secret', 'POST', RuwebConnectHandoffReceiver::PATH, 1764000000, '{"nonce":"n"}'));

        $signature = RuwebConnectSignature::request('s', 'POST', '/p', 1000, 'b');
        $this->assertTrue(RuwebConnectSignature::verifyRequest('s', 'POST', '/p', '1000', 'b', $signature, 1300));
        $this->assertFalse(RuwebConnectSignature::verifyRequest('s', 'POST', '/p', '1000', 'b', $signature, 1301), '±300 s window.');
        $this->assertFalse(RuwebConnectSignature::verifyRequest('s', 'POST', '/p', '1000', 'c', $signature, 1000), 'Body is signed.');
        $this->assertFalse(RuwebConnectSignature::verifyRequest('x', 'POST', '/p', '1000', 'b', $signature, 1000));
    }

    public function test_ruweb_connect_client_runs_the_whole_contract_against_the_fake(): void
    {
        $broker = FakeRuwebConnect::install();
        $client = app(RuwebConnectClient::class);
        $this->assertInstanceOf(HttpRuwebConnectClient::class, $client);

        $registration = $client->register('http://localhost:8000', 'Mi tienda');
        $this->assertSame('/.well-known/ruweb-connect/'.FakeRuwebConnect::CHALLENGE_TOKEN, $registration->challengePath);
        $this->assertSame(['origin' => 'http://localhost:8000', 'name' => 'Mi tienda'], json_decode($broker->calls()[0]['body'], true));
        $this->store()->startRegistration($registration->registrationId, $registration->challengeToken, $registration->expiresIn);
        $this->assertSame(FakeRuwebConnect::REGISTRATION_ID, $this->store()->registrationId());

        $broker->challengeCheck = fn (string $path, string $token): bool => $this->get($path)->assertOk()->getContent() === $token;
        $site = $client->verify($registration->registrationId);
        $this->assertSame('{}', $broker->calls()[1]['body']);
        $this->assertStringNotContainsString(FakeRuwebConnect::SITE_SECRET, print_r($site, true));
        $this->store()->saveConnectSite($site->siteId, $site->siteSecret);
        $this->assertNull($this->store()->registrationId(), 'Verified: the challenge is no longer served.');
        $this->get($registration->challengePath)->assertNotFound();

        $start = $client->startUrl($this->store()->connectSiteId(), $this->store()->connectSiteSecret(), 'nonce-123');
        $this->assertTrue(FakeRuwebConnect::validStartUrl($start));
        $this->assertStringNotContainsString(FakeRuwebConnect::SITE_SECRET, $start);

        // Contract v2: the broker POSTs the token to the site for the pending nonce; the site keeps it for that admin.
        $admin = User::factory()->create(['role' => 'admin']);
        $this->store()->startFlow('nonce-0123456789abcdef', $admin->id, 600);
        $broker->deliver($this, 'nonce-0123456789abcdef')->assertOk()->assertExactJson(['ok' => true]);
        $this->assertNull($this->store()->pullDelivery('nonce-0123456789abcdef', $admin->id + 1), 'Only for the admin who started it.');
        $this->store()->startFlow('nonce-0123456789abcdeg', $admin->id, 600);
        $broker->deliver($this, 'nonce-0123456789abcdeg')->assertOk();
        $handoff = $this->store()->pullDelivery('nonce-0123456789abcdeg', $admin->id);
        $this->assertSame(FakeMetaGraph::TOKEN, $handoff->accessToken);
        $this->assertSame(FakeMetaGraph::BUSINESS_ID, $handoff->businessId);
        $this->assertSame([FakeMetaGraph::OTHER_CATALOG_ID, FakeMetaGraph::CATALOG_ID], array_map(fn ($catalog) => $catalog->id, $handoff->commerceCatalogs()));
        $this->assertSame(['catalog_management', 'public_profile'], $handoff->grantedScopes);
        $this->assertStringNotContainsString(FakeMetaGraph::TOKEN, print_r($handoff, true));
        $this->assertNull($this->store()->pullDelivery('nonce-0123456789abcdeg', $admin->id), 'Single use.');
        $this->assertCount(2, $broker->calls(), 'The site never calls the broker to collect a token.');
    }

    public function test_a_delivery_payload_is_validated_before_it_is_kept(): void
    {
        $valid = FakeRuwebConnect::payload('nonce-0123456789abcdef');
        $this->assertInstanceOf(RuwebConnectHandoff::class, RuwebConnectHandoff::fromPayload($valid));
        $this->assertSame(1828876861482638, (int) RuwebConnectHandoff::fromPayload(['business_id' => 1828876861482638] + $valid)->businessId);
        foreach ([
            ['access_token' => ''], ['access_token' => "EAA token\n"], ['access_token' => str_repeat('A', 4097)], ['access_token' => ['x']],
            ['business_id' => ''], ['business_id' => '12a'], ['graph_version' => '27'], ['graph_version' => null], ['catalogs' => 'x'], ['granted_scopes' => 'x'],
        ] as $override) {
            $this->assertNull(RuwebConnectHandoff::fromPayload(array_replace($valid, $override)), json_encode($override));
        }
        $odd = RuwebConnectHandoff::fromPayload(array_replace($valid, ['catalogs' => [['name' => 'no id'], 'x', ['id' => '1', 'vertical' => 'commerce']], 'granted_scopes' => ['catalog_management', 'Bad Scope', 7]]));
        $this->assertSame(['1'], array_map(fn ($catalog) => $catalog->id, $odd->catalogs));
        $this->assertSame(['catalog_management'], $odd->grantedScopes);
        $this->assertSame($valid['access_token'], RuwebConnectHandoff::fromPayload($odd->toPayload())->accessToken, 'Round trip through the store.');
    }

    public function test_ruweb_connect_needs_an_https_broker_url(): void
    {
        Http::preventStrayRequests();
        $client = app(RuwebConnectClient::class);
        config(['cms.meta.connect_url' => null]);
        $this->assertSame(RuwebConnectException::NOT_CONFIGURED, $this->connectFailure(fn () => $client->register('https://tienda.example', 'T'))->errorCode);

        config(['cms.meta.connect_url' => 'http://connect.test', 'cms.meta.connect_allow_http' => false]);
        $this->assertSame(RuwebConnectException::INSECURE_URL, $this->connectFailure(fn () => $client->register('https://tienda.example', 'T'))->errorCode);

        config(['cms.meta.connect_allow_http' => true]);
        $this->assertSame('http://connect.test', $client->baseUrl(), 'Allowed for development (testing environment).');
        $this->app['env'] = 'production';
        $this->assertSame(RuwebConnectException::INSECURE_URL, $this->connectFailure(fn () => $client->baseUrl())->errorCode, 'Never in production.');
        $this->app['env'] = 'testing';

        $this->assertSame('v27.0', config('cms.meta.graph_version'));
    }

    public function test_routes_are_registered_and_leak_nothing(): void
    {
        foreach (['admin.connectors.meta.connect' => 'GET', 'admin.connectors.meta.connected' => 'GET', 'admin.connectors.meta.disconnect' => 'POST', 'connectors.ruweb-connect.challenge' => 'GET', 'connectors.ruweb-connect.handoff' => 'POST'] as $name => $method) {
            $this->assertTrue(Route::has($name), $name);
            $this->assertContains($method, Route::getRoutes()->getByName($name)->methods(), $name);
        }
        $this->assertSame('http://localhost:8000/admin/connectors/meta/connect', route('admin.connectors.meta.connect'));
        $this->assertSame('http://localhost:8000/.well-known/ruweb-connect/'.FakeRuwebConnect::CHALLENGE_TOKEN, route('connectors.ruweb-connect.challenge', ['token' => FakeRuwebConnect::CHALLENGE_TOKEN]));
        $this->assertSame('http://localhost:8000'.RuwebConnectHandoffReceiver::PATH, route('connectors.ruweb-connect.handoff'));
        $handoffRoute = Route::getRoutes()->getByName('connectors.ruweb-connect.handoff');
        $this->assertContains(PreventRequestForgery::class, $handoffRoute->excludedMiddleware(), 'Signed, not CSRF-protected.');
        $this->assertContains(StartSession::class, $handoffRoute->excludedMiddleware(), 'No session.');
        // Without site credentials (never registered) any delivery is a bare 404.
        $this->call('POST', RuwebConnectHandoffReceiver::PATH, [], [], [], FakeRuwebConnect::signedServer('{}'), '{}')->assertNotFound()->assertExactJson(['message' => 'Not Found']);
        $this->get(RuwebConnectHandoffReceiver::PATH)->assertStatus(405);

        $this->get(route('admin.connectors.meta.connect'))->assertNotFound();
        $admin = User::factory()->create(['role' => 'admin']);
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->get(route('admin.connectors.meta.connected', ['nonce' => 'n']))->assertStatus(403);

        // Track 2b filled them (tests/Feature/MetaConnectTest.php): without a broker or a nonce they change nothing.
        $detail = route('admin.connectors.show', ['connector' => 'meta']).'#meta-connection';
        config(['cms.meta.connect_url' => null]);
        $this->actingAs($admin)->get(route('admin.connectors.meta.connect'))->assertRedirect($detail)->assertSessionHasErrors('meta_connect');
        $this->actingAs($admin)->post(route('admin.connectors.meta.disconnect'))->assertRedirect($detail);
        $back = $this->actingAs($admin)->get(route('admin.connectors.meta.connected', ['nonce' => 'secret-nonce-from-elsewhere']));
        $back->assertRedirect($detail)->assertSessionHasErrors('meta_connect');
        $this->assertStringNotContainsString('secret-nonce-from-elsewhere', (string) $back->headers->get('Location'));
        $this->assertTrue($this->store()->get()->isManual(), 'Nothing changes without a valid nonce.');

        // The challenge only answers a registration in progress.
        $path = '/.well-known/ruweb-connect/'.FakeRuwebConnect::CHALLENGE_TOKEN;
        $this->get($path)->assertNotFound();
        $this->store()->startRegistration(FakeRuwebConnect::REGISTRATION_ID, FakeRuwebConnect::CHALLENGE_TOKEN, 600);
        $ok = $this->get($path)->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertSame(FakeRuwebConnect::CHALLENGE_TOKEN, $ok->getContent());
        $this->assertSame([], $ok->headers->getCookies(), 'Stateless: no session cookie.');
        $this->get('/.well-known/ruweb-connect/OtherChallengeToken0123456789abcdef')->assertNotFound();
        $this->travel(601)->seconds();
        $this->get($path)->assertNotFound();
    }

    private function graphFailure(callable $call): MetaGraphException
    {
        try {
            $call();
        } catch (MetaGraphException $exception) {
            return $exception;
        }
        $this->fail('Expected a MetaGraphException.');
    }

    private function connectFailure(callable $call): RuwebConnectException
    {
        try {
            $call();
        } catch (RuwebConnectException $exception) {
            return $exception;
        }
        $this->fail('Expected a RuwebConnectException.');
    }
}
