<?php

namespace Tests\Feature;

use App\Models\McpApiKey;
use App\Models\User;
use App\Services\Security\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountSecurityNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function assertSecurityPanelVisible($response): void
    {
        $response->assertOk()->assertSee('data-tab="security" style=""', false)
            ->assertSee('data-tab="information" style="display:none"', false);
    }

    #[DataProvider('adminProfiles')]
    public function test_enrollment_returns_to_visible_security_panel_and_displays_recovery_codes_once(string $profile): void
    {
        $this->useAdminProfile($profile);
        $this->travelTo(now()->startOfMinute());
        $user = User::factory()->create(['role' => 'admin']);
        $profile = route('admin.profile.index', ['tab' => 'security']);
        $this->actingAs($user)->from(route('admin.profile.index'))
            ->post(route('admin.two-factor.enable'), ['current_password' => 'password'])->assertRedirect($profile);
        $this->assertSecurityPanelVisible($this->get($profile));
        $user->refresh();
        $code = app(Totp::class)->code($user->two_factor_secret, intdiv(now()->timestamp, 30));
        $this->post(route('admin.two-factor.confirm'), ['code' => $code])->assertRedirect($profile);
        $recoveryCodes = session('recovery_codes');
        $this->assertCount(8, $recoveryCodes);
        $response = $this->get($profile);
        $this->assertSecurityPanelVisible($response);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        foreach ($recoveryCodes as $recoveryCode) {
            $response->assertSee($recoveryCode);
        }
        $this->get($profile)->assertDontSee($recoveryCodes[0]);
    }

    public function test_failed_security_form_keeps_its_panel_visible_without_a_query_string(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->from(route('admin.profile.index'))
            ->post(route('admin.two-factor.enable'), ['current_password' => 'wrong', '_profile_tab' => 'security'])
            ->assertSessionHasErrors('current_password');
        $this->assertSecurityPanelVisible($this->get(route('admin.profile.index')));
        $this->assertFalse($user->fresh()->hasTwoFactor());
        $this->assertNull($user->fresh()->two_factor_secret);
    }


    public function test_failed_key_creation_preserves_permissions_and_expiry_without_issuing_a_key(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $expiry = now()->addDay()->format('Y-m-d\TH:i');
        $this->actingAs($user)->from(route('admin.claude-mcp.index'))
            ->post(route('admin.claude-mcp.api-key.generate'), ['name' => str_repeat('x', 101),
                'scope' => 'draft-only', 'expires_at' => $expiry])->assertSessionHasErrors('name');
        $this->get(route('admin.claude-mcp.index'))->assertOk()
            ->assertSee('value="draft-only" selected', false)->assertSee('value="'.$expiry.'"', false);
        $this->assertSame(0, $user->mcpApiKeys()->count());
    }

    public function test_key_list_marks_expired_credentials_and_does_not_display_stored_secrets(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $key = McpApiKey::issue($user, 'Expired integration', 'read-only', now()->subMinute()->toDateTimeString());
        $row = $user->mcpApiKeys()->firstOrFail();
        $this->actingAs($user)->get(route('admin.claude-mcp.index'))->assertOk()
            ->assertSee('Expired integration')->assertSee(__('security.expired'))
            ->assertDontSee($key)->assertDontSee($row->key_hash)
            ->assertDontSee(route('admin.claude-mcp.api-keys.revoke', $row));
        $this->withToken($key)->getJson('/mcp/pages')->assertUnauthorized();
    }

    public function test_new_key_is_displayed_once_with_browser_storage_disabled(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->post(route('admin.claude-mcp.api-key.generate'), ['name' => 'One-time key', 'scope' => 'read-only'])
            ->assertRedirect(route('admin.claude-mcp.index'));
        $plainKey = session('new_mcp_api_key');
        $this->assertNotEmpty($plainKey);
        $response = $this->get(route('admin.claude-mcp.index'))->assertOk()->assertSee($plainKey);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->get(route('admin.claude-mcp.index'))->assertOk()->assertDontSee($plainKey);
        $this->assertSame(hash('sha256', $plainKey), $user->mcpApiKeys()->firstOrFail()->key_hash);
    }
}
