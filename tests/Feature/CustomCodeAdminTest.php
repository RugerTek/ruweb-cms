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
    public function test_oauth_custom_code_requires_consent_and_preserves_credential_scope(): void
    {
        config(['oauth.clients.test-client' => ['redirect_uris' => ['https://client.example/callback']]]);
        $user = User::factory()->create(['role' => 'admin', 'can_manage_custom_code' => true, 'password' => 'test-password']);
        $verifier = 'custom-code-test-verifier';
        $input = ['response_type' => 'code', 'client_id' => 'test-client', 'redirect_uri' => 'https://client.example/callback',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256', 'scope' => 'custom-code'];
        $this->actingAs($user)->get('/authorize?'.http_build_query($input))->assertOk()->assertSee(__('custom_code.consent'));
        $credentials = ['email' => $user->email, 'password' => 'test-password'];
        $this->post('/authorize', $input + $credentials)->assertSessionHasErrors('custom_code_consent');
        $this->assertDatabaseCount('oauth_authorization_codes', 0);
        $this->post('/authorize', $input + $credentials + ['custom_code_consent' => '1'])->assertRedirect();
        $code = \App\Models\OauthAuthorizationCode::first();
        $this->assertSame('custom-code', $code->scope);
        $this->postJson('/token', ['grant_type' => 'authorization_code', 'code' => $code->code,
            'redirect_uri' => $input['redirect_uri'], 'client_id' => 'test-client', 'code_verifier' => $verifier])
            ->assertOk()->assertJsonPath('scope', 'custom-code');
        $this->assertSame('custom-code', \App\Models\OauthAccessToken::first()->scope);
    }

    public function test_oauth_rejects_conflicting_query_and_body_authorization_parameters(): void
    {
        config(['oauth.clients.test-client' => ['redirect_uris' => ['https://client.example/callback']]]);
        $user = User::factory()->create(['role' => 'admin', 'can_manage_custom_code' => true, 'password' => 'test-password']);
        $input = ['response_type'=>'code', 'client_id'=>'test-client', 'redirect_uri'=>'https://client.example/callback', 'code_challenge'=>str_repeat('a',43), 'code_challenge_method'=>'S256', 'scope'=>'custom-code', 'state'=>'original'];
        foreach (['scope'=>'full', 'client_id'=>'other-client', 'redirect_uri'=>'https://other.example/callback', 'code_challenge'=>str_repeat('b',43), 'code_challenge_method'=>'plain', 'response_type'=>'token', 'state'=>'changed'] as $key=>$changed) {
            $this->travel(1)->minutes();
            $this->postJson('/authorize?'.http_build_query($input), array_replace($input, [$key=>$changed]) + ['email'=>$user->email, 'password'=>'test-password'])->assertStatus(400)->assertJsonPath('error','invalid_request');
            $this->assertDatabaseCount('oauth_authorization_codes',0);
        }
    }

}
