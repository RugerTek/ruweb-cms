<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CustomCode\CustomCodeOperations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomCodeAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_permission_is_explicit_and_independent_of_profile(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get(route('admin.custom-code.index'))->assertForbidden();
        $user->update(['can_manage_custom_code' => true]);
        foreach (['full', 'essential'] as $profile) {
            config(['cms.admin_profile' => $profile]);
            $this->actingAs($user->fresh())->get(route('admin.custom-code.create'))->assertOk()->assertSee(__('custom_code.warning'));
        }
        $user->update(['role' => 'editor']);
        $this->actingAs($user->fresh())->get(route('admin.custom-code.index'))->assertForbidden();
    }

    public function test_panel_saves_a_proposal_and_activation_is_separate(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'can_manage_custom_code' => true]);
        $this->actingAs($user)->post(route('admin.custom-code.store'), [
            'name' => 'Example', 'type' => 'css', 'code' => 'body {color: blue}',
            'position' => 'head_end', 'sort_order' => 0, 'global' => 1, 'operation_key' => 'panel-create',
        ])->assertRedirect();
        $snippet = app(CustomCodeOperations::class)->list($user)['snippets'][0];
        $this->assertSame('draft', $snippet['state']);
        $this->assertNull($snippet['active_version_id']);
        $this->get(route('admin.custom-code.edit', $snippet['id']))->assertOk()->assertSee('body {color: blue}');
        $this->post(route('admin.custom-code.activate', $snippet['id']), [
            'expected_revision' => $snippet['revision'], 'operation_key' => 'panel-activate',
            'version_id' => $snippet['versions'][0]['id'],
        ])->assertRedirect();
        $this->assertSame('active', app(CustomCodeOperations::class)->get($user, $snippet['id'])['state']);
    }

    public function test_custom_code_key_cannot_be_issued_without_account_permission(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->post(route('admin.claude-mcp.api-key.generate'), ['scope' => 'custom-code'])->assertForbidden();
        $user->update(['can_manage_custom_code' => true]);
        $this->actingAs($user->fresh())->post(route('admin.claude-mcp.api-key.generate'), ['scope' => 'custom-code'])->assertRedirect();
        $this->assertSame('custom-code', $user->mcpApiKeys()->first()->scope);
    }


}
