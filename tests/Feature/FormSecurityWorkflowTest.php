<?php

namespace Tests\Feature;

use App\Jobs\SendSubmissionNotification;
use App\Mail\ContactSubmissionReceived;
use App\Models\ContactSubmission;
use App\Models\Locale;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use App\Services\Forms\FormToken;
use App\Services\Forms\Recaptcha;
use App\Services\Forms\SubmissionNotifier;
use Database\Seeders\ContactPageSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FormSecurityWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected Page $page;

    protected function setUp(): void
    {
        parent::setUp();
        Locale::query()->firstOrCreate(['code' => 'es'], ['name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
        $this->seed([SettingsSeeder::class, ContactPageSeeder::class]);
        $this->page = Page::where('slug', 'contacto')->firstOrFail();
    }

    protected function payload(array $overrides = []): array
    {
        $this->travel(-10)->seconds();
        $token = app(FormToken::class)->issue($this->page->id, 'contact', 'es');
        $this->travelBack();

        return array_replace(['page_id' => $this->page->id, 'name' => 'Ana Pérez', 'email' => 'ana@example.com', 'message' => 'Quisiera más información sobre sus servicios.', 'website' => '', '_form_token' => $token], $overrides);
    }

    public static function invalidResponses(): array
    {
        $valid = ['success' => true, 'score' => 0.9, 'action' => 'contact', 'hostname' => 'localhost'];

        return [
            [array_diff_key($valid, ['score' => 1])], [array_diff_key($valid, ['action' => 1])],
            [array_diff_key($valid, ['hostname' => 1])], [array_replace($valid, ['score' => '0.9'])],
            [array_replace($valid, ['score' => 2])], [array_replace($valid, ['action' => 'newsletter'])],
            [array_replace($valid, ['hostname' => 'evil.example'])], [array_replace($valid, ['success' => 'true'])],
        ];
    }

    #[DataProvider('invalidResponses')]
    public function test_incomplete_or_invalid_recaptcha_success_is_rejected(array $response): void
    {
        Setting::set('recaptcha_site_key', 'site');
        Setting::set('recaptcha_secret_key', 'secret');
        Http::fake([Recaptcha::VERIFY_URL => Http::response($response)]);
        $this->post(route('forms.contact.store'), $this->payload(['recaptcha_token' => 'token']))->assertSessionHasErrors('recaptcha_token');
        $this->assertDatabaseCount('contact_submissions', 0);
    }

    public function test_unpublished_future_other_locale_wrong_template_and_hidden_page_are_rejected(): void
    {
        $original = $this->page->getAttributes();
        foreach ([['status' => 'draft'], ['published_at' => now()->addDay()], ['locale' => 'en'], ['template_key' => 'default'], ['content_json' => ['sections' => ['form' => ['is_visible' => false]]]], ['content_json' => ['sections' => []]]] as $changes) {
            $this->page->setRawAttributes($original);
            $this->page->fill($changes)->save();
            $this->post(route('forms.contact.store'), $this->payload())->assertSessionHasErrors('page_id');
        }
        $this->assertDatabaseCount('contact_submissions', 0);
    }

    public function test_missing_forged_expired_future_and_unbound_tokens_are_rejected(): void
    {
        $this->travel(-7201)->seconds();
        $expired = app(FormToken::class)->issue($this->page->id, 'contact', 'es');
        $this->travelBack();
        $this->travel(20)->seconds();
        $future = app(FormToken::class)->issue($this->page->id, 'contact', 'es');
        $this->travelBack();
        foreach ([null, 'forged', $expired, $future, app(FormToken::class)->issue($this->page->id + 1, 'contact', 'es'), app(FormToken::class)->issue($this->page->id, 'newsletter', 'es'), app(FormToken::class)->issue($this->page->id, 'contact', 'en')] as $token) {
            $this->post(route('forms.contact.store'), $this->payload(['_form_token' => $token, '_ts' => time() - 50]))->assertSessionHasErrors('_form_token');
        }
        $this->assertDatabaseCount('contact_submissions', 0);
    }

    public function test_missing_page_cannot_submit_and_hostname_does_not_trust_the_host_header(): void
    {
        $this->post(route('forms.contact.store'), $this->payload(['page_id' => null]))->assertSessionHasErrors('page_id');
        Setting::set('recaptcha_site_key', 'site');
        Setting::set('recaptcha_secret_key', 'secret');
        Http::fake([Recaptcha::VERIFY_URL => Http::response(['success' => true, 'score' => 0.9, 'action' => 'contact', 'hostname' => 'evil.example'])]);
        $this->withHeader('Host', 'evil.example')->post(route('forms.contact.store'), $this->payload(['recaptcha_token' => 'token']))->assertSessionHasErrors('recaptcha_token');
    }

    public function test_inbox_assigns_changes_state_stores_escaped_notes_and_filters(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $this->post(route('forms.contact.store'), $this->payload());
        $record = ContactSubmission::firstOrFail();
        $args = ['form' => 'contact', 'submission' => $record->id];
        $this->actingAs($editor)->patch(route('admin.submissions.update', $args), ['workflow_status' => 'in_progress', 'assigned_to' => $editor->id])->assertSessionHas('success');
        $this->assertDatabaseHas('contact_submissions', ['id' => $record->id, 'workflow_status' => 'in_progress', 'assigned_to' => $editor->id]);
        $this->post(route('admin.submissions.notes', $args), ['body' => '<script>alert(1)</script>'])->assertSessionHas('success');
        $this->get(route('admin.submissions.show', $args))->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertSee($editor->name);
        $this->get(route('admin.submissions.index', ['form' => 'contact', 'workflow' => 'resolved']))->assertDontSee('ana@example.com');
        $this->get(route('admin.submissions.index', ['form' => 'contact', 'workflow' => 'in_progress', 'assignee' => $editor->id]))->assertSee('ana@example.com');
        $this->patch(route('admin.submissions.update', $args), ['workflow_status' => 'resolved', 'assigned_to' => null])->assertSessionHas('success');
        $this->assertDatabaseHas('contact_submissions', ['id' => $record->id, 'workflow_status' => 'resolved', 'assigned_to' => null]);
    }

    public function test_assignment_and_notes_validate_and_unauthorized_role_is_rejected(): void
    {
        $record = ContactSubmission::create(['name' => 'Ana', 'email' => 'ana@example.com', 'message' => 'Please contact me']);
        $args = ['form' => 'contact', 'submission' => $record->id];
        $viewer = User::factory()->create(['role' => 'viewer']);
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->patch(route('admin.submissions.update', $args), ['workflow_status' => 'invalid', 'assigned_to' => $viewer->id])->assertSessionHasErrors(['workflow_status', 'assigned_to']);
        $this->post(route('admin.submissions.notes', $args), ['body' => ''])->assertSessionHasErrors('body');
        $this->actingAs($viewer)->post(route('admin.submissions.notes', $args), ['body' => 'secret'])->assertForbidden();
        $this->patch(route('admin.submissions.update', $args), ['workflow_status' => 'resolved'])->assertForbidden();
        $this->post(route('admin.submissions.resend', $args))->assertForbidden();
        $this->assertDatabaseCount('contact_submission_notes', 0);
    }

    public function test_notifications_queue_retry_resend_and_ignore_duplicate_or_stale_jobs(): void
    {
        Queue::fake();
        Mail::fake();
        Setting::set('mail_enabled', '1');
        $content = $this->page->content_json;
        $content['sections']['form']['notify_enabled'] = true;
        $content['sections']['form']['notify_email'] = 'inbox@example.com';
        $this->page->update(['content_json' => $content]);
        $this->post(route('forms.contact.store'), $this->payload());
        $record = ContactSubmission::firstOrFail();
        $notifier = app(SubmissionNotifier::class);
        Queue::assertPushed(SendSubmissionNotification::class);
        Mail::assertNothingSent();
        $this->assertFalse($notifier->notify($record, $this->page));
        $job = new SendSubmissionNotification($record->id, $record->notification_token);
        $this->assertSame([30, 120, 600], $job->backoff());
        $job->failed(new \RuntimeException('Delivery failed'));
        $editor = User::factory()->create(['role' => 'editor']);
        $args = ['form' => 'contact', 'submission' => $record->id];
        $this->actingAs($editor)->get(route('admin.submissions.show', $args))->assertSee('Reintentar notificación');
        $this->post(route('admin.submissions.resend', $args))->assertSessionHas('success');
        $record->refresh();
        $this->assertNotSame($job->token, $record->notification_token);
        $job->handle($notifier);
        Mail::assertNothingSent();
        $current = new SendSubmissionNotification($record->id, $record->notification_token);
        $current->handle($notifier);
        $current->handle($notifier);
        $this->assertSame('sent', $record->fresh()->notification_status);
        Mail::assertSentCount(1);
        Mail::assertSent(ContactSubmissionReceived::class);
        $this->post(route('admin.submissions.resend', $args))->assertSessionHas('error');
        $this->assertSame('sent', $record->fresh()->notification_status);
    }

    public function test_worker_retries_transport_failure_and_exhaustion_remains_actionable(): void
    {
        Mail::fake();
        Setting::set('mail_enabled', '0');
        $record = ContactSubmission::create(['name' => 'Ana', 'email' => 'ana@example.com', 'message' => 'Please contact me']);
        $record->forceFill(['notification_status' => 'queued', 'notification_token' => 'generation', 'notification_email' => 'inbox@example.com'])->save();
        $job = new SendSubmissionNotification($record->id, 'generation');
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            try {
                $job->handle(app(SubmissionNotifier::class));
                $this->fail('Disabled mail must fail delivery');
            } catch (\RuntimeException $exception) {
                $this->assertSame('retrying', $record->fresh()->notification_status);
                $this->assertSame($attempt, $record->fresh()->notification_attempts);
                if ($attempt === 4) {
                    $job->failed($exception);
                }
            }
        }
        $this->assertSame('failed', $record->fresh()->notification_status);
        $this->assertNotNull($record->fresh()->notification_failed_at);
        Mail::assertNothingSent();
    }

    public function test_live_sending_lease_releases_retry_and_expired_timeout_is_reclaimed(): void
    {
        Mail::fake();
        Setting::set('mail_enabled', '1');
        $record = ContactSubmission::create(['name' => 'Ana', 'email' => 'ana@example.com', 'message' => 'Please contact me']);
        $record->forceFill(['notification_status' => 'sending', 'notification_token' => 'generation', 'notification_email' => 'inbox@example.com', 'notification_started_at' => now()->subSeconds(20)])->save();
        $worker = \Mockery::mock(Job::class);
        $worker->shouldReceive('release')->once()->with(41);
        $job = new SendSubmissionNotification($record->id, 'generation');
        $job->setJob($worker);
        $job->handle(app(SubmissionNotifier::class));
        Mail::assertNothingSent();
        $this->assertSame('sending', $record->fresh()->notification_status);
        $this->travel(70)->seconds();
        $job->handle(app(SubmissionNotifier::class));
        $this->assertSame('sent', $record->fresh()->notification_status);
        Mail::assertSentCount(1);
        $this->travelBack();
    }
}
