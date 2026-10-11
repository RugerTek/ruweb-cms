<?php

namespace Tests\Feature;

use App\Models\McpApiKey;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpKeyScopesTest extends TestCase
{
    use RefreshDatabase;

    public function test_multiple_named_keys_preserve_one_another_and_revoke_individually(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $first = $this->actingAs($user)->post(route('admin.claude-mcp.api-key.generate'), ['name' => 'First', 'scope' => 'read-only'])->assertRedirect();
        $firstKey = session('new_mcp_api_key');
        $this->post(route('admin.claude-mcp.api-key.generate'), ['name' => 'Second', 'scope' => 'draft-only', 'expires_at' => now()->addDay()->toDateTimeString()])->assertRedirect();
        $secondKey = session('new_mcp_api_key');
        $this->assertSame(2, $user->mcpApiKeys()->count());
        $this->withToken($firstKey)->getJson('/mcp/pages')->assertOk();
        $this->withToken($secondKey)->getJson('/mcp/pages')->assertOk();
        $firstRow = $user->mcpApiKeys()->where('name', 'First')->first();
        $this->delete(route('admin.claude-mcp.api-keys.revoke', $firstRow))->assertRedirect();
        $this->withToken($firstKey)->getJson('/mcp/pages')->assertUnauthorized();
        $this->withToken($secondKey)->getJson('/mcp/pages')->assertOk();
        $this->get(route('admin.claude-mcp.index'))->assertSee('Second')->assertSee(__('security.scope_draft-only'));
    }

    public function test_expired_and_revoked_migrated_keys_cannot_use_legacy_fallback(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $legacy = $user->generateMcpApiKey();
        $row = $user->mcpApiKeys()->where('key_hash', hash('sha256', $legacy))->firstOrFail();
        $row->update(['expires_at' => now()->subMinute()]);
        $this->withToken($legacy)->getJson('/mcp/pages')->assertUnauthorized();
        $row->update(['expires_at' => null, 'revoked_at' => now()]);
        $this->withToken($legacy)->getJson('/mcp/pages')->assertUnauthorized();
    }

    public function test_read_only_scope_blocks_every_rest_and_rpc_write(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $key = McpApiKey::issue($user, 'Read', 'read-only', null);
        $this->withToken($key)->getJson('/mcp/pages')->assertOk();
        $this->postJson('/mcp/pages/drafts', ['title' => 'Denied'])->assertForbidden();
        $this->patchJson('/mcp/settings/site_name', ['value' => 'Denied'])->assertForbidden();
        $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'pages_create_draft', 'arguments' => ['title' => 'Denied']]])
            ->assertOk()->assertJsonPath('error.code', -32003)->assertJsonPath('error.data.scope', 'read-only');
        $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'pages_list', 'arguments' => []]])->assertOk();
        $this->assertSame(0, Page::count());
    }

    public function test_draft_only_scope_can_create_and_edit_drafts_but_cannot_publish_or_unpublish(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $key = McpApiKey::issue($user, 'Draft', 'draft-only', null);
        $response = $this->withToken($key)->postJson('/mcp/pages/drafts', ['title' => 'Draft', 'slug' => 'draft', 'template_key' => 'home'])->assertCreated();
        $page = Page::firstOrFail();
        $this->patchJson('/mcp/pages/'.$page->id.'/draft', ['title' => 'Edited'])->assertOk();
        $this->postJson('/mcp/pages/'.$page->id.'/publish')->assertForbidden();
        $page->forceFill(['status' => 'published', 'published_at' => now()])->save();
        $this->patchJson('/mcp/pages/'.$page->id.'/draft', ['title' => 'Denied'])->assertForbidden();
        $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'pages_update_draft', 'arguments' => ['page_id' => $page->id, 'title' => 'Denied']]])->assertOk()->assertJsonPath('error.code', -32003);
        $this->assertSame('published', $page->fresh()->status);
        $this->patchJson('/mcp/settings/site_name', ['value' => 'Denied'])->assertForbidden();
    }

    public function test_key_owner_cannot_revoke_another_users_key(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'editor']);
        McpApiKey::issue($owner, 'Owned', 'full', null);
        $this->actingAs($other)->delete(route('admin.claude-mcp.api-keys.revoke', $owner->mcpApiKeys()->first()))->assertForbidden();
    }
}
