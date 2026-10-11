<?php

namespace Tests\Feature;

use App\Jobs\SendSubmissionNotification;
use App\Models\ContactSubmission;
use App\Models\Media;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use App\Services\Forms\SubmissionNotifier;
use App\Services\Snapshots\SnapshotException;
use App\Services\Snapshots\SnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class SnapshotPortabilitySecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function rewrite(string $archive, array $files): void
    {
        $zip = new ZipArchive;
        $zip->open($archive);
        $checksums = json_decode($zip->getFromName('checksums.json'), true);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
            $checksums[$name] = hash('sha256', $contents);
        }
        $zip->addFromString('checksums.json', json_encode($checksums));
        $zip->close();
    }

    private function rows(string $archive, string $table): array
    {
        $zip = new ZipArchive;
        $zip->open($archive);
        $contents = $zip->getFromName('database/'.$table.'.jsonl');
        $zip->close();

        return array_map(fn ($line) => json_decode($line, true), array_filter(explode("\n", $contents)));
    }

    public function test_receipts_are_not_portable_and_submission_audit_and_delivery_are_reset_safely(): void
    {
        $user = User::factory()->create();
        $submission = ContactSubmission::create(['name' => 'Visitor', 'email' => 'visitor@example.test', 'message' => 'Question']);
        $submission->forceFill(['notification_status' => 'sending', 'notification_token' => 'source-token', 'notification_claim' => 'source-claim'])->save();
        DB::table('submission_events')->insert(['user_id' => $user->id, 'form' => 'contact', 'submission_id' => $submission->id, 'action' => 'update', 'before' => '{}', 'after' => '{}', 'created_at' => now()]);
        DB::table('mcp_operation_receipts')->insert(['user_id' => $user->id, 'operation' => 'create_page', 'operation_key' => 'source', 'payload_hash' => str_repeat('a', 64), 'model_type' => Page::class]);
        DB::table('editorial_operation_receipts')->insert(['user_id' => $user->id, 'content_type' => 'pages', 'content_id' => 1, 'operation_key' => 'source', 'request_hash' => str_repeat('a', 64), 'result' => '{}']);
        DB::table('submission_operation_receipts')->insert(['user_id' => $user->id, 'action' => 'update', 'operation_key' => 'source', 'payload_hash' => str_repeat('a', 64), 'result' => '{}']);
        $service = app(SnapshotService::class);
        $archive = $service->create('portability');
        $report = $service->inspect($archive);
        foreach (['mcp_operation_receipts', 'editorial_operation_receipts', 'submission_operation_receipts'] as $table) {
            $this->assertNotContains($table, array_column($report->manifest['database']['tables'], 'name'));
        }
        // A forged archive may list runtime credentials/receipts despite export exclusion.
        $manifest = $report->manifest;
        $injected = [];
        foreach (['mcp_operation_receipts', 'editorial_operation_receipts', 'submission_operation_receipts', 'custom_code_receipts', 'oauth_access_tokens', 'oauth_authorization_codes', 'mcp_api_keys'] as $table) {
            $file = 'database/'.$table.'.jsonl';
            $manifest['database']['tables'][] = ['name' => $table, 'file' => $file, 'rows' => 1];
            $injected[$file] = '{"user_id":1,"token":"must-never-import"}\n';
        }
        $injected['manifest.json'] = json_encode($manifest);
        $this->rewrite($archive, $injected);
        $events = $this->rows($archive, 'submission_events');
        $events[0]['user_id'] = 999999;
        $this->rewrite($archive, ['database/submission_events.jsonl' => json_encode($events[0])."\n"]);
        Bus::fake();
        Mail::fake();
        $service->restore($archive);
        (new SendSubmissionNotification($submission->id, 'source-token'))->handle(app(SubmissionNotifier::class));
        Mail::assertNothingSent();
        Bus::assertNothingDispatched();
        $this->assertDatabaseCount('jobs', 0);
        foreach (['mcp_operation_receipts', 'editorial_operation_receipts', 'submission_operation_receipts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        foreach (['oauth_access_tokens', 'oauth_authorization_codes', 'mcp_api_keys', 'custom_code_receipts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseHas('submission_events', ['submission_id' => $submission->id, 'user_id' => null, 'action' => 'update']);
        $this->assertDatabaseHas('contact_submissions', ['id' => $submission->id, 'notification_status' => 'failed', 'notification_token' => null, 'notification_claim' => null]);
    }

    public function test_rechecksummed_account_grants_and_html_are_normalized_on_restore(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $page = Page::create(['title' => 'Composition', 'slug' => 'composition', 'template_key' => 'html', 'locale' => 'es', 'status' => 'published', 'content_json' => ['sections' => ['content' => ['html' => '<p>Safe</p>']]]]);
        $post = Post::create(['title' => 'Post', 'slug' => 'post', 'locale' => 'es', 'status' => 'published', 'content' => '<p>Safe</p>']);
        $service = app(SnapshotService::class);
        $archive = $service->create('hostile-html');
        $users = $this->rows($archive, 'users');
        $users[0]['can_manage_custom_code'] = true;
        $users[0]['remember_token'] = 'injected-credential';
        $pages = $this->rows($archive, 'pages');
        $pages[0]['content_json'] = json_encode(['sections' => ['content' => ['html' => '<script>window.evil=true</script><p onclick="evil()">Safe</p>']]]);
        $posts = $this->rows($archive, 'posts');
        $posts[0]['content'] = '<script>window.evil=true</script><p onclick="evil()">Safe</p>';
        $this->rewrite($archive, ['database/users.jsonl' => json_encode($users[0])."\n", 'database/pages.jsonl' => json_encode($pages[0])."\n", 'database/posts.jsonl' => json_encode($posts[0])."\n"]);
        $service->restore($archive);
        $this->assertFalse($user->fresh()->can_manage_custom_code);
        $this->assertNull($user->fresh()->remember_token);
        $this->assertSame('<p>Safe</p>', $page->fresh()->content_json['sections']['content']['html']);
        $this->assertSame('<p>Safe</p>', $post->fresh()->content);
    }

    public function test_rechecksummed_html_cannot_reference_missing_media_and_failure_rolls_back(): void
    {
        $page = Page::create(['title' => 'Original', 'slug' => 'original', 'template_key' => 'html', 'locale' => 'es', 'status' => 'draft']);
        $service = app(SnapshotService::class);
        $archive = $service->create('missing-media');
        $pages = $this->rows($archive, 'pages');
        $pages[0]['title'] = 'Tampered';
        $pages[0]['content_json'] = json_encode(['sections' => ['content' => ['html' => '<img data-media-id="99999">']]]);
        $this->rewrite($archive, ['database/pages.jsonl' => json_encode($pages[0])."\n"]);
        try {
            $service->restore($archive);
            $this->fail('A missing composition media ID must reject restore.');
        } catch (ValidationException) {
            $this->assertSame('Original', $page->fresh()->title);
        }
    }

    public function test_rechecksummed_executable_svg_is_rejected_before_database_or_upload_replacement(): void
    {
        $user = User::factory()->create(['name' => 'Destination']);
        $service = app(SnapshotService::class);
        $archive = $service->create('hostile-svg');
        $this->rewrite($archive, ['uploads/media/injected.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>']);
        try {
            $service->restore($archive);
            $this->fail('An executable SVG must reject restore even with correct checksums.');
        } catch (SnapshotException) {
            $this->assertSame('Destination', $user->fresh()->name);
            Storage::disk('public')->assertMissing('media/injected.svg');
        }
    }

    public function test_legacy_submission_archive_clears_destination_audit_attached_to_reused_ids(): void
    {
        $user = User::factory()->create();
        $submission = ContactSubmission::create(['name' => 'Visitor', 'email' => 'visitor@example.test', 'message' => 'Question']);
        $service = app(SnapshotService::class);
        $archive = $service->create('legacy-submissions');
        $manifest = $service->inspect($archive)->manifest;
        $manifest['database']['tables'] = array_values(array_filter($manifest['database']['tables'], fn ($table) => $table['name'] !== 'submission_events'));
        $this->rewrite($archive, ['manifest.json' => json_encode($manifest)]);
        DB::table('submission_events')->insert(['user_id' => $user->id, 'form' => 'contact', 'submission_id' => $submission->id, 'action' => 'update', 'before' => '{}', 'after' => '{}', 'created_at' => now()]);
        $service->restore($archive);
        $this->assertDatabaseCount('submission_events', 0);
    }

    public function test_rechecksummed_media_paths_cannot_escape_the_public_disk(): void
    {
        $media = Media::create(['filename' => 'safe.svg', 'original_filename' => 'safe.svg', 'path' => 'media/safe.svg', 'extension' => 'svg', 'mime_type' => 'image/svg+xml', 'size' => 100, 'disk' => 'public']);
        $service = app(SnapshotService::class);
        $archive = $service->create('unsafe-media-path');
        $rows = $this->rows($archive, 'media');
        $rows[0]['original_path'] = '../private/secret';
        $this->rewrite($archive, ['database/media.jsonl' => json_encode($rows[0])."\n"]);
        try {
            $service->restore($archive);
            $this->fail('A media path outside the public disk must reject restore.');
        } catch (SnapshotException) {
            $this->assertNull($media->fresh()->original_path);
        }
    }

    public function test_restore_orders_source_media_before_composition_even_for_legacy_manifest_order(): void
    {
        $media = Media::forceCreate(['id' => 420, 'filename' => 'source.svg', 'original_filename' => 'source.svg', 'path' => 'media/source.svg', 'extension' => 'svg', 'mime_type' => 'image/svg+xml', 'size' => 100, 'disk' => 'public']);
        $media->translations()->create(['locale' => 'en', 'alt' => 'Source translation']);
        $page = Page::create(['title' => 'Source composition', 'slug' => 'source-composition', 'template_key' => 'composed', 'locale' => 'es', 'status' => 'published', 'content_json' => ['sections' => ['composition' => ['blocks' => [
            ['id' => 'source-callout', 'type' => 'structured', 'component' => 'callout', 'params' => ['heading' => 'Source', 'image_id' => $media->id]],
        ]]]]]);
        $service = app(SnapshotService::class);
        $archive = $service->create('source-media-order');
        $manifest = $service->inspect($archive)->manifest;
        $manifest['database']['tables'] = collect($manifest['database']['tables'])->sortBy(fn ($table) => $table['name'] === 'pages' ? 0 : 1)->values()->all();
        $this->rewrite($archive, ['manifest.json' => json_encode($manifest)]);
        DB::table('media_translations')->delete();
        DB::table('media')->delete();
        Media::forceCreate(['id' => 900, 'filename' => 'destination.svg', 'original_filename' => 'destination.svg', 'path' => 'media/destination.svg', 'extension' => 'svg', 'mime_type' => 'image/svg+xml', 'size' => 100, 'disk' => 'public']);
        $service->restore($archive, ['diverged_history']);
        $this->assertDatabaseHas('media', ['id' => 420, 'filename' => 'source.svg']);
        $this->assertDatabaseMissing('media', ['id' => 900]);
        $this->assertDatabaseHas('media_translations', ['media_id' => 420, 'locale' => 'en', 'alt' => 'Source translation']);
        $this->assertSame(420, $page->fresh()->content_json['sections']['composition']['blocks'][0]['params']['image_id']);
    }
}
