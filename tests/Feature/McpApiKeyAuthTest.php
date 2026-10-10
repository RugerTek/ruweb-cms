<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpApiKeyAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_generate_and_revoke_mcp_api_key_from_claude_mcp(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $generateResponse = $this->actingAs($user)->post(route('admin.claude-mcp.api-key.generate'));

        $generateResponse->assertRedirect(route('admin.claude-mcp.index'));
        $this->assertSame(1, $user->mcpApiKeys()->whereNull('revoked_at')->count());
        $this->assertNull($user->fresh()->mcp_api_key_hash);

        $revokeResponse = $this->actingAs($user)->delete(route('admin.claude-mcp.api-key.revoke'));

        $revokeResponse->assertRedirect(route('admin.claude-mcp.index'));
        $this->assertNull($user->fresh()->mcp_api_key_hash);
        $this->assertSame(0, $user->mcpApiKeys()->whereNull('revoked_at')->count());
    }

    public function test_mcp_endpoint_requires_valid_api_key(): void
    {
        $response = $this->getJson('/mcp/pages');

        $response->assertUnauthorized();
    }

    public function test_mcp_endpoint_authenticates_with_user_api_key(): void
    {
        $user = User::factory()->create(['role' => 'editor']);
        $apiKey = $user->generateMcpApiKey();

        Page::create([
            'title' => 'Home',
            'slug' => 'home',
            'template_key' => 'home',
            'status' => 'draft',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$apiKey)
            ->getJson('/mcp/pages');

        $response->assertOk();
        $response->assertJsonPath('data.0.slug', 'home');
        $this->assertNotNull($user->fresh()->mcp_api_key_last_used_at);
    }
}
