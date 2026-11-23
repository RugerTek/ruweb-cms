<?php

namespace Tests\Feature;

use App\Http\Controllers\McpServerController;
use App\Http\Middleware\EnforceMcpScopes;
use App\Models\Connector;
use App\Models\McpApiKey;
use App\Models\User;
use App\Services\Connectors\ConnectorOperations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeCatalogSource;
use Tests\TestCase;

/** connectors_* MCP tools and REST mirror (PLAN.md §4.9, stage 2 track 2D), over the fake connector of the P0. */
class McpConnectorsTest extends TestCase
{
    use RefreshDatabase;

    private const TOOLS = ['connectors_list', 'connectors_get', 'connectors_preview', 'connectors_update', 'connectors_rotate_token'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeConnectors();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->as($this->admin);
    }

    private function as(User $user, string $scope = 'full'): void
    {
        $this->withToken(McpApiKey::issue($user, 'Connectors '.$scope, $scope, null));
    }

    private function rpc(string $tool, array $args = []): TestResponse
    {
        return $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args]]);
    }

    private function rpcData(TestResponse $response): array
    {
        $response->assertOk()->assertJsonMissingPath('error');
        $this->assertFalse($response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function revision(): string
    {
        return $this->rpcData($this->rpc('connectors_get', ['connector' => 'meta']))['data']['revision'];
    }

    private function activated(): Connector
    {
        $this->useShop('catalog');

        return app(ConnectorOperations::class)->activate($this->admin, 'meta');
    }

    public function test_the_tools_exist_whatever_the_shop_mode_and_follow_the_mcp_contract(): void
    {
        foreach (['off', 'catalog'] as $mode) {
            $this->useShop($mode);
            $tools = array_column($this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk()->json('result.tools'), 'name');
            $this->assertSame(self::TOOLS, array_values(array_intersect($tools, self::TOOLS)), "shop {$mode}");
        }
        $resolve = new \ReflectionMethod(McpServerController::class, 'resolveHandler');
        $this->assertSame([], array_values(array_filter(self::TOOLS, fn ($tool) => $resolve->invoke(app(McpServerController::class), $tool) === null)));
        $this->assertSame(['connectors_list', 'connectors_get'], array_values(array_intersect(self::TOOLS, EnforceMcpScopes::reads())));
        $doc = (string) file_get_contents(base_path('docs/operations/mcp.md'));
        $this->assertSame([], array_values(array_filter(self::TOOLS, fn ($tool) => ! str_contains($doc, '`'.$tool.'`'))));
        $this->rpc('connectors_get', ['connector' => 'nope'])->assertOk()->assertJsonPath('error.code', -32602);
        $this->getJson('/mcp/connectors/nope')->assertNotFound();
    }

    public function test_list_and_get_report_status_requirements_and_settings(): void
    {
        $this->useShop('off');
        $list = $this->rpcData($this->rpc('connectors_list'))['data'];
        $this->assertSame(['meta'], array_column($list, 'key'));
        $this->assertSame(['needs_action', false, 'shop_catalog'], [$list[0]['status'], $list[0]['enabled'], $list[0]['requirements'][0]['key']]);
        $this->assertStringContainsString('shop_mode', $list[0]['requirements'][0]['resolution']);

        $this->useShop('catalog');
        $data = $this->rpcData($this->rpc('connectors_get', ['connector' => 'meta']))['data'];
        $this->assertSame(['available', false, true, 'products'], [$data['status'], $data['enabled'], $data['requirements'][0]['met'], $data['source']]);
        $this->assertSame(['brand' => 'Default brand', 'condition' => 'new'], $data['settings']);
        $this->assertSame(['brand', 'condition'], $data['setting_keys']);
        $this->assertSame(app(ConnectorOperations::class)->revision(new Connector(['key' => 'meta', 'enabled' => false])), $data['revision']);
        $this->assertSame(0, Connector::count(), 'Reading never creates the row.');
    }

    public function test_an_administrator_activates_updates_previews_rotates_and_deactivates(): void
    {
        $this->useShop('catalog');
        app(FakeCatalogSource::class)->items = [
            FakeCatalogSource::item('p1'),
            FakeCatalogSource::item('p2', ['price' => null]),
            FakeCatalogSource::item('p3', ['brand' => null]),
        ];

        $updated = $this->rpcData($this->rpc('connectors_update', ['connector' => 'meta', 'expected_revision' => $this->revision(),
            'enabled' => true, 'settings' => ['brand' => 'Acme', 'bogus' => 1]]));
        $this->assertSame(['connected', true, 'Acme', 'new'], [$updated['data']['status'], $updated['data']['enabled'], $updated['data']['settings']['brand'], $updated['data']['settings']['condition']]);
        $this->assertSame([['code' => 'unknown_setting', 'field' => 'settings.bogus']], array_map(fn ($w) => ['code' => $w['code'], 'field' => $w['field']], $updated['warnings']));
        $record = Connector::where('key', 'meta')->firstOrFail();
        $this->assertTrue($record->enabled);
        $this->assertNotNull($record->token_hash);
        $this->assertSame(app(ConnectorOperations::class)->revision($record), $updated['data']['revision']);

        $preview = $this->rpcData($this->rpc('connectors_preview', ['connector' => 'meta', 'limit' => 1]))['data'];
        $this->assertSame([2, 1, true], [$preview['included'], $preview['skipped'], $preview['source_available']]);
        $this->assertSame(['p1'], array_column($preview['sample'], 'id'));
        $this->assertSame(150000, $preview['sample'][0]['price_amount']);
        $this->assertSame([['p2', 'missing_price']], array_map(fn ($w) => [$w['item_id'], $w['code']], $preview['skipped_reasons']));
        $this->assertSame(__('connectors.warning_missing_price'), $preview['skipped_reasons'][0]['message']);
        $this->assertSame(['p3', 'default_brand', false], [$preview['notices'][0]['item_id'], $preview['notices'][0]['code'], $preview['notices'][0]['skips']]);
        $this->assertNull($record->fresh()->last_item_count, 'Preview writes nothing.');

        $oldHash = $record->token_hash;
        $rotated = $this->rpcData($this->rpc('connectors_rotate_token', ['connector' => 'meta', 'expected_revision' => $updated['data']['revision']]))['data'];
        $this->assertNotSame($oldHash, $record->fresh()->token_hash);
        $this->assertSame($record->fresh()->token_rotated_at->toISOString(), $rotated['rotated_at']);
        $this->assertSame($this->revision(), $rotated['revision']);

        $off = $this->rpcData($this->rpc('connectors_update', ['connector' => 'meta', 'expected_revision' => $rotated['revision'], 'enabled' => false]))['data'];
        $this->assertSame(['available', false, 'Acme'], [$off['status'], $off['enabled'], $off['settings']['brand']]);
        $this->assertFalse($record->fresh()->enabled);
        $this->assertNotNull($record->fresh()->token_hash, 'Deactivating keeps the token.');
    }

    public function test_a_stale_revision_is_a_conflict_and_writes_nothing(): void
    {
        $stale = $this->revision();
        $this->activated();

        $this->rpc('connectors_update', ['connector' => 'meta', 'expected_revision' => $stale, 'settings' => ['brand' => 'Late']])
            ->assertOk()->assertJsonPath('error.code', -32009)->assertJsonPath('error.data.cause', 'revision_conflict');
        $hash = Connector::where('key', 'meta')->value('token_hash');
        $this->rpc('connectors_rotate_token', ['connector' => 'meta', 'expected_revision' => $stale])->assertOk()->assertJsonPath('error.code', -32009);
        $this->patchJson('/mcp/connectors/meta', ['expected_revision' => $stale, 'enabled' => false])->assertStatus(409);

        $record = Connector::where('key', 'meta')->firstOrFail();
        $this->assertSame([true, $hash, null], [$record->enabled, $record->token_hash, $record->settings_json]);
    }

    public function test_invalid_input_and_unmet_requirements_save_nothing(): void
    {
        $this->useShop('off');
        $revision = $this->revision();
        $this->rpc('connectors_update', ['connector' => 'meta', 'expected_revision' => $revision])->assertOk()->assertJsonPath('error.code', -32602);
        $this->rpc('connectors_update', ['connector' => 'meta'])->assertOk()->assertJsonPath('error.code', -32602);
        $this->rpc('connectors_update', ['connector' => 'meta', 'expected_revision' => $revision, 'settings' => ['condition' => 'broken']])
            ->assertOk()->assertJsonPath('error.code', -32602);
        // Settings valid, activation refused (catalog off): the whole call rolls back.
        $this->rpc('connectors_update', ['connector' => 'meta', 'expected_revision' => $revision, 'enabled' => true, 'settings' => ['brand' => 'Acme']])
            ->assertOk()->assertJsonPath('error.code', -32602);
        $this->rpc('connectors_rotate_token', ['connector' => 'meta', 'expected_revision' => $revision])->assertOk()->assertJsonPath('error.code', -32602);
        $this->assertSame(0, Connector::count());

        $preview = $this->rpcData($this->rpc('connectors_preview', ['connector' => 'meta']))['data'];
        $this->assertSame([0, 0, false, 'shop_catalog'], [$preview['included'], $preview['skipped'], $preview['source_available'], $preview['unmet_requirements'][0]['key']]);
        $this->rpc('connectors_preview', ['connector' => 'meta', 'limit' => 500])->assertOk()->assertJsonPath('error.code', -32602);
    }

    public function test_an_editor_is_refused_every_tool(): void
    {
        $this->activated();
        $revision = $this->revision();
        $this->as(User::factory()->create(['role' => 'editor']));
        foreach (['connectors_list' => [], 'connectors_get' => ['connector' => 'meta'], 'connectors_preview' => ['connector' => 'meta'],
            'connectors_update' => ['connector' => 'meta', 'expected_revision' => $revision, 'enabled' => false],
            'connectors_rotate_token' => ['connector' => 'meta', 'expected_revision' => $revision]] as $tool => $args) {
            $this->rpc($tool, $args)->assertOk()->assertJsonPath('error.code', -32003)->assertJsonPath('error.data.reason', 'forbidden');
        }
        $this->getJson('/mcp/connectors')->assertForbidden();
        $this->getJson('/mcp/connectors/meta/preview')->assertForbidden();
        $this->patchJson('/mcp/connectors/meta', ['expected_revision' => $revision, 'enabled' => false])->assertForbidden();
        $this->assertTrue(Connector::where('key', 'meta')->value('enabled'));
    }

    public function test_restricted_credentials_read_but_never_write(): void
    {
        $record = $this->activated();
        $revision = $this->revision();
        foreach (['read-only', 'draft-only'] as $scope) {
            $this->as($this->admin, $scope);
            foreach (['connectors_list' => [], 'connectors_get' => ['connector' => 'meta'], 'connectors_preview' => ['connector' => 'meta']] as $tool => $args) {
                $this->rpcData($this->rpc($tool, $args));
            }
            $this->getJson('/mcp/connectors/meta')->assertOk();
            $this->getJson('/mcp/connectors/meta/preview')->assertOk();
            $this->rpc('connectors_update', ['connector' => 'meta', 'expected_revision' => $revision, 'enabled' => false])
                ->assertOk()->assertJsonPath('error.code', -32003)->assertJsonPath('error.data.scope', $scope);
            $this->rpc('connectors_rotate_token', ['connector' => 'meta', 'expected_revision' => $revision])->assertOk()->assertJsonPath('error.code', -32003);
            $this->patchJson('/mcp/connectors/meta', ['expected_revision' => $revision, 'enabled' => false])->assertForbidden();
            $this->postJson('/mcp/connectors/meta/rotate-token', ['expected_revision' => $revision])->assertForbidden();
        }
        $fresh = $record->fresh();
        $this->assertSame([true, $record->token_hash], [$fresh->enabled, $fresh->token_hash]);
    }

    public function test_the_feed_token_and_url_never_appear_in_any_response(): void
    {
        $record = $this->activated();
        app(FakeCatalogSource::class)->items = [FakeCatalogSource::item('p1'), FakeCatalogSource::item('p2', ['price' => null])];
        $secrets = fn (Connector $connector) => array_filter([$connector->plainToken(), $connector->token_hash, $connector->feedUrl(), $connector->getRawOriginal('token')]);
        $this->assertCount(4, $secrets($record));
        $bodies = [];
        $call = function (string $tool, array $args) use (&$bodies): TestResponse {
            $response = $this->rpc($tool, ['connector' => 'meta'] + $args);
            $bodies[$tool.' '.count($bodies)] = $response->getContent();

            return $response;
        };

        $call('connectors_list', []);
        $call('connectors_get', []);
        $call('connectors_preview', []);
        $updated = $this->rpcData($call('connectors_update', ['expected_revision' => $this->revision(), 'settings' => ['brand' => 'Acme']]));
        $rotated = $this->rpcData($call('connectors_rotate_token', ['expected_revision' => $updated['data']['revision']]));
        $call('connectors_get', []);
        $call('connectors_update', ['expected_revision' => $rotated['data']['revision'], 'enabled' => false]);
        $call('connectors_update', ['expected_revision' => 'stale'.str_repeat('0', 59), 'enabled' => true]);
        foreach (['/mcp/connectors', '/mcp/connectors/meta', '/mcp/connectors/meta/preview'] as $url) {
            $bodies[$url] = $this->getJson($url)->assertOk()->getContent();
        }
        $bodies['rest rotate'] = $this->postJson('/mcp/connectors/meta/rotate-token', ['expected_revision' => $this->revision()])->assertOk()->getContent();
        $bodies['rest update'] = $this->patchJson('/mcp/connectors/meta', ['expected_revision' => $this->revision(), 'enabled' => true])->assertOk()->getContent();

        $current = Connector::where('key', 'meta')->firstOrFail();
        $all = [...$secrets($record), ...$secrets($current)];
        $this->assertCount(8, array_unique($all), 'Every rotation leaves a different token, hash and URL.');
        foreach ($bodies as $label => $body) {
            $decoded = str_replace('\\/', '/', (string) $body);
            foreach ($all as $secret) {
                $this->assertStringNotContainsString($secret, $decoded, "{$label} leaks a feed secret.");
            }
            $this->assertStringNotContainsString('/feeds/', $decoded, "{$label} leaks a feed URL.");
        }
    }
}
