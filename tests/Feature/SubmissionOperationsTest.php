<?php

namespace Tests\Feature;

use App\Exceptions\RevisionConflict;
use App\Jobs\SendSubmissionNotification;
use App\Models\ContactSubmission;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use App\Services\Forms\SubmissionNotifier;
use App\Services\Forms\SubmissionOperations;
use Database\Seeders\SettingsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SubmissionOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function record(array $data = []): ContactSubmission
    {
        return ContactSubmission::create($data + ['name' => 'Visitor', 'email' => 'visitor@example.com', 'message' => 'Question']);
    }

    public function test_after_commit_queue_failure_is_caught_and_recorded_durably(): void
    {
        $this->seed(SettingsSeeder::class);
        Setting::set('mail_enabled', '1');
        $page = Page::create(['title' => 'Contact', 'slug' => 'contact', 'template' => 'contact',
            'content_json' => ['sections' => ['form' => ['notify_enabled' => true, 'notify_email' => 'inbox@example.com']]]]);
        $record = $this->record(['page_id' => $page->id]);
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Queue unavailable'));
        DB::transaction(function () use ($record, $page) {
            $this->assertTrue(app(SubmissionNotifier::class)->notify($record, $page));
            $this->assertSame('queued', $record->fresh()->notification_status);
        });
        $this->assertSame('failed', $record->fresh()->notification_status);
        $this->assertNotNull($record->fresh()->notification_failed_at);
        $this->assertSame('Queue unavailable', $record->fresh()->notification_error);
    }

    public function test_reads_preserve_unread_and_bound_personal_data(): void
    {
        $actor = User::factory()->create(['role' => 'editor']);
        $record = $this->record(['message' => str_repeat('x', 12000), 'assigned_to' => $actor->id]);
        for ($i = 0; $i < 25; $i++) {
            $record->notes()->create(['body' => 'Internal '.$i, 'user_id' => $actor->id]);
        }
        $operations = app(SubmissionOperations::class);
        $list = $operations->read($actor, 'contact', null);
        $this->assertArrayNotHasKey('notes', $list['submissions'][0]);
        $detail = $operations->read($actor, 'contact', $record->id, ['field' => 'message', 'offset' => 8192, 'length' => 8192]);
        $this->assertNull($record->fresh()->read_at);
        $this->assertCount(20, $detail['notes']);
        $this->assertSame(25, $detail['notes_pagination']['total']);
        $this->assertSame(['id', 'name', 'role'], array_keys($detail['assignee']));
        $this->assertArrayNotHasKey('notification_token', $detail);
        $this->assertSame(8192, mb_strlen($detail['message']));
        $this->assertSame(3808, mb_strlen($detail['field_slice']['value']));
        $this->assertSame(hash('sha256', $record->message), $detail['truncated_fields']['message']['sha256']);
        $this->assertCount(5, $operations->get($actor, 'contact', $record->id, ['notes_page' => 2])['notes']);
    }

    public function test_note_retries_are_attributed_once_and_audited_even_after_a_later_change(): void
    {
        $actor = User::factory()->create(['role' => 'editor']);
        $record = $this->record();
        $operations = app(SubmissionOperations::class);
        $input = ['body' => 'Follow up', 'expected_revision' => $operations->get($actor, 'contact', $record->id)['revision'], 'operation_key' => 'note:1'];
        $result = $operations->write($actor, 'contact', $record->id, 'add_note', $input);
        $operations->write($actor, 'contact', $record->id, 'set_status', ['workflow_status' => 'resolved']);
        $this->assertSame($result, $operations->write($actor, 'contact', $record->id, 'add_note', $input));
        $this->assertDatabaseCount('contact_submission_notes', 1);
        $this->assertDatabaseHas('contact_submission_notes', ['user_id' => $actor->id, 'body' => 'Follow up']);
        $this->assertDatabaseCount('submission_events', 2);
        $this->assertDatabaseHas('submission_events', ['user_id' => $actor->id, 'action' => 'add_note']);
    }

    public function test_stale_revision_rejects_mutation_after_a_note_is_added(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $record = $this->record();
        $operations = app(SubmissionOperations::class);
        $revision = $operations->get($actor, 'contact', $record->id)['revision'];
        $operations->write($actor, 'contact', $record->id, 'add_note', ['body' => 'New note']);
        try {
            $operations->write($actor, 'contact', $record->id, 'set_status', ['workflow_status' => 'resolved', 'expected_revision' => $revision]);
            $this->fail('Stale write was accepted.');
        } catch (RevisionConflict $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame('new', $record->fresh()->workflow_status);
    }

    public function test_operation_key_cannot_be_reused_for_different_payload(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $record = $this->record();
        $operations = app(SubmissionOperations::class);
        $operations->write($actor, 'contact', $record->id, 'add_note', ['body' => 'One', 'operation_key' => 'same']);
        try {
            $operations->write($actor, 'contact', $record->id, 'add_note', ['body' => 'Two', 'operation_key' => 'same']);
            $this->fail('Conflicting retry was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('contact_submission_notes', 1);
    }

    public function test_assignment_rejects_nonoperational_roles_and_notes_reject_whitespace(): void
    {
        $actor = User::factory()->create(['role' => 'editor']);
        $client = User::factory()->create(['role' => 'client']);
        $record = $this->record();
        foreach ([['assign', ['assigned_to' => $client->id]], ['add_note', ['body' => '   ']]] as [$action, $input]) {
            try {
                app(SubmissionOperations::class)->write($actor, 'contact', $record->id, $action, $input);
                $this->fail('Invalid input was accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertDatabaseCount('submission_events', 0);
    }

    public function test_client_is_denied_without_mutating_data(): void
    {
        $actor = User::factory()->create(['role' => 'client']);
        $record = $this->record();
        $this->expectException(AuthorizationException::class);
        app(SubmissionOperations::class)->write($actor, 'contact', $record->id, 'set_status', ['workflow_status' => 'resolved']);
    }

    public function test_panel_read_and_delete_are_audited_and_notes_cascade(): void
    {
        $actor = User::factory()->create(['role' => 'editor']);
        $record = $this->record();
        $record->notes()->create(['body' => 'Private', 'user_id' => $actor->id]);
        $operations = app(SubmissionOperations::class);
        $operations->write($actor, 'contact', $record->id, 'mark_read', []);
        $this->assertNotNull($record->fresh()->read_at);
        $operations->write($actor, 'contact', $record->id, 'delete', []);
        $this->assertDatabaseCount('contact_submission_notes', 0);
        $this->assertDatabaseCount('submission_events', 2);
    }

    public function test_reclaimed_attempt_cannot_be_overwritten_by_late_success_or_failure(): void
    {
        $this->seed(SettingsSeeder::class);
        Setting::set('mail_enabled', '1');
        foreach ([false, true] as $throw) {
            $record = $this->record();
            $record->forceFill(['notification_status' => 'queued', 'notification_token' => 'generation', 'notification_email' => 'inbox@example.com'])->save();
            $transport = \Mockery::mock();
            $transport->shouldReceive('to')->once()->with('inbox@example.com')->andReturnSelf();
            $transport->shouldReceive('send')->once()->andReturnUsing(function () use ($record, $throw) {
                // Simulate another worker reclaiming the expired lease during transport.
                $record->forceFill(['notification_claim' => 'replacement', 'notification_status' => 'retrying', 'notification_error' => 'New attempt'])->save();
                if ($throw) {
                    throw new \RuntimeException('Old attempt');
                }
            });
            Mail::shouldReceive('mailer')->once()->andReturn($transport);
            try {
                (new SendSubmissionNotification($record->id, 'generation'))->handle(app(SubmissionNotifier::class));
            } catch (\RuntimeException $exception) {
                $this->assertTrue($throw);
            }
            $this->assertSame('retrying', $record->fresh()->notification_status);
            $this->assertSame('New attempt', $record->fresh()->notification_error);
            $this->assertNull($record->fresh()->notified_at);
        }
    }

    public function test_obsolete_exhausted_job_cannot_fail_an_active_lease_or_sent_delivery(): void
    {
        $record = $this->record();
        $record->forceFill(['notification_token' => 'generation', 'notification_claim' => 'active', 'notification_status' => 'sending', 'notification_started_at' => now()])->save();
        $job = new SendSubmissionNotification($record->id, 'generation');
        $job->failed(new \RuntimeException('Duplicate exhausted'));
        $this->assertSame('sending', $record->fresh()->notification_status);
        $record->forceFill(['notification_status' => 'sent', 'notified_at' => now()])->save();
        $job->failed(new \RuntimeException('Stale failure'));
        $this->assertSame('sent', $record->fresh()->notification_status);
    }

    public function test_expired_lease_can_be_failed_but_old_generations_cannot_change_it(): void
    {
        $record = $this->record();
        $record->forceFill(['notification_token' => 'new-generation', 'notification_status' => 'sending', 'notification_started_at' => now()->subSeconds(61)])->save();
        (new SendSubmissionNotification($record->id, 'old-generation'))->failed(new \RuntimeException('Old'));
        $this->assertSame('sending', $record->fresh()->notification_status);
        (new SendSubmissionNotification($record->id, 'new-generation'))->failed(new \RuntimeException('Timed out'));
        $this->assertSame('failed', $record->fresh()->notification_status);
    }
}
