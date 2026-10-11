<?php

namespace Tests\Feature;

use App\Jobs\SendSubmissionNotification;
use App\Mail\ContactSubmissionReceived;
use App\Models\ContactSubmission;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use App\Services\Forms\SubmissionOperations;
use App\Services\Snapshots\SnapshotService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SnapshotNotificationQueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // This flow needs real commit callbacks; initialize its own in-memory database
        // without RefreshDatabase's enclosing transaction.
        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    private function work(): void
    {
        app('queue.worker')->runNextJob('database', 'default', new WorkerOptions);
    }

    public function test_destination_worker_cannot_revive_restored_deliveries_and_only_explicit_retry_sends(): void
    {
        Storage::fake('public');
        Mail::fake();
        config(['queue.default' => 'database']);
        $this->seed(SettingsSeeder::class);
        Setting::set('mail_enabled', '1');
        $actor = User::factory()->create(['role' => 'admin']);
        $page = Page::create(['title' => 'Contact', 'slug' => 'contact', 'template_key' => 'contact', 'locale' => 'es', 'status' => 'published',
            'content_json' => ['sections' => ['form' => ['notify_enabled' => true, 'notify_email' => 'inbox@example.test']]]]);
        $pending = ContactSubmission::create(['page_id' => $page->id, 'name' => 'Pending', 'email' => 'pending@example.test', 'message' => 'Question']);
        $pending->forceFill(['notification_status' => 'queued', 'notification_token' => 'old-generation', 'notification_email' => 'inbox@example.test'])->save();
        $sent = ContactSubmission::create(['page_id' => $page->id, 'name' => 'Sent', 'email' => 'sent@example.test', 'message' => 'Already delivered', 'notified_at' => now()]);
        $sent->forceFill(['notification_status' => 'sent', 'notification_token' => 'sent-generation', 'notification_email' => 'inbox@example.test'])->save();
        $service = app(SnapshotService::class);
        $archive = $service->create('queue-portability');

        // These jobs belong to the destination and refer to IDs that restore reuses.
        Bus::dispatch((new SendSubmissionNotification($pending->id, 'old-generation'))->onConnection('database'));
        Bus::dispatch((new SendSubmissionNotification($sent->id, 'sent-generation'))->onConnection('database'));
        $this->assertDatabaseCount('jobs', 2);
        $service->restore($archive);
        $this->work();
        $this->work();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        Mail::assertNothingSent();
        $this->assertDatabaseHas('contact_submissions', ['id' => $pending->id, 'notification_status' => 'failed', 'notification_token' => null, 'notification_claim' => null]);
        $this->assertSame('sent', $sent->fresh()->notification_status);
        $this->assertNotNull($sent->fresh()->notified_at);

        $operations = app(SubmissionOperations::class);
        $alreadySent = $operations->write($actor->fresh(), 'contact', $sent->id, 'retry_notification', ['operation_key' => 'sent-retry']);
        $this->assertFalse($alreadySent['queued']);
        $this->assertSame('already_sent', $alreadySent['reason']);
        $this->assertDatabaseCount('jobs', 0);
        $retried = $operations->write($actor->fresh(), 'contact', $pending->id, 'retry_notification', ['operation_key' => 'explicit-retry']);
        $this->assertTrue($retried['queued']);
        $generation = $pending->fresh()->notification_token;
        $this->assertNotNull($generation);
        $this->assertNotSame('old-generation', $generation);
        $this->assertDatabaseCount('jobs', 1);
        $this->work();
        Mail::assertSent(ContactSubmissionReceived::class, 1);
        $this->assertSame('sent', $pending->fresh()->notification_status);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame(2, DB::table('submission_events')->where('action', 'retry_notification')->count());
    }
}
