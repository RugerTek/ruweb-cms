<?php

namespace Tests\Feature;

use App\Models\McpApiKey;
use App\Models\OauthAccessToken;
use App\Models\OauthAuthorizationCode;
use App\Models\User;
use App\Notifications\VerifyPendingEmail;
use App\Services\Security\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function oauthParameters(): array
    {
        config(['oauth.clients' => ['test-client' => ['redirect_uris' => ['https://client.example/callback']]]]);
        return ['response_type' => 'code', 'client_id' => 'test-client', 'redirect_uri' => 'https://client.example/callback',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', 'verifier', true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256'];
    }

    public function test_oauth_code_is_claimed_once_and_bad_verifier_does_not_consume_it(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $params = $this->oauthParameters();
        $code = OauthAuthorizationCode::create($params + ['code' => 'one-use', 'user_id' => $user->id, 'expires_at' => now()->addMinute()]);
        $exchange = ['grant_type' => 'authorization_code', 'code' => $code->code, 'client_id' => 'test-client',
            'redirect_uri' => $params['redirect_uri'], 'code_verifier' => 'bad'];
        $this->postJson('/token', $exchange)->assertStatus(400);
        $this->assertNull($code->fresh()->used_at);
        $exchange['code_verifier'] = 'verifier';
        $response = $this->postJson('/token', $exchange)->assertOk();
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache');
        $this->assertNotNull($code->fresh()->used_at);
        $this->postJson('/token', $exchange)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertSame(1, OauthAccessToken::count());
        $this->withToken($response->json('access_token'))->getJson('/mcp/pages')->assertOk();
        $this->assertNotNull(OauthAccessToken::first()->last_used_at);
    }

    public function test_oauth_login_and_token_endpoints_are_rate_limited(): void
    {
        $params = $this->oauthParameters();
        for ($i = 0; $i < 5; $i++) $this->post('/authorize', $params + ['email' => 'unknown@example.test', 'password' => 'bad'])->assertRedirect();
        $this->post('/authorize', $params + ['email' => 'unknown@example.test', 'password' => 'bad'])->assertStatus(429);
        for ($i = 0; $i < 30; $i++) $this->postJson('/token', [])->assertStatus(422);
        $this->postJson('/token', [])->assertStatus(429);
    }

    public function test_reset_request_has_identical_response_for_known_unknown_and_throttled_account(): void
    {
        Notification::fake();
        $user = User::factory()->create(['role' => 'admin']);
        foreach ([$user->email, 'unknown@example.test', $user->email] as $email) {
            $this->post(route('admin.password.email'), ['email' => $email])->assertRedirect()
                ->assertSessionHas('success', __('security.reset_sent'))->assertSessionHasNoErrors();
        }
        $this->post(route('admin.password.email'), ['email' => 'a@example.test']);
        $this->post(route('admin.password.email'), ['email' => 'b@example.test']);
        $this->post(route('admin.password.email'), ['email' => 'c@example.test'])->assertStatus(429);
    }

    public function test_email_change_requires_password_confirmation_and_signed_one_use_link(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'old@example.test', 'role' => 'admin']);
        $data = ['name' => $user->name, 'email' => 'new@example.test'];
        $this->actingAs($user)->put(route('admin.profile.information.update'), $data)->assertSessionHasErrors(['current_password', 'email_confirmation']);
        $this->assertSame('old@example.test', $user->fresh()->email);
        $data += ['current_password' => 'password', 'email_confirmation' => 'new@example.test'];
        $this->put(route('admin.profile.information.update'), $data)->assertSessionHasNoErrors();
        $this->assertSame('old@example.test', $user->fresh()->email);
        $this->assertSame('new@example.test', $user->fresh()->pending_email);
        $link = null;
        Notification::assertSentOnDemand(VerifyPendingEmail::class, function ($notification, $channels, $recipient) use (&$link) {
            $link = $notification->toMail($recipient)->actionUrl;
            return $recipient->routes['mail'] === 'new@example.test';
        });
        $this->get($link)->assertRedirect(route('admin.login'));
        $this->assertSame('new@example.test', $user->fresh()->email);
        $this->assertNull($user->fresh()->pending_email_token);
        $this->get($link)->assertForbidden();
    }

    public function test_expired_or_tampered_pending_email_link_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->forceFill(['pending_email' => 'new@example.test', 'pending_email_token' => hash('sha256', 'secret'),
            'pending_email_expires_at' => now()->subMinute()])->save();
        $link = URL::temporarySignedRoute('admin.profile.email.verify', now()->addMinute(), ['user' => $user->id, 'token' => 'secret']);
        $this->get($link)->assertForbidden();
        $this->get(str_replace('secret', 'tampered', $link))->assertForbidden();
    }

    public function test_password_recovery_revokes_keys_oauth_codes_and_sessions(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $legacy = $user->generateMcpApiKey();
        $named = McpApiKey::issue($user, 'Integration', 'full', null);
        $access = OauthAccessToken::create(['user_id' => $user->id, 'token' => hash('sha256', 'token'), 'client_id' => 'client', 'expires_at' => now()->addHour()]);
        DB::table('admin_sessions')->insert(['id' => 'other', 'user_id' => $user->id, 'last_active_at' => now()]);
        $token = Password::broker()->createToken($user);
        $this->post(route('admin.password.update'), ['email' => $user->email, 'token' => $token,
            'password' => 'new-safe-password', 'password_confirmation' => 'new-safe-password'])->assertRedirect(route('admin.login'));
        $this->assertNotNull($access->fresh()->revoked_at);
        $this->assertNull($user->fresh()->mcp_api_key_hash);
        $this->assertNotNull(DB::table('admin_sessions')->where('id', 'other')->value('revoked_at'));
        $this->withToken($legacy)->getJson('/mcp/pages')->assertUnauthorized();
        $this->withToken($named)->getJson('/mcp/pages')->assertUnauthorized();
    }

    public function test_password_change_keeps_current_session_but_invalidates_stale_session(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->put(route('admin.profile.password.update'), ['current_password' => 'password',
            'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertSessionHasNoErrors();
        $this->get(route('admin.profile.index'))->assertOk();
        $this->withSession(['security_version' => 0])->get(route('admin.profile.index'))->assertRedirect(route('admin.login'));
        $this->assertGuest();
    }

    public function test_totp_matches_rfc_vector_and_cannot_be_replayed(): void
    {
        $totp = app(Totp::class);
        // RFC 6238 SHA-1 vector: 94287082 at Unix 59, truncated to six digits.
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('287082', $totp->code($secret, 1));
        $this->assertSame('081804', $totp->code($secret, intdiv(1111111109, 30)));
        $user = User::factory()->create(['role' => 'admin']);
        $user->forceFill(['two_factor_secret' => $totp->secret(), 'two_factor_confirmed_at' => now()])->save();
        $code = $totp->code($user->two_factor_secret, intdiv(now()->timestamp, 30));
        $this->assertTrue($totp->verify($user, $code));
        $this->assertFalse($totp->verify($user, $code));
        $codes = $totp->recoveryCodes($user);
        $this->assertTrue($totp->verify($user, $codes[0]));
        $this->assertFalse($totp->verify($user, $codes[0]));
        $this->assertNotSame($user->two_factor_secret, DB::table('users')->where('id', $user->id)->value('two_factor_secret'));
    }

    public function test_two_factor_enrollment_and_login_challenge_are_complete(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $totp = app(Totp::class);
        $this->actingAs($user)->post(route('admin.two-factor.enable'), ['current_password' => 'password'])->assertRedirect();
        $user->refresh();
        $this->assertFalse($user->hasTwoFactor());
        $code = $totp->code($user->two_factor_secret, intdiv(now()->timestamp, 30));
        $this->post(route('admin.two-factor.confirm'), ['code' => $code])->assertSessionHas('recovery_codes');
        $this->assertTrue($user->fresh()->hasTwoFactor());
        $this->get(route('admin.profile.index'))->assertOk()->assertSee(__('security.regenerate_recovery'));
        $this->post(route('admin.logout'));
        $this->post(route('admin.login'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('admin.two-factor.challenge'));
        $this->assertGuest();
        $this->post(route('admin.two-factor.verify'), ['code' => 'bad'])->assertSessionHasErrors('code');
        $this->travel(31)->seconds();
        $code = $totp->code($user->two_factor_secret, intdiv(now()->timestamp, 30));
        $this->post(route('admin.two-factor.verify'), ['code' => $code])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_oauth_requires_second_factor_and_revoked_session_cannot_auto_authorize(): void
    {
        $totp = app(Totp::class);
        $user = User::factory()->create(['role' => 'admin']);
        $user->forceFill(['two_factor_secret' => $totp->secret(), 'two_factor_confirmed_at' => now()])->save();
        $params = $this->oauthParameters();
        $this->post('/authorize', $params + ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('code');
        $this->assertSame(0, OauthAuthorizationCode::count());
        $this->post('/authorize', $params + ['email' => $user->email, 'password' => 'password',
            'code' => $totp->code($user->two_factor_secret, intdiv(now()->timestamp, 30))])->assertRedirect();
        $this->assertSame(1, OauthAuthorizationCode::count());
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        $this->actingAs($user)->get(route('admin.profile.index'))->assertOk();
        $sessionId = session()->getId();
        DB::table('admin_sessions')->where('user_id', $user->id)->update(['revoked_at' => now()]);
        $this->withCookie(config('session.cookie'), $sessionId)->get('/authorize?'.http_build_query($params))->assertOk();
        $this->assertSame(1, OauthAuthorizationCode::count());
    }

    public function test_two_factor_challenge_is_rate_limited(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->forceFill(['two_factor_secret' => app(Totp::class)->secret(), 'two_factor_confirmed_at' => now()])->save();
        $this->withSession(['two_factor_user' => $user->id, 'two_factor_expires' => now()->addMinute()->timestamp, 'two_factor_version' => 0]);
        for ($i = 0; $i < 5; $i++) $this->post(route('admin.two-factor.verify'), ['code' => 'bad'])->assertSessionHasErrors('code');
        $this->post(route('admin.two-factor.verify'), ['code' => 'bad'])->assertStatus(429);
    }
    public function test_password_change_cancels_pending_email_verification(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->forceFill(['pending_email' => 'attacker@example.test', 'pending_email_token' => hash('sha256', 'pending'),
            'pending_email_expires_at' => now()->addHour()])->save();
        $link = URL::temporarySignedRoute('admin.profile.email.verify', now()->addHour(), ['user' => $user->id, 'token' => 'pending']);
        $this->actingAs($user)->put(route('admin.profile.password.update'), ['current_password' => 'password',
            'password' => 'replacement-password', 'password_confirmation' => 'replacement-password'])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->pending_email_token);
        $this->get($link)->assertForbidden();
    }

    public function test_administrator_password_reset_revokes_existing_credentials(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'editor']);
        $key = McpApiKey::issue($user, 'Old integration', 'full', null);
        $this->actingAs($admin)->put(route('admin.users.update', $user), ['name' => $user->name, 'email' => $user->email,
            'role' => 'editor', 'password' => 'replacement-password', 'password_confirmation' => 'replacement-password'])->assertSessionHasNoErrors();
        $this->withToken($key)->getJson('/mcp/pages')->assertUnauthorized();
        $this->assertGreaterThan(0, $user->fresh()->security_version);
    }

    public function test_individual_session_and_oauth_connection_revocation_are_owner_scoped(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'editor']);
        DB::table('admin_sessions')->insert(['id' => 'owned-session', 'user_id' => $user->id, 'last_active_at' => now()]);
        DB::table('admin_sessions')->insert(['id' => 'other-session', 'user_id' => $other->id, 'last_active_at' => now()]);
        $connection = OauthAccessToken::create(['user_id' => $user->id, 'token' => hash('sha256', 'own'), 'client_id' => 'client', 'expires_at' => now()->addHour()]);
        $foreign = OauthAccessToken::create(['user_id' => $other->id, 'token' => hash('sha256', 'foreign'), 'client_id' => 'client', 'expires_at' => now()->addHour()]);
        $this->actingAs($user)->delete(route('admin.profile.sessions.destroy', 'other-session'), ['current_password' => 'password'])->assertNotFound();
        $this->delete(route('admin.profile.sessions.destroy', 'owned-session'), ['current_password' => 'password'])->assertRedirect();
        $this->delete(route('admin.profile.connections.destroy', $foreign), ['current_password' => 'password'])->assertForbidden();
        $this->delete(route('admin.profile.connections.destroy', $connection), ['current_password' => 'password'])->assertRedirect();
        $this->assertNotNull($connection->fresh()->revoked_at);
        $this->assertNull($foreign->fresh()->revoked_at);
        $this->assertNotNull(DB::table('admin_sessions')->where('id', 'owned-session')->value('revoked_at'));
    }

    public function test_two_factor_recovery_login_disable_and_expired_challenge(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $totp = app(Totp::class);
        $user->forceFill(['two_factor_secret' => $totp->secret(), 'two_factor_confirmed_at' => now()])->save();
        $codes = $totp->recoveryCodes($user);
        $this->withSession(['two_factor_user' => $user->id, 'two_factor_expires' => now()->subMinute()->timestamp, 'two_factor_version' => 0])
            ->post(route('admin.two-factor.verify'), ['code' => $codes[0]])->assertSessionHasErrors('code');
        $this->withSession(['two_factor_expires' => now()->addMinute()->timestamp])->post(route('admin.two-factor.verify'), ['code' => $codes[0]])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->delete(route('admin.two-factor.disable'), ['current_password' => 'password', 'code' => $codes[1]])->assertRedirect();
        $this->assertFalse($user->fresh()->hasTwoFactor());
        $this->get(route('admin.profile.index'))->assertOk();
    }

}
