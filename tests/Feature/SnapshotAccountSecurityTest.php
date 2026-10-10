<?php

namespace Tests\Feature;

use App\Models\McpApiKey;
use App\Models\User;
use App\Services\Security\Totp;
use App\Services\Snapshots\SnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SnapshotAccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_does_not_transfer_credentials_and_rejects_old_array_session(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['role' => 'admin']);
        $user->forceFill(['two_factor_secret' => app(Totp::class)->secret(), 'two_factor_confirmed_at' => now(),
            'pending_email' => 'pending@example.test', 'pending_email_token' => 'pending-token', 'pending_email_expires_at' => now()->addHour()])->save();
        $key = McpApiKey::issue($user, 'Must not travel', 'full', null);
        $this->actingAs($user)->withSession(['security_version' => 0, 'two_factor_passed' => $user->id])->get(route('admin.profile.index'))->assertOk();
        $archive = app(SnapshotService::class)->create('security-lifecycle');
        app(SnapshotService::class)->restore($archive);
        $restored = $user->fresh();
        $this->assertNull($restored->two_factor_secret);
        $this->assertNull($restored->two_factor_recovery_codes);
        $this->assertNull($restored->pending_email_token);
        $this->assertNull($restored->remember_token);
        $this->assertGreaterThan(0, $restored->security_version);
        $this->assertDatabaseCount('mcp_api_keys', 0);
        $this->assertDatabaseCount('admin_sessions', 0);
        $this->withToken($key)->getJson('/mcp/pages')->assertUnauthorized();
        // Refresh the authenticated instance to simulate rehydration on the next request.
        $this->actingAs($restored)->get(route('admin.profile.index'))->assertRedirect(route('admin.login'));
        $this->assertGuest();
    }
}
