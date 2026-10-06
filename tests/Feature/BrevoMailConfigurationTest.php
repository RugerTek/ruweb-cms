<?php

namespace Tests\Feature;

use App\Mail\Transport\BrevoTransport;
use App\Models\ContactSubmission;
use App\Models\Page;
use App\Models\Setting;
use App\Providers\BrevoMailServiceProvider;
use App\Services\Forms\SubmissionNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BrevoMailConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::clearCache();
        Setting::create(['key' => 'mail_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'email']);
        Setting::create(['key' => 'brevo_api_key', 'value' => '', 'type' => 'password', 'group' => 'email']);
        config(['mail.default' => 'array', 'mail.mailers.brevo.key' => '']);
    }

    private function bootProvider(): TestableBrevoMailServiceProvider
    {
        $provider = new TestableBrevoMailServiceProvider($this->app);
        $provider->boot();

        return $provider;
    }

    public static function defaultMailers(): array
    {
        return [['array'], ['log'], ['smtp']];
    }

    #[DataProvider('defaultMailers')]
    public function test_enabling_mail_without_a_key_preserves_the_configured_default(string $mailer): void
    {
        config(['mail.default' => $mailer]);
        $this->bootProvider();

        $this->assertSame($mailer, config('mail.default'));
        $this->assertSame($mailer, app(SubmissionNotifier::class)->mailer());
    }

    public function test_whitespace_only_keys_do_not_select_brevo(): void
    {
        Setting::set('brevo_api_key', '   ');
        config(['mail.mailers.brevo.key' => '   ']);
        $this->bootProvider();

        $this->assertSame('array', config('mail.default'));
        $this->assertSame('array', app(SubmissionNotifier::class)->mailer());
    }

    public static function effectiveKeys(): array
    {
        return [
            ['database-test-key', '', 'database-test-key'],
            ['', 'environment-test-key', 'environment-test-key'],
            ['   ', 'environment-test-key', 'environment-test-key'],
            ['database-test-key', 'environment-test-key', 'database-test-key'],
        ];
    }

    #[DataProvider('effectiveKeys')]
    public function test_effective_keys_select_brevo_and_database_key_takes_precedence(string $databaseKey, string $environmentKey, string $expected): void
    {
        Setting::set('brevo_api_key', $databaseKey);
        config(['mail.mailers.brevo.key' => $environmentKey]);
        $provider = $this->bootProvider();

        $this->assertSame('brevo', config('mail.default'));
        $this->assertSame('brevo', app(SubmissionNotifier::class)->mailer());
        $this->assertSame($expected, $provider->effectiveKey());
        $this->assertInstanceOf(BrevoTransport::class, Mail::mailer('brevo')->getSymfonyTransport());
    }

    public function test_disabled_mail_does_not_override_the_default_even_with_a_key(): void
    {
        Setting::set('mail_enabled', '0');
        Setting::set('brevo_api_key', 'database-test-key');
        $this->bootProvider();

        $this->assertSame('array', config('mail.default'));
        [$submission, $page] = $this->notification();
        $this->assertFalse(app(SubmissionNotifier::class)->notify($submission, $page));
        $this->assertNull($submission->fresh()->notified_at);
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_notification_uses_real_array_transport_when_enabled_without_a_key(): void
    {
        $this->bootProvider();
        [$submission, $page] = $this->notification();

        $this->assertTrue(app(SubmissionNotifier::class)->notify($submission, $page));
        $this->assertNotNull($submission->fresh()->notified_at);
        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertSame('inbox@example.com', $messages->first()->getOriginalMessage()->getTo()[0]->getAddress());
    }

    public function test_failed_notification_is_logged_and_remains_unnotified(): void
    {
        $this->bootProvider();
        [$submission, $page] = $this->notification();
        Log::spy();
        Mail::shouldReceive('mailer')->once()->with('array')->andThrow(new RuntimeException('Simulated mail transport failure'));

        $this->assertFalse(app(SubmissionNotifier::class)->notify($submission, $page));
        $this->assertNull($submission->fresh()->notified_at);
        $this->assertDatabaseHas('contact_submissions', ['id' => $submission->id]);
        Log::shouldHaveReceived('warning')->once()->with('Submission notification failed: Simulated mail transport failure');
    }

    private function notification(): array
    {
        $submission = ContactSubmission::create(['name' => 'Visitor', 'email' => 'visitor@example.com', 'message' => 'Test message']);
        $page = Page::create(['title' => 'Contact', 'slug' => 'contact', 'template_key' => 'contact', 'status' => 'draft',
            'content_json' => ['sections' => ['form' => ['notify_enabled' => true, 'notify_email' => 'inbox@example.com']]]]);

        return [$submission, $page];
    }
}

class TestableBrevoMailServiceProvider extends BrevoMailServiceProvider
{
    public function effectiveKey(): string
    {
        return $this->resolveApiKey(config('mail.mailers.brevo'));
    }
}
