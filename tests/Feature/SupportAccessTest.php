<?php

namespace Tests\Feature;

use App\Mcp\ToolRegistry;
use App\Models\McpApiKey;
use App\Models\NotFoundHit;
use App\Models\SupportAudit;
use App\Models\SupportSession;
use App\Models\User;
use App\Services\SupportAccess\Es256Signature;
use App\Services\SupportAccess\SupportAccessKeys;
use App\Services\SupportAccess\SupportActivitySummary;
use App\Services\SupportAccess\SupportNotices;
use App\Services\SupportAccess\SupportPassIssuer;
use App\Services\SupportAccess\SupportPassVerifier;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

/** «Acceso de soporte RügerTek» (docs/operations/support-access.md). */
class SupportAccessTest extends TestCase
{
    use RefreshDatabase;

    private const AUDIENCE = 'http://localhost:8000';

    private const REASON = 'Revisar por qué no sincroniza el catálogo de Meta';

    /** One P-256 key per test class run: generating keys is the slow part of these tests. */
    private static ?OpenSSLAsymmetricKey $sharedKey = null;

    private OpenSSLAsymmetricKey $privateKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->privateKey = self::$sharedKey ??= SupportPassIssuer::generateKey();
        config([
            'app.url' => self::AUDIENCE,
            // The JWK form, as Azure Key Vault exports the console key.
            'cms.support_access.public_keys' => SupportAccessKeys::publicJwk($this->privateKey),
            'cms.support_access.issuer' => 'ruweb-cloud',
            'cms.support_access.operator_domain' => 'rugertek.com',
        ]);
    }

    private function pass(array $overrides = [], ?OpenSSLAsymmetricKey $key = null, string $email = 'ana@rugertek.com', string $role = 'operacion', ?int $ttl = null, ?string $ticket = null): string
    {
        return SupportPassIssuer::issue($key ?? $this->privateKey, self::AUDIENCE, 'ruweb-cloud', $email, 'Ana', self::REASON, $role, $ttl, $ticket, $overrides);
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
        $this->assertSame(self::REASON, $session->reason);
        $this->assertNull($session->ticket);
        $this->assertEqualsWithDelta(7200, $session->started_at->diffInSeconds($session->expires_at), 2);

        $this->get(route('admin.dashboard'))->assertOk()->assertSee('data-support-bar', false)
            ->assertSee(__('support.bar', ['name' => 'Ana', 'time' => $session->expires_at->timezone(config('app.timezone'))->format('H:i')], 'es'));
    }

    public function test_rejected_passes_answer_a_plain_404_and_never_log_or_store_the_pass(): void
    {
        $other = SupportPassIssuer::generateKey();
        $valid = $this->pass();
        [$payload, $signature] = explode('.', $valid);
        $tamperedPayload = rtrim(strtr(base64_encode(str_replace('"Ana"', '"Eve"', base64_decode(strtr($payload, '-_', '+/')))), '+/', '-_'), '=');
        $now = time();
        $cases = [
            'tampered payload' => $tamperedPayload.'.'.$signature,
            'foreign signing key' => $this->pass(key: $other),
            'other site' => $this->pass(['aud' => 'https://otro-sitio.com.py']),
            'expired' => $this->pass(['iat' => $now - 400, 'exp' => $now - 380]),
            'issued in the future' => $this->pass(['iat' => $now + 300, 'exp' => $now + 320]),
            'lifetime over 30 s' => $this->pass(['iat' => $now, 'exp' => $now + 31]),
            'v1 pass' => $this->pass(['v' => 1]),
            'missing reason' => $this->pass(['reason' => null]),
            'short reason' => $this->pass(['reason' => 'Revisar']),
            'long reason' => $this->pass(['reason' => str_repeat('Revisar el sitio. ', 30)]),
            'html in reason' => $this->pass(['reason' => '<b>Revisar</b> el catálogo de Meta']),
            'reason not a string' => $this->pass(['reason' => ['Revisar el catálogo de Meta']]),
            'bad ticket' => $this->pass(['ticket' => '<script>alert(1)</script>']),
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

        // A v1 (Ed25519) public key left in .env is not a valid key any more: the feature stays off.
        config(['cms.support_access.public_keys' => base64_encode(random_bytes(32))]);
        $this->enter($this->pass())->assertNotFound();

        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
    }

    public function test_two_public_keys_allow_a_rotation_and_a_third_is_ignored(): void
    {
        $next = SupportPassIssuer::generateKey();
        $third = SupportPassIssuer::generateKey();
        // Current key as a JWK (Key Vault `key show`), next one as a PEM body (Key Vault `key download -e PEM`).
        config(['cms.support_access.public_keys' => SupportAccessKeys::publicJwk($this->privateKey).', '.SupportAccessKeys::publicPemBody($next).','.SupportAccessKeys::publicJwk($third)]);

        $this->enter($this->pass(key: $third))->assertNotFound();
        $this->enter($this->pass())->assertRedirect(route('admin.dashboard'));
        $this->post(route('admin.support.exit'));
        $this->enter($this->pass(key: $next))->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated();

        // After the rotation only the next key remains: passes of the old one stop working at once.
        $this->post(route('admin.support.exit'));
        config(['cms.support_access.public_keys' => SupportAccessKeys::publicPemBody($next)]);
        $this->enter($this->pass())->assertNotFound();
        $this->enter($this->pass(key: $next))->assertRedirect(route('admin.dashboard'));
    }

    public function test_public_keys_are_accepted_as_jwk_or_pem_and_nothing_else(): void
    {
        $details = openssl_pkey_get_details($this->privateKey);
        $jwk = json_decode(SupportAccessKeys::publicJwk($this->privateKey), true);
        $armored = $details['key'];
        $accepted = [
            'jwk' => json_encode($jwk),
            'jwk as az keyvault key show prints it' => json_encode(['kid' => 'https://kv.vault.azure.net/keys/ruweb-support/abc', 'kty' => 'EC-HSM', 'key_ops' => ['sign', 'verify'], 'crv' => 'P-256', 'x' => $jwk['x'], 'y' => $jwk['y']]),
            'armored pem' => $armored,
            'pem with \\n escapes' => str_replace("\n", '\\n', trim($armored)),
            'pem body' => SupportAccessKeys::publicPemBody($this->privateKey),
        ];
        foreach ($accepted as $label => $value) {
            $this->assertNotNull(SupportAccessKeys::parsePublicKey($value), $label);
        }

        // An empty openssl.cnf: PHP builds for Windows ship without a default one (see SupportPassIssuer::generateKey()).
        $config = tempnam(sys_get_temp_dir(), 'ossl');
        $rsa = openssl_pkey_get_details(openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048, 'config' => $config]))['key'];
        @unlink($config);
        $rejected = [
            'ed25519 public key (v1)' => base64_encode(random_bytes(32)),
            'jwk of another curve' => json_encode(['kty' => 'EC', 'crv' => 'P-384', 'x' => $jwk['x'], 'y' => $jwk['y']]),
            'jwk with a private part' => json_encode($jwk + ['d' => $jwk['x']]),
            'jwk point off the curve' => json_encode(['kty' => 'EC', 'crv' => 'P-256', 'x' => $jwk['x'], 'y' => $jwk['x']]),
            'private key pem' => '-----BEGIN PRIVATE KEY-----'.SupportPassIssuer::privateKeyBody($this->privateKey).'-----END PRIVATE KEY-----',
            'garbage' => 'not a key',
            'rsa key' => $rsa,
        ];
        foreach ($rejected as $label => $value) {
            $this->assertNull(SupportAccessKeys::parsePublicKey($value), $label);
        }
        $this->assertSame(['{"a":1,"b":2}', 'abc'], SupportAccessKeys::splitKeys(' {"a":1,"b":2} , abc ,'));
    }

    public function test_es256_signatures_use_the_key_vault_r_s_format(): void
    {
        $pass = $this->pass();
        [$payload, $signature] = explode('.', $pass);
        $raw = SupportAccessKeys::base64UrlDecode($signature);
        $this->assertSame(64, strlen($raw), 'JWS / Key Vault ES256 signatures are r||s, 64 bytes');
        $der = Es256Signature::rawToDer($raw);
        $this->assertSame(1, openssl_verify(SupportPassVerifier::SIGNING_CONTEXT.$payload, $der, openssl_pkey_get_details($this->privateKey)['key'], OPENSSL_ALGO_SHA256));
        $this->assertSame($raw, Es256Signature::derToRaw($der));

        // High bit set and leading zeros round-trip; a zero r or s is not a signature.
        $edge = "\0\0".str_repeat("\x81", 30).str_repeat("\xff", 32);
        $this->assertSame($edge, Es256Signature::derToRaw(Es256Signature::rawToDer($edge)));
        $this->assertNull(Es256Signature::rawToDer(str_repeat("\0", 32).str_repeat("\x01", 32)));
        $this->assertNull(Es256Signature::rawToDer(str_repeat("\x01", 63)));

        // The DER form itself (what a non-JWS signer would send) is not accepted: only the 64-byte form.
        $this->enter($payload.'.'.SupportAccessKeys::base64UrlEncode($der))->assertNotFound();
        $this->enter($pass)->assertRedirect(route('admin.dashboard'));
    }

    public function test_the_pass_expires_in_seconds_with_a_documented_clock_tolerance(): void
    {
        $this->freezeTime();
        $now = Carbon::now()->getTimestamp();
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.1']);
        $this->enter($this->pass(['iat' => $now, 'exp' => $now + 31]))->assertNotFound();
        // Exactly 30 s is fine, and so is a console clock 25 s behind or ahead.
        $this->enter($this->pass(['iat' => $now, 'exp' => $now + 30]))->assertRedirect(route('admin.dashboard'));
        $this->post(route('admin.support.exit'));
        $this->enter($this->pass(['iat' => $now - 50, 'exp' => $now - 25]))->assertRedirect(route('admin.dashboard'));
        $this->post(route('admin.support.exit'));
        $this->enter($this->pass(['iat' => $now + 25, 'exp' => $now + 50]))->assertRedirect(route('admin.dashboard'));
        $this->post(route('admin.support.exit'));
        // Beyond the tolerance (exp more than 30 s ago, iat more than 30 s ahead): rejected.
        $this->enter($this->pass(['iat' => $now - 61, 'exp' => $now - 31]))->assertNotFound();
        $this->enter($this->pass(['iat' => $now + 31, 'exp' => $now + 50]))->assertNotFound();
        $this->assertSame(3, SupportSession::query()->count());
    }

    public function test_the_support_account_is_created_once_and_reused(): void
    {
        $this->enter($this->pass())->assertRedirect();
        $this->post(route('admin.support.exit'));
        $this->enter(SupportPassIssuer::issue($this->privateKey, self::AUDIENCE, 'ruweb-cloud', 'ANA@rugertek.com', 'Ana María', self::REASON, 'desarrollo'))->assertRedirect();

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
        config(['cms.support_access.dev_private_key' => SupportPassIssuer::privateKeyBody($this->privateKey)]);
        $this->artisan('support:issue-pass', ['email' => 'ana@rugertek.com', '--name' => 'Ana', '--ttl' => 900, '--reason' => self::REASON, '--ticket' => 'TK-1234'])
            ->expectsOutputToContain(self::AUDIENCE.'/_ruweb/support/enter?t=')
            ->assertSuccessful();
        $this->artisan('support:issue-pass', ['email' => 'ana@rugertek.com'])
            ->expectsOutputToContain('--reason is required')
            ->assertFailed();
        $this->artisan('support:issue-pass', ['email' => 'ana@rugertek.com', '--reason' => self::REASON, '--ticket' => 'no spaces allowed'])->assertFailed();

        $this->app['env'] = 'production';
        $this->artisan('support:issue-pass', ['email' => 'ana@rugertek.com'])->assertFailed();
        $this->artisan('support:keypair')->assertFailed();
    }

    public function test_issue_pass_command_needs_the_development_key(): void
    {
        config(['cms.support_access.dev_private_key' => null]);
        $this->artisan('support:issue-pass', ['email' => 'ana@rugertek.com'])->assertFailed();
        $this->artisan('support:keypair')->expectsOutputToContain('RUWEB_SUPPORT_PUBLIC_KEYS=\'{"kty":"EC","crv":"P-256"')->assertSuccessful();
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

    public function test_the_reason_and_ticket_are_stored_and_shown_to_the_client(): void
    {
        $this->enter($this->pass(ticket: 'TK-1234'))->assertRedirect(route('admin.dashboard'));
        $this->post(route('admin.support.exit'));
        $session = SupportSession::query()->sole();
        $this->assertSame(self::REASON, $session->reason);
        $this->assertSame('TK-1234', $session->ticket);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.support-activity.index'))->assertOk()
            ->assertSee('Motivo: '.self::REASON)->assertSee('Ticket TK-1234');
        $this->actingAs($admin)->get(route('admin.support-activity.show', $session->id))->assertOk()
            ->assertSee(self::REASON)->assertSee('Ticket TK-1234');
    }

    public function test_line_breaks_in_the_reason_are_collapsed(): void
    {
        $this->enter($this->pass(['reason' => "Revisar el catálogo\n  de Meta\tque no sincroniza"]))->assertRedirect();

        $this->assertSame('Revisar el catálogo de Meta que no sincroniza', SupportSession::query()->sole()->reason);
    }

    public function test_a_client_admin_turns_support_access_off_and_on(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Laura Dueña']);
        $this->actingAs($admin)->get(route('admin.support-activity.index'))->assertOk()
            ->assertSee('Permitir acceso de soporte de RügerTek')->assertSee('Activado')
            ->assertSee(route('admin.support-activity.access'), false);

        // Without the confirmation nothing changes.
        $this->actingAs($admin)->post(route('admin.support-activity.access'), ['allowed' => 0])->assertSessionHasErrors('confirm');
        $this->assertSame(0, DB::table('support_access_changes')->count());

        $this->actingAs($admin)->post(route('admin.support-activity.access'), ['allowed' => 0, 'confirm' => 1])
            ->assertRedirect(route('admin.support-activity.index'))->assertSessionHas('success');
        $change = DB::table('support_access_changes')->sole();
        $this->assertFalse((bool) $change->allowed);
        $this->assertSame($admin->id, (int) $change->user_id);
        $this->assertSame('Laura Dueña', $change->user_name);
        $this->actingAs($admin)->get(route('admin.support-activity.index'))->assertOk()
            ->assertSee('Desactivado')->assertSee('Lo desactivó Laura Dueña el');

        auth()->guard('web')->logout();
        $this->enter($this->pass())->assertNotFound();
        $this->assertGuest();
        $this->assertSame(0, SupportSession::query()->count());

        $this->actingAs($admin)->post(route('admin.support-activity.access'), ['allowed' => 1, 'confirm' => 1])->assertRedirect();
        auth()->guard('web')->logout();
        $this->enter($this->pass())->assertRedirect(route('admin.dashboard'));
    }

    public function test_turning_it_off_closes_an_open_support_session_on_its_next_request(): void
    {
        $this->enterAsSupport();
        $this->get(route('admin.dashboard'))->assertOk();
        // Another administrator, in another browser, turns it off.
        DB::table('support_access_changes')->insert(['allowed' => false, 'user_id' => null, 'user_name' => 'Laura', 'changed_at' => now()]);

        $this->get(route('admin.settings.index'))->assertForbidden()
            ->assertSee(__('support.ended_disabled_body', [], 'es'));
        $this->assertGuest();
        $session = SupportSession::query()->sole();
        $this->assertSame('disabled', $session->end_reason);
        $this->assertNotNull($session->ended_at);
    }

    public function test_turning_it_off_from_the_panel_closes_open_sessions_at_once(): void
    {
        $this->enterAsSupport();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.support-activity.access'), ['allowed' => 0, 'confirm' => 1])
            ->assertSessionHas('success', trans_choice('support.access.turned_off', 1, ['count' => 1], 'es'));

        $this->assertSame('disabled', SupportSession::query()->sole()->end_reason);
        $this->actingAs($admin)->get(route('admin.support-activity.show', SupportSession::query()->sole()->id))->assertOk()->assertSee('Se desactivó el acceso de soporte', false);
    }

    public function test_a_support_account_cannot_change_the_switch(): void
    {
        $this->enterAsSupport();

        $this->get(route('admin.support-activity.index'))->assertOk()
            ->assertSee('Solo un administrador del sitio puede cambiar esto')
            ->assertDontSee(route('admin.support-activity.access'), false);
        $this->post(route('admin.support-activity.access'), ['allowed' => 0, 'confirm' => 1])->assertForbidden();
        $this->post(route('admin.support-activity.access'), ['allowed' => 1, 'confirm' => 1])->assertForbidden();

        $this->assertSame(0, DB::table('support_access_changes')->count());
        $attempt = SupportAudit::query()->where('route_name', 'admin.support-activity.access')->firstOrFail();
        $this->assertSame(403, $attempt->status);
        $this->assertAuthenticated();
    }

    public function test_an_editor_cannot_change_the_switch(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);

        $this->actingAs($editor)->post(route('admin.support-activity.access'), ['allowed' => 0, 'confirm' => 1])->assertForbidden();
        $this->assertSame(0, DB::table('support_access_changes')->count());
    }

    public function test_the_mcp_tool_reports_reason_and_the_switch_but_cannot_change_it(): void
    {
        $this->enter($this->pass(ticket: 'TK-9'))->assertRedirect();
        $this->post(route('admin.support.exit'));
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Laura']);
        $this->actingAs($admin)->post(route('admin.support-activity.access'), ['allowed' => 0, 'confirm' => 1]);

        auth()->guard('web')->logout();
        $data = $this->withToken(McpApiKey::issue($admin, 'Read', 'read-only', null))->getJson('/mcp/support-activity')->assertOk()->json();
        $this->assertSame(self::REASON, $data['data'][0]['reason']);
        $this->assertSame('TK-9', $data['data'][0]['ticket']);
        $this->assertFalse($data['meta']['access']['allowed']);
        $this->assertSame('Laura', $data['meta']['access']['changed_by']);

        $names = array_column(ToolRegistry::all(), 'name');
        $this->assertSame([], array_values(array_filter($names, fn (string $name): bool => str_contains($name, 'support') && $name !== 'support_activity_list')));
    }

    public function test_the_client_admin_sees_a_notice_of_who_entered_when_and_why(): void
    {
        $this->enter($this->pass(ticket: 'TK-77'))->assertRedirect();
        $session = SupportSession::query()->sole();
        // The operator never sees the notice about themselves.
        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('data-support-notice', false);
        $this->post(route('admin.support.exit'));

        $admin = User::factory()->create(['role' => 'admin']);
        $editor = User::factory()->create(['role' => 'editor']);
        $time = $session->started_at->timezone(config('app.timezone'))->format('H:i');
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()
            ->assertSee('data-support-notice', false)
            ->assertSee('Soporte RügerTek (Ana) entró hoy a las '.$time)
            ->assertSee('Motivo: '.self::REASON)
            ->assertSee('Ticket TK-77')
            ->assertSee(route('admin.support-activity.show', $session->id), false);
        $this->actingAs($editor)->get(route('admin.dashboard'))->assertOk()->assertDontSee('data-support-notice', false);

        // Dismissed: gone for this administrator only, back with the next entry.
        $other = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.support-activity.notice.dismiss'))->assertRedirect();
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertDontSee('data-support-notice', false);
        $this->actingAs($other)->get(route('admin.dashboard'))->assertOk()->assertSee('data-support-notice', false);
        $this->actingAs($editor)->post(route('admin.support-activity.notice.dismiss'))->assertForbidden();

        $this->travel(1)->minutes();
        auth()->guard('web')->logout();
        $this->enter($this->pass())->assertRedirect();
        $this->post(route('admin.support.exit'));
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSee('data-support-notice', false);

        // Seven days later it is gone by itself.
        $this->travel(SupportNotices::DAYS)->days();
        $this->travel(1)->minutes();
        $this->actingAs($other)->get(route('admin.dashboard'))->assertOk()->assertDontSee('data-support-notice', false);
    }

    public function test_the_notice_says_yesterday_or_the_date(): void
    {
        app()->setLocale('es');
        $notices = app(SupportNotices::class);
        $this->travelTo(Carbon::parse('2026-11-26 18:00:00', config('app.timezone')));

        $this->assertSame('hoy a las 15:20', $notices->when(Carbon::parse('2026-11-26 15:20:00', config('app.timezone'))));
        $this->assertSame('ayer a las 09:05', $notices->when(Carbon::parse('2026-11-25 09:05:00', config('app.timezone'))));
        $this->assertSame('el 22/11 a las 23:59', $notices->when(Carbon::parse('2026-11-22 23:59:00', config('app.timezone'))));
    }

    public function test_page_views_read_as_screens_by_name(): void
    {
        $this->enterAsSupport();
        $this->get(route('admin.connectors.show', 'amiba_chat'))->assertOk();
        $this->get(route('admin.settings.index'))->assertOk();
        $this->get(route('admin.pages.create'))->assertOk();
        $summary = app(SupportActivitySummary::class);

        $lines = SupportAudit::query()->orderBy('id')->get()->map(fn (SupportAudit $audit) => $summary->describe($audit))->all();
        $this->assertSame(['Vio el conector Chatbot Amiba', 'Vio los ajustes del sitio', 'Abrió el formulario para crear una página'], $lines);

        $session = SupportSession::query()->sole();
        $unknown = new SupportAudit(['session_id' => $session->id, 'method' => 'GET', 'route_name' => null, 'path' => '/admin/algo-nuevo']);
        $this->assertSame('Vio /admin/algo-nuevo', $summary->describe($unknown));
        $write = new SupportAudit(['session_id' => $session->id, 'method' => 'PUT', 'route_name' => 'admin.connectors.settings', 'path' => '/admin/connectors/amiba_chat/settings', 'subject_type' => 'connector', 'subject_label' => 'amiba_chat', 'status' => 302]);
        $this->assertSame('Editó el conector Chatbot Amiba', $summary->describe($write));
    }
}
