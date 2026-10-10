<?php

namespace Tests\Feature;

use App\Mcp\ToolRegistry;
use App\Models\McpApiKey;
use App\Models\Locale;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class McpCustomCodeTest extends TestCase
{
    use RefreshDatabase;

    public static function profiles(): array
    {
        return [['full'], ['essential']];
    }

    private function rpc(string $tool, array $input = [])
    {
        return $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $input]]);
    }

    private function payload(string $key): array
    {
        return ['name' => 'Local marker', 'description' => 'No external traffic', 'type' => 'javascript', 'code' => 'window.localMarker = true;', 'position' => 'body_end', 'sort_order' => 10, 'rules' => ['global' => true, 'include' => [], 'exclude' => []], 'operation_key' => $key];
    }

    #[DataProvider('profiles')]
    public function test_rest_and_rpc_share_pending_versions_and_exact_activation(string $profile): void
    {
        config(['cms.admin_profile' => $profile]);
        $actor = User::factory()->create(['role' => 'admin', 'can_manage_custom_code' => true]);
        $this->withToken(McpApiKey::issue($actor, 'Custom code', 'custom-code', null));
        $input = $this->payload('create-marker');
        $created = $this->postJson('/mcp/custom-code', $input)->assertOk()->json('data');
        $this->assertNull($created['active_version_id']);
        $retry = $this->rpc('custom_code_save', $input)->assertOk();
        $this->assertSame($created, json_decode($retry->json('result.content.0.text'), true)['data']);
        $id = $created['id'];
        $version = $created['versions'][0]['id'];
        $activation = ['id' => $id, 'version_id' => $version, 'expected_revision' => $created['revision'], 'operation_key' => 'activate-marker'];
        $response = $this->rpc('custom_code_activate', $activation)->assertOk();
        $active = json_decode($response->json('result.content.0.text'), true)['data'];
        $this->assertSame($version, $active['active_version_id']);
        $updated = $this->patchJson('/mcp/custom-code/'.$id, array_replace($input, ['code' => 'window.pendingMarker = true;', 'expected_revision' => $active['revision'], 'operation_key' => 'pending-marker']))->assertOk()->json('data');
        $this->assertSame($version, $updated['active_version_id']);
        $this->assertCount(2, $updated['versions']);
        $this->getJson('/mcp/custom-code/'.$id)->assertOk()->assertJsonPath('data.active_version_id', $version);
        $disabled = $this->postJson('/mcp/custom-code/'.$id.'/deactivate', ['expected_revision' => $updated['revision'], 'operation_key' => 'disable-marker'])->assertOk()->json('data');
        $this->assertSame('inactive', $disabled['state']);
        $this->getJson('/mcp/capabilities')->assertOk()->assertJsonPath('data.capabilities.custom_code', true);
    }

    public function test_editorial_credentials_cannot_read_or_activate_executable_code(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'can_manage_custom_code' => true]);
        foreach (['full', 'draft-only', 'read-only'] as $scope) {
            $this->withToken(McpApiKey::issue($actor, $scope, $scope, null));
            $this->getJson('/mcp/custom-code')->assertForbidden();
            $this->getJson('/mcp/custom-code/destinations')->assertForbidden();
            foreach (['list', 'get', 'save', 'activate', 'deactivate', 'evaluate', 'destinations'] as $operation) {
                $this->rpc('custom_code_'.$operation)->assertForbidden()->assertJsonPath('error.code', -32603);
            }
        }
        $unprivileged = User::factory()->create(['role' => 'admin', 'can_manage_custom_code' => false]);
        $this->withToken(McpApiKey::issue($unprivileged, 'Custom code', 'custom-code', null));
        $this->getJson('/mcp/custom-code')->assertForbidden();
        $this->rpc('custom_code_list')->assertForbidden()->assertJsonPath('error.code', -32603);
    }

    public function test_validation_stale_revisions_and_retry_conflicts_survive_transport_adaptation(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'can_manage_custom_code' => true]);
        $this->withToken(McpApiKey::issue($actor, 'Custom code', 'custom-code', null));
        $created = $this->postJson('/mcp/custom-code', $this->payload('original'))->assertOk()->json('data');
        $id = $created['id'];
        $this->rpc('custom_code_get')->assertOk()->assertJsonPath('error.code', -32602);
        $this->rpc('custom_code_activate', ['id' => $id])->assertOk()->assertJsonPath('error.code', -32602);
        $this->postJson('/mcp/custom-code/'.$id.'/activate', [])->assertUnprocessable()->assertJsonValidationErrors(['version_id']);
        $this->postJson('/mcp/custom-code/'.$id.'/activate', ['version_id' => $created['versions'][0]['id']])->assertUnprocessable()->assertJsonValidationErrors(['expected_revision', 'operation_key']);
        $this->postJson('/mcp/custom-code', array_replace($this->payload('original'), ['code' => 'different']))->assertConflict();
        $active = $this->postJson('/mcp/custom-code/'.$id.'/activate', ['version_id' => $created['versions'][0]['id'], 'expected_revision' => $created['revision'], 'operation_key' => 'activate'])->assertOk()->json('data');
        $this->rpc('custom_code_deactivate', ['id' => $id, 'expected_revision' => $created['revision'], 'operation_key' => 'stale'])->assertOk()->assertJsonPath('error.code', -32009);
        $this->getJson('/mcp/custom-code/'.$id)->assertOk()->assertJsonPath('data.active_version_id', $active['active_version_id']);
        $this->getJson('/mcp/custom-code/destinations?per_page=101')->assertUnprocessable();
    }

    public function test_destination_discovery_and_evaluation_match_both_transports_without_execution(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'can_manage_custom_code' => true]);
        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true]);
        $page = Page::create(['title' => 'Exact destination', 'slug' => 'exact-destination', 'locale' => 'es', 'template_key' => 'default', 'status' => 'draft']);
        $this->withToken(McpApiKey::issue($actor, 'Custom code', 'custom-code', null));
        $target = ['type' => 'pages', 'id' => $page->id, 'locale' => 'es', 'group' => false];
        $rest = $this->getJson('/mcp/custom-code/destinations?type=pages')->assertOk()->json('data');
        $rpc = $this->rpc('custom_code_destinations', ['type' => 'pages'])->assertOk();
        $this->assertSame($rest, json_decode($rpc->json('result.content.0.text'), true)['data']);
        $this->assertSame($target, array_intersect_key($rest['destinations'][0], $target));
        $input = $this->payload('diagnostic');
        $input['rules']['exclude'] = [$target];
        $saved = $this->postJson('/mcp/custom-code', $input)->assertOk()->json('data');
        $query = ['target' => $target, 'version_id' => $saved['versions'][0]['id']];
        $evaluation = $this->postJson('/mcp/custom-code/evaluate', $query)->assertOk()->json('data');
        $rpcEvaluation = $this->rpc('custom_code_evaluate', $query)->assertOk();
        $this->assertSame($evaluation, json_decode($rpcEvaluation->json('result.content.0.text'), true)['data']);
        $this->assertFalse($evaluation['snippets'][0]['applies']);
        $this->postJson('/mcp/custom-code/evaluate', ['target' => $target])->assertOk()->assertJsonCount(0, 'data.snippets');
        $this->getJson('/mcp/custom-code/'.$saved['id'])->assertOk()->assertJsonPath('data.revision', $saved['revision'])->assertJsonPath('data.active_version_id', null);
    }

    public static function rejectedCode(): array
    {
        return [
            'php' => ['html', 'body_end', '<?php echo 1; ?>'],
            'short php' => ['html', 'body_end', '<?= 1 ?>'],
            'blade expression' => ['html', 'body_end', '{{ config("app.key") }}'],
            'blade raw expression' => ['html', 'body_end', '{!! "value" !!}'],
            'blade directive' => ['html', 'body_end', '@include("admin.layouts.app")'],
            'css position' => ['css', 'body_start', 'body{color:red}'],
            'css style wrapper' => ['css', 'head_end', '<style>body{color:red}</style>'],
            'javascript wrapper' => ['javascript', 'body_end', '<script>window.marker=true</script>'],
            'html document' => ['html', 'body_end', '<html><body>Fragment</body></html>'],
            'html script' => ['html', 'body_end', '<script>window.marker=true</script>'],
            'html style' => ['html', 'head_end', '<style>body{color:red}</style>'],
        ];
    }

    #[DataProvider('rejectedCode')]
    public function test_executable_type_wrappers_positions_and_server_templates_reject_without_persisting(string $type, string $position, string $code): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'can_manage_custom_code' => true]);
        $this->withToken(McpApiKey::issue($actor, 'Custom code', 'custom-code', null));
        $input = array_replace($this->payload('invalid-template'), compact('type', 'position', 'code'));
        $this->postJson('/mcp/custom-code', $input)->assertUnprocessable();
        $this->rpc('custom_code_save', $input)->assertOk()->assertJsonPath('error.code', -32602);
        $this->assertDatabaseCount('custom_code_snippets', 0);
        $this->assertDatabaseCount('custom_code_versions', 0);
        $this->assertDatabaseCount('custom_code_events', 0);
        $this->assertDatabaseCount('custom_code_receipts', 0);
    }

    public function test_discovery_lists_the_same_tools_and_registered_destination_types_in_both_profiles(): void
    {
        $names = ['custom_code_list', 'custom_code_get', 'custom_code_save', 'custom_code_activate', 'custom_code_deactivate', 'custom_code_evaluate', 'custom_code_destinations'];
        foreach (['full', 'essential'] as $profile) {
            config(['cms.admin_profile' => $profile]);
            $tools = collect(ToolRegistry::all())->keyBy('name');
            foreach ($names as $name) $this->assertTrue($tools->has($name));
            $this->assertContains('pages', $tools['custom_code_evaluate']['inputSchema']['properties']['target']['properties']['type']['enum']);
            $this->assertContains('indexes', $tools['custom_code_evaluate']['inputSchema']['properties']['target']['properties']['type']['enum']);
            $this->assertContains('version_id', $tools['custom_code_activate']['inputSchema']['required']);
        }
    }
    public function test_evaluation_is_paginated_and_code_recovered_from_exact_version(): void
    {
        \App\Models\Locale::create(['code'=>'es','name'=>'Español','is_base'=>true,'is_active'=>true,'is_public'=>true]);
        $actor = User::factory()->create(['role'=>'admin','can_manage_custom_code'=>true]);
        $ops = app(\App\Services\CustomCode\CustomCodeOperations::class);
        $page = \App\Models\Page::create(['title'=>'Target','slug'=>'target','template_key'=>'default']);
        foreach (range(1,3) as $i) {
            $created = $ops->save($actor, array_replace($this->payload('save-'.$i), ['code'=>str_repeat('x',65536)]));
            $ops->activate($actor,$created['id'],['version_id'=>$created['versions'][0]['id'],'expected_revision'=>$created['revision'],'operation_key'=>'activate-'.$i]);
        }
        $this->withToken(McpApiKey::issue($actor,'Code','custom-code',null));
        $target = ['type'=>'pages','id'=>$page->id,'locale'=>$page->locale];
        $evaluation = $this->postJson('/mcp/custom-code/evaluate',['target'=>$target,'per_page'=>1,'page'=>2])->assertOk()->json('data');
        $this->assertSame(3,$evaluation['total']); $this->assertCount(1,$evaluation['snippets']);
        $selected=$evaluation['snippets'][0];$this->assertArrayNotHasKey('code',$selected['payload']);
        $this->assertSame(65536,$selected['code_read']['bytes']);
        $version=$this->getJson('/mcp/custom-code/'.$selected['id'].'?version_id='.$selected['version_id'])->assertOk()->json('data.versions.0');
        $this->assertSame(hash('sha256',$version['payload']['code']),$selected['code_read']['sha256']);
        $this->assertSame(65536,strlen($version['payload']['code']));
        $listing=$this->getJson('/mcp/custom-code')->assertOk()->json('data.snippets.0.versions.0');
        $this->assertArrayNotHasKey('code',$listing['payload']);
        $this->postJson('/mcp/custom-code/evaluate',['target'=>$target,'per_page'=>101])->assertUnprocessable();
    }

}
