<?php

namespace Tests\Feature;

use App\Models\McpApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpClientCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_machine_token_exchange_excludes_real_forgery_middleware_but_consent_does_not(): void
    {
        // Laravel normally skips this middleware in tests; exercise its deployed branch.
        $this->app->instance('env', 'local');
        $this->postJson('/token', [])->assertUnprocessable()->assertJsonValidationErrors('grant_type');
        $this->postJson('/authorize', [])->assertStatus(419);
    }

    public function test_each_scope_can_initialize_and_ping_without_gaining_a_write(): void
    {
        config(['cms.version' => '0.54.99-test']);
        foreach (['full', 'read-only', 'draft-only', 'custom-code'] as $scope) {
            $this->withToken(McpApiKey::issue(User::factory()->create(['role' => 'admin']), 'Client test', $scope, null));
            $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2099-01-01']])
                ->assertOk()->assertJsonPath('result.protocolVersion', '2024-11-05')->assertJsonPath('result.serverInfo.version', '0.54.99-test');
            $response = $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 'health', 'method' => 'ping'])->assertOk();
            $this->assertIsObject(json_decode($response->getContent())->result);
            $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])->assertStatus(202);
            if ($scope !== 'full') {
                $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'menus_create', 'arguments' => ['name' => 'Denied', 'slug' => 'denied']]])
                    ->assertForbidden()->assertJsonPath('error.code', -32603);
            }
        }
        $this->assertDatabaseMissing('menus', ['slug' => 'denied']);
    }

    public function test_malformed_request_types_return_protocol_diagnostics_instead_of_server_errors(): void
    {
        $this->withToken(McpApiKey::issue(User::factory()->create(['role' => 'admin']), 'Client test', 'full', null));
        foreach ([['method' => ['tools/list']], ['method' => 'tools/list', 'params' => 'invalid'], ['method' => 'tools/list', 'id' => []]] as $invalid) {
            $this->postJson('/mcp/rpc', array_replace(['jsonrpc' => '2.0', 'id' => 1], $invalid))->assertOk()->assertJsonPath('error.code', -32600);
        }
        foreach ([['name' => ['pages_list']], ['name' => 'pages_list', 'arguments' => 'invalid']] as $params) {
            $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => $params])->assertOk()->assertJsonPath('error.code', -32602);
        }
    }
    public function test_missing_and_wrong_selector_types_are_recoverable_protocol_errors_in_both_profiles(): void
    {
        foreach (['full', 'essential'] as $profile) {
            config(['cms.admin_profile' => $profile]);
            $this->withToken(McpApiKey::issue(User::factory()->create(['role' => 'admin', 'can_manage_custom_code' => true]), 'Selectors', 'full', null));
            foreach ([
                ['pages_get', 'page_id'], ['pages_update_draft', 'page_id'], ['pages_publish', 'page_id'],
                ['posts_get', 'post_id'], ['posts_update_draft', 'post_id'], ['posts_publish', 'post_id'],
                ['media_get', 'media_id'], ['media_update_metadata', 'media_id'], ['media_usages', 'media_id'], ['media_delete', 'media_id'],
                ['settings_get', 'key'], ['settings_update', 'key'],
                ['menus_get', 'menu_id'], ['menus_update', 'menu_id'], ['menus_sync_items', 'menu_id'], ['menus_delete', 'menu_id'],
                ['content_read_field', 'id'], ['content_translate', 'id'], ['content_translation_changes', 'id'],
                ['content_translation_acknowledge', 'id'], ['content_publication', 'id'], ['content_preview', 'id'],
                ['submissions_get', 'id'], ['submissions_list', 'form'],
            ] as [$tool, $selector]) {
                foreach ([[], [$selector => []], [$selector => true], [$selector => null]] as $arguments) {
                    $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 'selector', 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]])
                        ->assertOk()->assertJsonPath('id', 'selector')->assertJsonPath('error.code', -32602)
                        ->assertJsonStructure(['error' => ['data' => [$selector]]]);
                }
            }
            // JSON numeric strings must not be silently coerced into resource identifiers.
            $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'pages_get', 'arguments' => ['page_id' => '1']]])
                ->assertOk()->assertJsonPath('error.code', -32602)->assertJsonStructure(['error' => ['data' => ['page_id']]]);
        }
    }

    public function test_every_advertised_required_argument_is_checked_before_dispatch(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'can_manage_custom_code' => true]);
        foreach (['full', 'essential'] as $profile) {
            config(['cms.admin_profile' => $profile]);
            foreach (\App\Mcp\ToolRegistry::all() as $tool) {
                $required = $tool['inputSchema']['required'] ?? [];
                if ($required === []) {
                    continue;
                }
                $scope = str_starts_with($tool['name'], 'custom_code_') ? 'custom-code' : 'full';
                $this->withToken(McpApiKey::issue($user, 'Required arguments', $scope, null));
                $response = $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => $tool['name'], 'arguments' => []]])
                    ->assertOk()->assertJsonPath('error.code', -32602);
                foreach ($required as $field) {
                    $response->assertJsonStructure(['error' => ['data' => [$field]]]);
                }
            }
        }
    }

    public function test_advertised_selector_enums_and_bounds_keep_field_diagnostics(): void
    {
        $this->withToken(McpApiKey::issue(User::factory()->create(['role' => 'admin']), 'Bounds', 'full', null));
        foreach ([
            ['content_versions', ['type' => 'unknown', 'id' => 1], 'type'],
            ['content_versions', ['type' => 'pages', 'id' => 0], 'id'],
            ['submissions_list', ['form' => 'unknown'], 'form'],
            ['media_usages', ['media_id' => -1], 'media_id'],
            ['content_preview', ['type' => 'pages', 'id' => 1, 'hours' => 169], 'hours'],
        ] as [$tool, $arguments, $field]) {
            $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]])
                ->assertOk()->assertJsonPath('error.code', -32602)->assertJsonStructure(['error' => ['data' => [$field]]]);
        }
    }

}
