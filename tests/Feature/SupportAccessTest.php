<?php

namespace Tests\Feature;

use App\Models\McpApiKey;
use App\Models\NotFoundHit;
use App\Models\SupportAudit;
use App\Models\SupportSession;
use App\Models\User;
use App\Services\SupportAccess\SupportActivitySummary;
use App\Services\SupportAccess\SupportPassIssuer;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** «Acceso de soporte RügerTek» (docs/operations/support-access.md). */
class SupportAccessTest extends TestCase
{
    use RefreshDatabase;

    private const AUDIENCE = 'http://localhost:8000';

    private string $secretKey;

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();
        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        $this->publicKey = sodium_crypto_sign_publickey($pair);
        config([
            'app.url' => self::AUDIENCE,
            'cms.support_access.public_keys' => base64_encode($this->publicKey),
            'cms.support_access.issuer' => 'ruweb-cloud',
            'cms.support_access.operator_domain' => 'rugertek.com',
        ]);
    }

    private function pass(array $overrides = [], ?string $secretKey = null, string $email = 'ana@rugertek.com', string $role = 'operacion', ?int $ttl = null): string
    {
        return SupportPassIssuer::issue($secretKey ?? $this->secretKey, self::AUDIENCE, 'ruweb-cloud', $email, 'Ana', $role, $ttl, $overrides);
    }

    private function enter(string $pass): TestResponse
    {
        return $this->get('/_ruweb/support/enter?t='.$pass);
    }

    private function enterAsSupport(array $overrides = [], ?int $ttl = null): User
    {
        $this->enter($this->pass($overrides, ttl: $ttl))->assertRedirect(route('admin.dashboard'));

        return User::query()->where('email', 'ana@rugertek.com')->firstOrFail();
    }

    public function test_a_valid_pass_opens_a_support_session_in_the_admin(): void
    {
        $response = $this->enter($this->pass());

        $response->assertRedirect(route('admin.dashboard'));
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $user = User::query()->where('email', 'ana@rugertek.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->is_support);
        $this->assertTrue($user->isAdmin());
        $this->assertSame('Soporte RügerTek – Ana', $user->name);

        $session = SupportSession::query()->sole();
        $this->assertSame($user->id, $session->user_id);
        $this->assertSame('ana@rugertek.com', $session->operator_email);
        $this->assertSame('operacion', $session->role);
        $this->assertSame('ruweb-cloud', $session->issuer);
        $this->assertEqualsWithDelta(7200, $session->started_at->diffInSeconds($session->expires_at), 2);

        $this->get(route('admin.dashboard'))->assertOk()->assertSee('data-support-bar', false)
            ->assertSee(__('support.bar', ['name' => 'Ana', 'time' => $session->expires_at->timezone(config('app.timezone'))->format('H:i')], 'es'));
    }

    public function test_rejected_passes_answer_a_plain_404_and_never_log_or_store_the_pass(): void
    {
        $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $valid = $this->pass();
        [$payload, $signature] = explode('.', $valid);
        $tamperedPayload = rtrim(strtr(base64_encode(str_replace('"Ana"', '"Eve"', base64_decode(strtr($payload, '-_', '+/')))), '+/', '-_'), '=');
        $now = time();
        $cases = [
            'tampered payload' => $tamperedPayload.'.'.$signature,
            'foreign signing key' => $this->pass(secretKey: $other),
            'other site' => $this->pass(['aud' => 'https://otro-sitio.com.py']),
            'expired' => $this->pass(['iat' => $now - 400, 'exp' => $now - 300]),
            'issued in the future' => $this->pass(['iat' => $now + 300, 'exp' => $now + 360]),
            'lifetime over 120 s' => $this->pass(['iat' => $now, 'exp' => $now + 600]),
            'foreign email' => $this->pass(email: 'ana@gmail.com'),
            'look-alike domain' => $this->pass(email: 'ana@rugertek.com.evil.io'),
            'unknown claim' => $this->pass(['admin' => true]),
            'wrong issuer' => $this->pass(['iss' => 'someone-else']),
            'unknown role' => $this->pass(['role' => 'root']),
            'session too long' => $this->pass(['session_ttl' => 86400]),
            'short jti' => $this->pass(['jti' => 'abc']),
            'malformed' => 'not-a-pass',
            'missing' => '',
        ];
        Log::spy();

        $i = 0;
        foreach ($cases as $label => $pass) {
            // One IP per case: the entry is throttled to 10 attempts a minute (test_the_entry_is_throttled).
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.(++$i)]);
            $this->enter($pass)->assertNotFound();
            $this->assertGuest();
            $this->assertSame(0, User::query()->count(), $label);
        }

        $this->assertSame(0, SupportSession::query()->count());
        $this->assertSame(0, NotFoundHit::query()->count(), 'The 404 monitor must not store the entry URL.');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => $message === 'Support pass rejected.' && isset($context['reason'], $context['ip']));
        $leaks = function (string $message, array $context = []) use ($cases): bool {
            $logged = $message.json_encode($context);
            foreach ($cases as $pass) {
                [$payload] = explode('.', $pass.'.');
                if (strlen($payload) > 20 && str_contains($logged, $payload)) {
                    return true;
                }
            }

            return false;
        };
        foreach (['warning', 'info', 'error', 'debug', 'notice'] as $level) {
            Log::shouldNotHaveReceived($level, $leaks);
        }
    }

    public function test_the_entry_is_throttled(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->enter('not-a-pass')->assertNotFound();
        }

        $this->enter($this->pass())->assertTooManyRequests();
        $this->assertGuest();
    }

    public function test_a_pass_works_only_once(): void
    {
        $pass = $this->pass();
        $this->enter($pass)->assertRedirect(route('admin.dashboard'));
        $this->post(route('admin.support.exit'));

        $this->enter($pass)->assertNotFound();
        $this->assertGuest();
        $this->assertSame(1, SupportSession::query()->count());
    }

    public function test_without_a_public_key_the_feature_is_off(): void
    {
        config(['cms.support_access.public_keys' => null]);
        $this->enter($this->pass())->assertNotFound();

        config(['cms.support_access.public_keys' => 'not base64 !!']);
        $this->enter($this->pass())->assertNotFound();

        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
    }

    public function test_two_public_keys_allow_a_rotation_and_a_third_is_ignored(): void
    {
        $next = sodium_crypto_sign_keypair();
        $third = sodium_crypto_sign_keypair();
        config(['cms.support_access.public_keys' => base64_encode(random_bytes(32)).', '.base64_encode(sodium_crypto_sign_publickey($next)).','.base64_encode(sodium_crypto_sign_publickey($third))]);

        $this->enter($this->pass(secretKey: sodium_crypto_sign_secretkey($third)))->assertNotFound();
        $this->enter($this->pass(secretKey: $this->secretKey))->assertNotFound();
        $this->enter($this->pass(secretKey: sodium_crypto_sign_secretkey($next)))->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated();
    }

    public function test_the_support_account_is_created_once_and_reused(): void
    {
        $this->enter($this->pass())->assertRedirect();
        $this->post(route('admin.support.exit'));
        $this->enter(SupportPassIssuer::issue($this->secretKey, self::AUDIENCE, 'ruweb-cloud', 'ANA@rugertek.com', 'Ana María', 'desarrollo'))->assertRedirect();

        $this->assertSame(1, User::query()->count());
        $user = User::query()->sole();
        $this->assertSame('Soporte RügerTek – Ana María', $user->name);
        $this->assertTrue($user->can_manage_custom_code, 'desarrollo operators can manage custom code');
        $this->assertSame(2, SupportSession::query()->where('user_id', $user->id)->count());
    }

    public function test_an_ordinary_user_with_the_operator_email_is_never_reused(): void
    {
        $client = User::factory()->create(['email' => 'ana@rugertek.com', 'role' => 'editor', 'name' => 'Ana Cliente']);

        $this->enter($this->pass())->assertStatus(409)->assertSee('ana@rugertek.com');

        $this->assertGuest();
        $client->refresh();
        $this->assertFalse($client->is_support);
        $this->assertSame('editor', $client->role);
        $this->assertSame('Ana Cliente', $client->name);
        $this->assertSame(0, SupportSession::query()->count());
    }

    public function test_a_support_account_cannot_sign_in_with_a_password_or_reset_it(): void
    {
        $user = $this->enterAsSupport();
        $this->post(route('admin.support.exit'));
        $user->forceFill(['password' => Hash::make('a-known-password')])->save();

        $this->post(route('admin.login.submit'), ['login' => 'ana@rugertek.com', 'password' => 'a-known-password'])->assertSessionHasErrors('login');
        $this->assertGuest();

        Notification::fake();
        $this->post(route('admin.password.email'), ['email' => 'ana@rugertek.com'])->assertSessionHas('success');
        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $this->assertFalse(Notification::hasSent($user, ResetPassword::class));
    }

    public function test_support_accounts_are_hidden_from_the_clients_user_management(): void
    {
        $support = $this->enterAsSupport();
        $this->post(route('admin.support.exit'));
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk()->assertDontSee('ana@rugertek.com');
        $this->actingAs($admin)->get(route('admin.users.edit', $support))->assertNotFound();
        $this->actingAs($admin)->put(route('admin.users.update', $support), ['name' => 'X', 'email' => 'x@example.com', 'role' => 'editor'])->assertNotFound();
        $this->actingAs($admin)->delete(route('admin.users.destroy', $support))->assertNotFound();
        $this->assertTrue($support->fresh()->is_support);
        $this->assertSame(1, User::query()->clientAccounts()->count());
    }

    public function test_the_support_session_expires_at_its_time_even_with_activity(): void
    {
        $this->enterAsSupport(ttl: 600);
        $this->travel(5)->minutes();
        $this->get(route('admin.dashboard'))->assertOk();
        $this->travel(6)->minutes();

        $this->get(route('admin.dashboard'))->assertForbidden()->assertSee(__('support.ended_title', [], 'es'));
        $this->assertGuest();
        $session = SupportSession::query()->sole();
        $this->assertSame('expired', $session->end_reason);
        $this->get(route('admin.dashboard'))->assertNotFound();
    }

    public function test_leaving_support_mode_closes_the_session(): void
    {
        $this->enterAsSupport();

        $this->post(route('admin.support.exit'))->assertRedirect(route('home'));

        $this->assertGuest();
        $session = SupportSession::query()->sole();
        $this->assertNotNull($session->ended_at);
        $this->assertSame('exit', $session->end_reason);
        $this->get(route('admin.dashboard'))->assertNotFound();
    }

    public function test_logout_also_closes_the_support_session_and_others_cannot_exit_support_mode(): void
    {
        $this->enterAsSupport();
        $this->post(route('admin.logout'));
        $this->assertSame('logout', SupportSession::query()->sole()->end_reason);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.support.exit'))->assertNotFound();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_writes_are_recorded_with_field_names_and_never_values(): void
    {
        $this->enterAsSupport();
        $secret = 'super-secret-value-'.bin2hex(random_bytes(6));

        $this->get(route('admin.settings.index'))->assertOk();
        $this->getJson(route('admin.dashboard'));
        $this->put(route('admin.settings.update'), [
            'settings' => ['site_name' => 'Tienda Nueva', 'smtp_password' => $secret],
            'api_token' => $secret,
            'card' => ['number' => $secret],
        ]);
        $this->post(route('admin.claude-mcp.api-key.generate'), ['password' => $secret])->assertForbidden();

        $audits = SupportAudit::query()->orderBy('id')->get();
        $this->assertSame(['GET', 'PUT', 'POST'], $audits->pluck('method')->all(), 'The background JSON GET is not a page view.');
        $this->assertSame('admin.settings.index', $audits[0]->route_name);
        $update = $audits[1];
        $this->assertSame('admin.settings.update', $update->route_name);
        $this->assertSame('/admin/settings', $update->path);
        $this->assertEqualsCanonicalizing(['settings.site_name', 'settings.smtp_password', 'api_token', 'card.number'], $update->changed_fields);
        $this->assertNotNull($update->status);
        $this->assertSame(403, $audits[2]->status);
        $this->assertSame(['password'], $audits[2]->changed_fields);

        $dump = json_encode(DB::table('support_audit')->get()).json_encode(DB::table('support_sessions')->get());
        $this->assertStringNotContainsString($secret, $dump);
        $this->assertStringNotContainsString('Tienda Nueva', $dump);
    }

    public function test_the_subject_of_a_write_is_recorded_with_its_name(): void
    {
        $this->enterAsSupport();
        $editor = User::factory()->create(['role' => 'editor', 'name' => 'Pedro Editor']);

        $this->delete(route('admin.users.destroy', $editor))->assertRedirect();

        $audit = SupportAudit::query()->where('method', 'DELETE')->sole();
        $this->assertSame('user', $audit->subject_type);
        $this->assertSame((string) $editor->id, $audit->subject_id);
        $this->assertSame('Pedro Editor', $audit->subject_label);
        $this->assertSame('Borró el usuario «Pedro Editor»', app(SupportActivitySummary::class)->describe($audit));
    }

    public function test_the_client_admin_sees_the_activity_and_an_editor_does_not(): void
    {
        $this->enterAsSupport();
        $this->put(route('admin.settings.update'), ['settings' => ['site_name' => 'Otro nombre']]);
        $this->post(route('admin.support.exit'));
        $session = SupportSession::query()->sole();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.support-activity.index'))->assertOk()
            ->assertSee('ana@rugertek.com')->assertSee('Cambió los ajustes del sitio');
        $this->actingAs($admin)->get(route('admin.support-activity.show', $session->id))->assertOk()
            ->assertSee('settings.site_name')->assertDontSee('Otro nombre');

        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->get(route('admin.support-activity.index'))->assertForbidden();
        $this->actingAs($editor)->get(route('admin.support-activity.show', $session->id))->assertForbidden();
    }

    public function test_support_activity_list_mcp_tool(): void
    {
        $this->enterAsSupport();
        $this->get(route('admin.dashboard'))->assertOk();
        $this->put(route('admin.settings.update'), ['settings' => ['site_name' => 'Otro nombre']]);
        $this->post(route('admin.support.exit'));
        $session = SupportSession::query()->sole();
        $admin = User::factory()->create(['role' => 'admin']);
        $rpc = fn (array $args = []) => $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'support_activity_list', 'arguments' => $args]]);

        $this->withToken(McpApiKey::issue($admin, 'Read', 'read-only', null));
        $data = json_decode($rpc()->assertOk()->json('result.content.0.text'), true);
        $this->assertSame('ana@rugertek.com', $data['data'][0]['operator_email']);
        $this->assertSame('exit', $data['data'][0]['end_reason']);
        $change = collect($data['data'][0]['changes'])->firstWhere('route_name', 'admin.settings.update');
        $this->assertSame(['settings.site_name'], $change['changed_fields']);
        $this->assertStringNotContainsString('Otro nombre', json_encode($data));

        $detail = json_decode($rpc(['session_id' => $session->id])->assertOk()->json('result.content.0.text'), true);
        $this->assertContains('page_view', array_column($detail['data']['entries'], 'kind'));
        $this->getJson('/mcp/support-activity')->assertOk()->assertJsonPath('data.0.id', $session->id);

        $editor = User::factory()->create(['role' => 'editor']);
        $this->withToken(McpApiKey::issue($editor, 'Editor', 'full', null));
        $rpc()->assertOk()->assertJsonPath('error.code', -32003);
    }

    public function test_support_accounts_cannot_use_mcp_keys(): void
    {
        $support = $this->enterAsSupport();
        $this->post(route('admin.support.exit'));
        $token = McpApiKey::issue($support, 'Leftover', 'full', null);

        $this->withToken($token)->getJson('/mcp/support-activity')->assertUnauthorized();
    }

    public function test_issue_pass_command_works_locally_and_refuses_in_production(): void
    {
        config(['cms.support_access.dev_private_key' => base64_encode($this->secretKey)]);
        $this->artisan('support:issue-pass', ['email' => 'ana@rugertek.com', '--name' => 'Ana', '--ttl' => 900])
            ->expectsOutputToContain(self::AUDIENCE.'/_ruweb/support/enter?t=')
            ->assertSuccessful();

        $this->app['env'] = 'production';
        $this->artisan('support:issue-pass', ['email' => 'ana@rugertek.com'])->assertFailed();
        $this->artisan('support:keypair')->assertFailed();
    }

    public function test_issue_pass_command_needs_the_development_key(): void
    {
        config(['cms.support_access.dev_private_key' => null]);
        $this->artisan('support:issue-pass', ['email' => 'ana@rugertek.com'])->assertFailed();
        $this->artisan('support:keypair')->expectsOutputToContain('RUWEB_SUPPORT_PUBLIC_KEYS=')->assertSuccessful();
    }

    public function test_prune_keeps_the_retention_period(): void
    {
        $this->enterAsSupport();
        $this->post(route('admin.support.exit'));
        config(['cms.support_access.retention_days' => 30]);

        $this->artisan('support:prune')->assertSuccessful();
        $this->assertSame(1, SupportSession::query()->count());

        $this->travel(31)->days();
        $this->artisan('support:prune')->assertSuccessful();
        $this->assertSame(0, SupportSession::query()->count());
        $this->assertSame(0, SupportAudit::query()->count());
        $this->assertSame(0, DB::table('support_pass_jtis')->count());
        $this->assertSame(1, User::query()->where('is_support', true)->count());
    }
}
