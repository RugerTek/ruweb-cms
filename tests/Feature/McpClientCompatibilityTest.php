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
}
