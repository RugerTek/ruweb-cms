<?php

namespace Tests\Feature;

use App\Models\ContactSubmission;
use App\Models\Page;
use App\Models\User;
use App\Services\Snapshots\SnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SnapshotFeatureLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_restore_invalidates_pending_deliveries_and_local_editorial_sessions(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['role' => 'admin']);
        $page = Page::create(['title' => 'Source', 'slug' => 'source', 'template_key' => 'default', 'locale' => 'es', 'status' => 'draft']);
        $submission = ContactSubmission::create(['name' => 'Visitor', 'email' => 'visitor@example.com', 'message' => 'Question']);
        $submission->forceFill(['notification_status' => 'queued', 'notification_token' => 'old-token'])->save();
        DB::table('editorial_drafts')->insert(['content_type' => 'pages', 'content_id' => $page->id, 'user_id' => $user->id, 'payload' => '[]', 'base_fingerprint' => str_repeat('a', 64), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('editorial_preview_links')->insert(['content_type' => 'pages', 'content_id' => $page->id, 'user_id' => $user->id, 'token_hash' => str_repeat('b', 64), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);

        $archive = app(SnapshotService::class)->create('feature-lifecycle');
        app(SnapshotService::class)->restore($archive);

        $this->assertDatabaseHas('contact_submissions', ['id' => $submission->id, 'notification_status' => 'failed', 'notification_token' => null]);
        $this->assertDatabaseCount('editorial_drafts', 0);
        $this->assertDatabaseCount('editorial_preview_links', 0);
        $this->assertGreaterThan(0, DB::table('editorial_versions')->count());
    }
}
