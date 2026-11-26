<?php

namespace Tests\Feature;

use App\Models\McpApiKey;
use App\Models\Setting;
use App\Models\User;
use App\Services\Mail\MailSettings;
use App\Support\SettingDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;

/**
 * Admin > Email with an SMTP server (docs/operations/email.md): settings saved with the password encrypted and never
 * shown again, Laravel's smtp mailer built from them, «Enviar correo de prueba» with the server's real error, and the
 * email state in MCP without secrets.
 */
class MailSmtpSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'clave-smtp-secreta-123';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (SettingDefaults::all() as $setting) {
            Setting::updateOrCreate(['key' => $setting['key']], $setting);
        }
        Setting::clearCache();
        $this->admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@example.com']);
    }

    /** @param  array<string, string>  $overrides */
    private function saveSmtp(array $overrides = []): void
    {
        $this->actingAs($this->admin)->put(route('admin.email.update'), ['settings' => $overrides + [
            'mail_enabled' => '1',
            'mail_provider' => 'smtp',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => '587',
            'smtp_encryption' => 'tls',
            'smtp_username' => 'ventas@example.com',
            'smtp_password' => self::PASSWORD,
            'mail_from_address' => 'ventas@example.com',
            'mail_from_name' => 'Tienda Ejemplo',
        ]])->assertSessionHasNoErrors()->assertRedirect(route('admin.email.index'));
        Setting::clearCache();
    }

    public function test_smtp_settings_are_saved_with_the_password_encrypted_and_never_shown(): void
    {
        $this->saveSmtp();

        $raw = (string) DB::table('settings')->where('key', 'smtp_password')->value('value');
        $this->assertNotSame(self::PASSWORD, $raw);
        $this->assertSame(self::PASSWORD, Crypt::decryptString($raw));
        $this->assertSame('smtp', Setting::get('mail_provider'));
        $this->assertSame('smtp.example.com', Setting::get('smtp_host'));
        $this->assertSame(587, Setting::get('smtp_port'));

        $this->get(route('admin.email.index'))->assertOk()
            ->assertDontSee(self::PASSWORD)
            ->assertDontSee($raw)
            ->assertSee('value="smtp.example.com"', false)
            ->assertSee(__('admin.password_stored_placeholder'))
            ->assertSee('data-mail-status="active"', false);

        // An empty password field keeps the stored one; other fields change.
        $this->saveSmtp(['smtp_password' => '', 'smtp_host' => 'mail.example.com']);
        $this->assertSame(self::PASSWORD, Setting::get('smtp_password'));
        $this->assertSame('mail.example.com', Setting::get('smtp_host'));
    }

    public function test_the_smtp_mailer_is_built_from_the_panel_settings(): void
    {
        config(['mail.default' => 'array']);
        $this->saveSmtp(['smtp_port' => '2525']);
        $mail = app(MailSettings::class);
        $mail->apply();

        $this->assertSame('smtp', $mail->mailer());
        $this->assertSame('smtp', config('mail.default'), 'With sending on, every site email uses the chosen provider.');
        $transport = Mail::mailer('smtp')->getSymfonyTransport();
        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertSame('ventas@example.com', $transport->getUsername());
        $this->assertSame(self::PASSWORD, $transport->getPassword());
        $this->assertSame(2525, $transport->getStream()->getPort());
        $this->assertTrue($transport->isTlsRequired());
        $this->assertSame('ventas@example.com', config('mail.from.address'));

        $this->saveSmtp(['smtp_port' => '465', 'smtp_encryption' => 'ssl']);
        $mail->apply();
        $this->assertTrue(Mail::mailer('smtp')->getSymfonyTransport()->getStream()->isTLS(), 'ssl connects with TLS from the start.');

        $this->saveSmtp(['smtp_port' => '25', 'smtp_encryption' => 'none']);
        $mail->apply();
        $transport = Mail::mailer('smtp')->getSymfonyTransport();
        $this->assertFalse($transport->isAutoTls());
        $this->assertFalse($transport->isTlsRequired());
    }

    public function test_enabling_smtp_needs_a_server_and_a_port(): void
    {
        $this->actingAs($this->admin)->put(route('admin.email.update'), ['settings' => [
            'mail_enabled' => '1', 'mail_provider' => 'smtp', 'smtp_host' => '', 'smtp_port' => '',
        ]])->assertSessionHasErrors(['settings.smtp_host', 'settings.smtp_port']);
        $this->actingAs($this->admin)->put(route('admin.email.update'), ['settings' => [
            'mail_enabled' => '1', 'mail_provider' => 'smtp', 'smtp_host' => 'smtp.example.com', 'smtp_port' => '70000',
        ]])->assertSessionHasErrors(['settings.smtp_port']);
        $this->actingAs($this->admin)->put(route('admin.email.update'), ['settings' => ['mail_provider' => 'sendgrid']])
            ->assertSessionHasErrors(['settings.mail_provider']);
        Setting::clearCache();
        $this->assertSame('brevo', Setting::get('mail_provider'));
        $this->assertFalse((bool) Setting::get('mail_enabled'));

        // Saved but off: allowed, so the administrator can prepare SMTP before switching it on.
        $this->actingAs($this->admin)->put(route('admin.email.update'), ['settings' => ['mail_enabled' => '0', 'mail_provider' => 'smtp', 'smtp_host' => '']])
            ->assertSessionHasNoErrors();
        // Keys outside Admin > Email are ignored.
        $this->actingAs($this->admin)->put(route('admin.email.update'), ['settings' => ['site_name' => 'Hackeado']])->assertSessionHasNoErrors();
        Setting::clearCache();
        $this->assertNotSame('Hackeado', Setting::get('site_name'));
    }

    public function test_test_email_goes_through_the_saved_smtp_server(): void
    {
        $this->actingAs($this->admin)->post(route('admin.email.test'), ['test_email' => 'yo@example.com'])
            ->assertSessionHas('error', __('admin.email_test_no_key'));

        $this->actingAs($this->admin)->put(route('admin.email.update'), ['settings' => ['mail_provider' => 'smtp', 'smtp_host' => '']]);
        $this->actingAs($this->admin)->post(route('admin.email.test'), ['test_email' => 'yo@example.com'])
            ->assertSessionHas('error', __('admin.email_test_no_smtp'));

        $this->saveSmtp();
        Mail::fake();
        $this->actingAs($this->admin)->post(route('admin.email.test'), ['test_email' => 'yo@example.com'])
            ->assertSessionHas('success', __('admin.email_test_success', ['email' => 'yo@example.com']));
    }

    public function test_a_failing_smtp_server_shows_its_real_error_without_secrets(): void
    {
        // Nothing listens on port 1: the connection is refused, like a wrong host or port.
        $this->saveSmtp(['smtp_host' => '127.0.0.1', 'smtp_port' => '1', 'smtp_encryption' => 'none']);

        $response = $this->actingAs($this->admin)->post(route('admin.email.test'), ['test_email' => 'yo@example.com']);
        $error = (string) session('error');
        $response->assertRedirect(route('admin.email.index'));
        $this->assertStringStartsWith(__('admin.email_test_failed', ['error' => '']), $error);
        $this->assertStringContainsString('127.0.0.1', $error, 'The real transport error reaches the administrator.');
        $this->assertStringNotContainsString(self::PASSWORD, $error);

        $last = app(MailSettings::class)->lastError();
        $this->assertNotNull($last);
        $this->assertSame('smtp', $last['provider']);
        $this->get(route('admin.email.index'))->assertOk()->assertSee('data-mail-last-error', false)->assertDontSee(self::PASSWORD);

        // A successful send clears it.
        Mail::fake();
        $this->saveSmtp();
        $this->actingAs($this->admin)->post(route('admin.email.test'), ['test_email' => 'yo@example.com'])->assertSessionHas('success');
        $this->assertNull(app(MailSettings::class)->lastError());
    }

    public function test_error_messages_are_scrubbed_of_secrets(): void
    {
        $this->saveSmtp();
        $mail = app(MailSettings::class);
        $mail->recordError('535 Authentication failed for ventas@example.com with '.self::PASSWORD);

        $this->assertSame('535 Authentication failed for ventas@example.com with [oculto]', $mail->lastError()['message']);
    }

    public function test_mcp_reports_the_email_state_without_secrets(): void
    {
        $this->saveSmtp();
        app(MailSettings::class)->recordError('Connection refused');
        $this->withToken(McpApiKey::issue($this->admin, 'Mail', 'full', null));

        $response = $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'cms_capabilities', 'arguments' => []]]);
        $response->assertOk();
        $text = (string) $response->json('result.content.0.text');
        $mail = json_decode($text, true, flags: JSON_THROW_ON_ERROR)['data']['mail'];
        $this->assertSame('smtp', $mail['provider']);
        $this->assertTrue($mail['enabled']);
        $this->assertTrue($mail['active']);
        $this->assertSame('smtp.example.com', $mail['smtp']['host']);
        $this->assertTrue($mail['smtp']['password_configured']);
        $this->assertSame('Connection refused', $mail['last_error']['message']);
        $this->assertStringNotContainsString(self::PASSWORD, $text);

        $settings = $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'settings_get', 'arguments' => ['key' => 'smtp_password']]]);
        $settings->assertOk();
        $this->assertStringNotContainsString(self::PASSWORD, $settings->getContent());
        $this->assertStringNotContainsString((string) DB::table('settings')->where('key', 'smtp_password')->value('value'), $settings->getContent());
        $list = $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'settings_list', 'arguments' => []]]);
        $list->assertOk();
        $this->assertStringNotContainsString(self::PASSWORD, $list->getContent());
    }

    public function test_existing_brevo_sites_keep_brevo(): void
    {
        $this->actingAs($this->admin)->put(route('admin.email.update'), ['settings' => ['mail_enabled' => '1', 'brevo_api_key' => 'xkeysib-prueba']])
            ->assertSessionHasNoErrors();
        Setting::clearCache();
        $mail = app(MailSettings::class);

        $this->assertSame('brevo', $mail->provider());
        $this->assertSame('brevo', $mail->mailer());
        $this->assertTrue($mail->active());
        $this->get(route('admin.email.index'))->assertOk()->assertDontSee('xkeysib-prueba')->assertSee('data-mail-status="active"', false);
    }
}
