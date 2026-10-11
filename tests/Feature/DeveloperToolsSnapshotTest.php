<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Services\Snapshots\SnapshotException;
use App\Services\Snapshots\SnapshotHousekeeping;
use App\Services\Snapshots\SnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class DeveloperToolsSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The snapshot root is an isolated temp directory set up by TestCase.
        Storage::fake('public');
        // Text fixtures represent a deployment that explicitly allows text uploads.
        config(['cms.upload.allowed_extensions' => [...config('cms.upload.allowed_extensions'), 'txt'],
            'cms.upload.allowed_document_mimes' => [...config('cms.upload.allowed_document_mimes'), 'text/plain']]);
    }

    public function test_admin_can_view_developer_tools(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('admin.developer-tools.index'));

        $response->assertOk();
        $response->assertSee('Developer tools');
        $response->assertSee('Sistema');
        $response->assertSee('Migración');
    }

    public function test_non_admin_cannot_access_developer_tools(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);

        $response = $this->actingAs($editor)->get(route('admin.developer-tools.index'));

        $response->assertForbidden();
    }

    public function test_snapshot_create_command_generates_backup_file(): void
    {
        User::factory()->create(['role' => 'admin']);
        Storage::disk('public')->put('media/example.txt', 'example');

        $exitCode = Artisan::call('snapshot:create', ['--name' => 'feature-test']);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists(SnapshotService::snapshotsDirectory().'/feature-test.appbackup');
    }

    public function test_download_uses_site_name_in_backup_filename(): void
    {
        Carbon::setTestNow('2026-05-04 17:18:39');
        Setting::query()->create([
            'key' => 'site_name',
            'value' => 'Mi Sitio Demo',
            'type' => 'string',
            'group' => 'general',
        ]);

        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->post(route('admin.developer-tools.download'));

        $response->assertOk();
        $response->assertHeader('content-disposition', 'attachment; filename=mi-sitio-demo-'.config('cms.version').'-20260504-171839.appbackup');

        Carbon::setTestNow();
    }

    public function test_restore_replaces_database_and_uploads(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'origin@example.com',
        ]);
        Media::query()->create([
            'filename' => 'origin.txt',
            'original_filename' => 'origin.txt',
            'path' => 'media/origin.txt',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size' => 6,
            'disk' => 'public',
            'uploaded_by' => $admin->id,
        ]);
        Storage::disk('public')->put('media/origin.txt', 'origin');

        $archive = app(SnapshotService::class)->create('restore-source');

        Media::query()->delete();
        User::query()->delete();
        User::factory()->create([
            'role' => 'admin',
            'email' => 'destination@example.com',
        ]);
        Storage::disk('public')->delete('media/origin.txt');
        Storage::disk('public')->put('media/old.txt', 'old');
        Storage::disk('public')->put('.gitignore', "*\n!.gitignore\n");

        app(SnapshotService::class)->restore($archive);

        $this->assertDatabaseHas('users', ['email' => 'origin@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'destination@example.com']);
        $this->assertDatabaseHas('media', ['path' => 'media/origin.txt']);
        Storage::disk('public')->assertExists('media/origin.txt');
        Storage::disk('public')->assertMissing('media/old.txt');
        Storage::disk('public')->assertExists('.gitignore');
    }

    public function test_snapshot_without_media_translations_clears_destination_overrides(): void
    {
        User::factory()->create(['role' => 'admin']);
        $media = Media::create([
            'filename' => 'shared.svg', 'original_filename' => 'shared.svg', 'path' => 'media/shared.svg',
            'mime_type' => 'image/svg+xml', 'extension' => 'svg', 'size' => 11, 'disk' => 'public',
            'alt' => 'Original base',
        ]);
        Storage::disk('public')->put($media->path, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        $archive = app(SnapshotService::class)->create('before-media-translations');
        $legacyArchive = $this->copyArchiveWithMutatedManifest($archive, function (array $manifest): array {
            $manifest['database']['tables'] = array_values(array_filter(
                $manifest['database']['tables'], fn ($table) => $table['name'] !== 'media_translations'
            ));
            unset($manifest['database']['schema']['media_translations']);

            return $manifest;
        });
        $media->translations()->create(['locale' => 'en', 'alt' => 'Destination-only override']);

        app(SnapshotService::class)->restore($legacyArchive);

        $this->assertDatabaseCount('media_translations', 0);
        $this->assertSame('Original base', $media->fresh()->localizedAlt('en'));
    }

    public function test_manifest_does_not_contain_schema_qualified_table_names(): void
    {
        User::factory()->create(['role' => 'admin']);

        $archive = app(SnapshotService::class)->create('manifest-test');
        $manifest = $this->readJsonFromZip($archive, 'manifest.json');

        foreach ($manifest['database']['tables'] as $table) {
            $this->assertStringNotContainsString('.', $table['name']);
        }
    }

    public function test_restore_rejects_schema_qualified_table_names(): void
    {
        User::factory()->create(['role' => 'admin']);

        $archive = app(SnapshotService::class)->create('unsafe-table');
        $unsafeArchive = $this->copyArchiveWithMutatedManifest($archive, function (array $manifest): array {
            $manifest['database']['tables'][0]['name'] = 'other_schema.'.$manifest['database']['tables'][0]['name'];

            return $manifest;
        });

        $this->expectException(SnapshotException::class);
        $this->expectExceptionMessage('schema-qualified');

        app(SnapshotService::class)->restore($unsafeArchive);
    }

    public function test_restore_keeps_destination_migration_history(): void
    {
        User::factory()->create(['role' => 'admin']);
        $archive = app(SnapshotService::class)->create('migrations-kept');

        // Simulate a destination that already ran a newer migration.
        DB::table('migrations')->insert([
            'migration' => '2099_01_01_000000_future_migration',
            'batch' => 99,
        ]);

        // The destination is now ahead of the snapshot, which is a warning to accept.
        app(SnapshotService::class)->restore($archive, ['older_snapshot']);

        $this->assertDatabaseHas('migrations', ['migration' => '2099_01_01_000000_future_migration']);
    }

    public function test_inspect_reports_legacy_format_as_warning_and_restore_requires_acceptance(): void
    {
        User::factory()->create(['role' => 'admin']);
        $archive = app(SnapshotService::class)->create('legacy');
        $legacy = $this->copyArchiveWithMutatedManifest($archive, function (array $manifest): array {
            $manifest['version'] = 1;

            return $manifest;
        });

        $report = app(SnapshotService::class)->inspect($legacy);

        $this->assertSame(['legacy_format'], array_map(fn ($risk) => $risk->key, $report->warnings()));
        $this->assertSame([], $report->blocking());
        $this->assertFalse($report->isRestorable([]));
        $this->assertTrue($report->isRestorable(['legacy_format']));

        try {
            app(SnapshotService::class)->restore($legacy);
            $this->fail('Restore should refuse unaccepted warnings.');
        } catch (SnapshotException $exception) {
            $this->assertStringContainsString('legacy_format', $exception->getMessage());
        }

        $result = app(SnapshotService::class)->restore($legacy, ['legacy_format']);
        $this->assertGreaterThan(0, $result['tables']);
    }

    public function test_inspect_blocks_when_destination_lacks_a_table(): void
    {
        User::factory()->create(['role' => 'admin']);
        $archive = app(SnapshotService::class)->create('missing-table');
        $mutated = $this->copyArchiveWithMutatedManifest($archive, function (array $manifest): array {
            $manifest['database']['tables'][] = ['name' => 'not_here_yet', 'file' => 'database/users.jsonl', 'rows' => 0];

            return $manifest;
        });

        $report = app(SnapshotService::class)->inspect($mutated);

        $this->assertSame(['missing_tables'], array_map(fn ($risk) => $risk->key, $report->blocking()));

        $this->expectException(SnapshotException::class);
        $this->expectExceptionMessage('not_here_yet');
        app(SnapshotService::class)->restore($mutated, ['missing_tables']);
    }

    public function test_restore_command_fails_on_unaccepted_warning_and_succeeds_with_yes(): void
    {
        User::factory()->create(['role' => 'admin']);
        $archive = app(SnapshotService::class)->create('cli');
        $legacy = $this->copyArchiveWithMutatedManifest($archive, fn (array $m): array => array_replace($m, ['version' => 1]));

        $this->assertSame(1, Artisan::call('snapshot:restore', ['archive' => $legacy]));
        $this->assertStringContainsString('legacy_format', Artisan::output());

        $this->assertSame(0, Artisan::call('snapshot:restore', ['archive' => $legacy, '--yes' => true]));
        $this->assertSame(0, Artisan::call('snapshot:inspect', ['archive' => $legacy, '--json' => true]));
        $this->assertSame('legacy_format', json_decode(Artisan::output(), true)['risks'][0]['key']);
    }

    public function test_admin_upload_flow_reviews_then_restores(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'origin@example.com']);
        Storage::disk('public')->put('media/flow.txt', 'flow');
        // A tracked write so the review screen has at least one risk to render.
        Page::query()->create(['locale' => 'es', 'title' => 'Flow', 'slug' => 'flow', 'template_key' => 'default', 'content_json' => [], 'status' => 'draft', 'created_by' => $admin->id]);
        $archive = app(SnapshotService::class)->create('ui-flow');

        Storage::disk('public')->delete('media/flow.txt');
        User::query()->delete();
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'destination@example.com']);

        $upload = new UploadedFile($archive, 'ui-flow.appbackup', 'application/octet-stream', null, true);
        $response = $this->actingAs($admin)->post(route('admin.developer-tools.upload'), ['backup' => $upload]);

        $response->assertRedirect();
        $token = basename($response->headers->get('Location'));
        $this->assertMatchesRegularExpression(SnapshotHousekeeping::PENDING_TOKEN_PATTERN, $token);

        // Nothing restored yet.
        $this->assertDatabaseHas('users', ['email' => 'destination@example.com']);
        Storage::disk('public')->assertMissing('media/flow.txt');

        $review = $this->actingAs($admin)->get(route('admin.developer-tools.review', ['token' => $token]));
        $review->assertOk();
        $review->assertSee('ui-flow.appbackup');
        $review->assertSee(__('admin.snapshot_risk_history_contained'));

        $index = $this->actingAs($admin)->get(route('admin.developer-tools.index', ['tab' => 'migration']));
        $index->assertSee('ui-flow.appbackup');

        $restore = $this->actingAs($admin)->post(route('admin.developer-tools.restore', ['token' => $token]));
        $restore->assertRedirect(route('admin.developer-tools.index', ['tab' => 'migration']));
        $restore->assertSessionHas('success');

        $this->assertDatabaseHas('users', ['email' => 'origin@example.com']);
        Storage::disk('public')->assertExists('media/flow.txt');
        $this->assertNull(app(SnapshotHousekeeping::class)->pending($token));
    }

    public function test_review_rejects_unknown_or_malformed_tokens(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.developer-tools.review', ['token' => str_repeat('a', 40)]))
            ->assertRedirect(route('admin.developer-tools.index', ['tab' => 'migration']))
            ->assertSessionHas('error');

        // Malformed tokens never reach the controller.
        $this->actingAs($admin)->get('/admin/developer-tools/review/not-a-valid-token')->assertNotFound();
    }

    public function test_prune_removes_stale_transients_and_keeps_newest_pre_restore_backups(): void
    {
        User::factory()->create(['role' => 'admin']);
        $service = app(SnapshotService::class);
        $directory = SnapshotService::snapshotsDirectory();

        foreach (['a', 'b', 'c', 'd', 'e'] as $index => $suffix) {
            $path = $service->create('pre-restore-'.$suffix);
            touch($path, time() - (10 - $index) * 60);
        }
        $service->create('keep-me');

        $stale = SnapshotService::workDirectory().'/snapshot-stale';
        File::ensureDirectoryExists($stale);
        File::put($stale.'/x.jsonl', 'x');
        touch($stale, time() - 48 * 3600);

        $fresh = SnapshotService::workDirectory().'/snapshot-fresh';
        File::ensureDirectoryExists($fresh);
        File::put($fresh.'/x.jsonl', 'x');

        $removed = app(SnapshotHousekeeping::class)->prune(24, 3);

        $this->assertSame(2, $removed['pre_restore_backups']['files']);
        $this->assertSame(1, $removed['work_dirs']['files']);
        $this->assertFileExists($directory.'/pre-restore-e.appbackup');
        $this->assertFileExists($directory.'/pre-restore-c.appbackup');
        $this->assertFileDoesNotExist($directory.'/pre-restore-a.appbackup');
        $this->assertFileExists($directory.'/keep-me.appbackup');
        $this->assertDirectoryDoesNotExist($stale);
        $this->assertDirectoryExists($fresh);
    }

    public function test_manifest_records_version_migrations_and_schema(): void
    {
        User::factory()->create(['role' => 'admin']);

        $archive = app(SnapshotService::class)->create('versioned');
        $manifest = $this->readJsonFromZip($archive, 'manifest.json');

        $this->assertSame(config('cms.version'), $manifest['app']['version']);
        $this->assertContains('0001_01_01_000000_create_users_table', $manifest['database']['migrations']);
        $this->assertContains('email', $manifest['database']['schema']['users']);
    }

    public function test_newer_snapshot_is_blocking(): void
    {
        User::factory()->create(['role' => 'admin']);
        $archive = app(SnapshotService::class)->create('newer');
        $newer = $this->copyArchiveWithMutatedManifest($archive, function (array $m): array {
            $m['app']['version'] = '99.0.0';

            return $m;
        });

        $report = app(SnapshotService::class)->inspect($newer);

        $this->assertSame(['newer_snapshot'], array_map(fn ($r) => $r->key, $report->blocking()));
    }

    public function test_older_snapshot_warns_and_lists_missing_migrations(): void
    {
        User::factory()->create(['role' => 'admin']);
        $archive = app(SnapshotService::class)->create('older');
        $older = $this->copyArchiveWithMutatedManifest($archive, function (array $m): array {
            $m['app']['version'] = '0.1.0';
            array_pop($m['database']['migrations']);

            return $m;
        });

        $report = app(SnapshotService::class)->inspect($older);
        $warnings = collect($report->warnings())->keyBy('key');

        $this->assertSame([], $report->blocking());
        $this->assertTrue($warnings->has('older_snapshot'));
        $this->assertCount(1, $warnings['older_snapshot']->details['migrations']);
        $this->assertFalse($report->isRestorable([]));
        $this->assertTrue($report->isRestorable(['older_snapshot']));
    }

    public function test_snapshot_with_unknown_migrations_or_columns_is_blocking(): void
    {
        User::factory()->create(['role' => 'admin']);
        $archive = app(SnapshotService::class)->create('unknown-schema');
        $mutated = $this->copyArchiveWithMutatedManifest($archive, function (array $m): array {
            $m['database']['migrations'][] = '2099_01_01_000000_from_the_future';
            $m['database']['schema']['users'][] = 'column_not_here';

            return $m;
        });

        $report = app(SnapshotService::class)->inspect($mutated);
        $keys = array_map(fn ($r) => $r->key, $report->blocking());

        $this->assertContains('unknown_migrations', $keys);
        $this->assertContains('missing_columns', $keys);
    }

    public function test_snapshot_without_version_warns(): void
    {
        User::factory()->create(['role' => 'admin']);
        $archive = app(SnapshotService::class)->create('no-version');
        $mutated = $this->copyArchiveWithMutatedManifest($archive, function (array $m): array {
            unset($m['app']['version']);

            return $m;
        });

        $report = app(SnapshotService::class)->inspect($mutated);

        $this->assertContains('unknown_origin_version', array_map(fn ($r) => $r->key, $report->warnings()));
    }

    public function test_restore_rewrites_origin_urls_in_text_and_json_columns(): void
    {
        config(['app.url' => 'http://localhost:8000']);
        $admin = User::factory()->create(['role' => 'admin']);

        $post = Post::query()->create([
            'locale' => 'es',
            'title' => 'Hola',
            'slug' => 'hola',
            'content' => '<p><a href="https://external.test/x">out</a></p>',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);
        // Bypass the sanitizer so the row really carries an absolute origin, as legacy data does.
        DB::table('posts')->where('id', $post->id)->update([
            'content' => '<p><img src="http://localhost:8000/storage/media/a.webp"><a href="https://external.test/x">out</a></p>',
        ]);
        Page::query()->create([
            'locale' => 'es',
            'title' => 'Inicio',
            'slug' => 'inicio',
            'template_key' => 'default',
            'content_json' => ['sections' => ['content' => ['is_visible' => 1, 'body' => 'See http://localhost:8000/blog now']]],
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);
        $this->assertStringContainsString('http:\\/\\/localhost:8000', (string) DB::table('pages')->value('content_json'));

        $archive = app(SnapshotService::class)->create('urls');

        config(['app.url' => 'https://proyecto.com']);
        $result = app(SnapshotService::class)->restore($archive);

        $this->assertSame(['from' => 'http://localhost:8000', 'to' => 'https://proyecto.com'], $result['url_rewritten']);
        $content = (string) DB::table('posts')->value('content');
        $this->assertStringContainsString('/storage/media/a.webp', $content);
        $this->assertStringContainsString('https://external.test/x', $content);
        $this->assertStringNotContainsString('localhost:8000', $content);
        $this->assertSame('See https://proyecto.com/blog now', Page::query()->first()->content_json['sections']['content']['body']);
    }

    public function test_rewrite_url_command_updates_live_rows(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $post = Post::query()->create([
            'locale' => 'es', 'title' => 'A', 'slug' => 'a', 'content' => 'plain', 'status' => 'draft', 'created_by' => $admin->id,
        ]);
        DB::table('posts')->where('id', $post->id)->update(['content' => 'see http://old.test/p']);

        $this->assertSame(0, Artisan::call('cms:rewrite-url', ['--from' => 'http://old.test', '--to' => 'https://new.test', '--dry-run' => true]));
        $this->assertSame('see http://old.test/p', DB::table('posts')->value('content'));

        $this->assertSame(0, Artisan::call('cms:rewrite-url', ['--from' => 'http://old.test', '--to' => 'https://new.test']));
        $this->assertSame('see https://new.test/p', DB::table('posts')->value('content'));
    }

    public function test_srcset_builds_variant_urls_from_paths_at_render_time(): void
    {
        // The public disk is faked in setUp, so its URLs are root-relative here.
        $media = new Media([
            'disk' => 'public', 'path' => 'media/a.webp', 'mime_type' => 'image/webp',
            'variants' => [
                ['width' => 480, 'path' => 'media/variants/a-480.webp'],
                ['width' => 240, 'url' => 'http://localhost:8000/storage/media/variants/a-240.webp'],
            ],
        ]);

        $srcset = $media->srcset();

        $this->assertStringStartsWith('http://localhost:8000/storage/media/variants/a-240.webp 240w, ', $srcset);
        $this->assertStringEndsWith('/storage/media/variants/a-480.webp 480w', $srcset);
    }

    public function test_history_contained_is_reported_as_a_clean_fast_forward(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Page::query()->create(['locale' => 'es', 'title' => 'A', 'slug' => 'a', 'template_key' => 'default', 'content_json' => [], 'status' => 'draft', 'created_by' => $admin->id]);

        // Snapshot taken after the destination's changes: it contains all of them.
        $archive = app(SnapshotService::class)->create('ff');
        $report = app(SnapshotService::class)->inspect($archive);

        $this->assertSame([], $report->warnings());
        $this->assertContains('history_contained', array_map(fn ($r) => $r->key, $report->info()));
        $this->assertGreaterThan(0, $report->manifest['history']['count']);
    }

    public function test_diverged_history_warns_and_lists_the_changes_that_would_be_lost(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'editor@example.com']);
        $this->actingAs($admin);
        Page::query()->create(['locale' => 'es', 'title' => 'Base', 'slug' => 'base', 'template_key' => 'default', 'content_json' => [], 'status' => 'draft', 'created_by' => $admin->id]);
        $archive = app(SnapshotService::class)->create('base');

        // Destination keeps editing after the snapshot was taken.
        $page = Page::query()->create(['locale' => 'es', 'title' => 'Late', 'slug' => 'late', 'template_key' => 'default', 'content_json' => [], 'status' => 'draft', 'created_by' => $admin->id]);
        $page->update(['title' => 'Later']);

        $report = app(SnapshotService::class)->inspect($archive);
        $risk = collect($report->warnings())->firstWhere('key', 'diverged_history');

        $this->assertNotNull($risk);
        $this->assertSame(2, $risk->details['diverged']);
        $this->assertSame('updated', $risk->details['changes'][0]['action']);
        $this->assertSame('Page#'.$page->id, $risk->details['changes'][0]['entity']);
        $this->assertSame('editor@example.com', $risk->details['changes'][0]['user']);
        $this->assertFalse($report->isRestorable([]));

        app(SnapshotService::class)->restore($archive, ['diverged_history']);

        // The destination adopted the snapshot's ledger, so a second inspect is clean.
        $this->assertDatabaseMissing('pages', ['slug' => 'late']);
        $this->assertContains('history_contained', array_map(fn ($r) => $r->key, app(SnapshotService::class)->inspect($archive)->info()));
    }

    public function test_snapshot_without_ledger_warns_about_unknown_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Page::query()->create(['locale' => 'es', 'title' => 'X', 'slug' => 'x', 'template_key' => 'default', 'content_json' => [], 'status' => 'draft', 'created_by' => $admin->id]);
        $archive = app(SnapshotService::class)->create('no-ledger');
        $mutated = $this->copyArchiveWithMutatedManifest($archive, function (array $m): array {
            $m['database']['tables'] = array_values(array_filter($m['database']['tables'], fn ($t) => $t['name'] !== 'content_revisions'));
            unset($m['database']['schema']['content_revisions']);

            return $m;
        });

        $report = app(SnapshotService::class)->inspect($mutated);

        $this->assertContains('unknown_history', array_map(fn ($r) => $r->key, $report->warnings()));
    }

    public function test_snapshot_paths_stay_inside_the_configured_root(): void
    {
        $root = config('cms.snapshots.root');

        $this->assertStringStartsWith(sys_get_temp_dir(), $root);

        foreach ([
            SnapshotService::snapshotsDirectory(),
            SnapshotService::pendingUploadsDirectory(),
            SnapshotService::downloadsDirectory(),
            SnapshotService::workDirectory(),
            SnapshotService::restoreDirectory(),
            SnapshotService::uploadsRollbackDirectory(),
        ] as $path) {
            $this->assertStringStartsWith($root.'/', $path);
        }

        User::factory()->create(['role' => 'admin']);
        app(SnapshotService::class)->create('inside-root');

        $this->assertFileExists($root.'/snapshots/inside-root.appbackup');
        $this->assertFileDoesNotExist(storage_path('app/private/snapshots/inside-root.appbackup'));
    }

    protected function readJsonFromZip(string $archive, string $path): array
    {
        $zip = new ZipArchive;
        $zip->open($archive);

        try {
            return json_decode($zip->getFromName($path), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            $zip->close();
        }
    }

    protected function copyArchiveWithMutatedManifest(string $archive, callable $mutator): string
    {
        File::ensureDirectoryExists(SnapshotService::rootDirectory().'/test-mutate');
        $sourceDir = SnapshotService::rootDirectory().'/test-mutate/'.uniqid('source-', true);
        $target = SnapshotService::rootDirectory().'/test-mutate/mutated-'.uniqid().'.appbackup';
        File::ensureDirectoryExists($sourceDir);

        $zip = new ZipArchive;
        $zip->open($archive);
        $zip->extractTo($sourceDir);
        $zip->close();

        $manifestPath = $sourceDir.'/manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        file_put_contents($manifestPath, json_encode($mutator($manifest), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $checksums = [];
        foreach (File::allFiles($sourceDir) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            if ($relative !== 'checksums.json') {
                $checksums[$relative] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($checksums);
        file_put_contents($sourceDir.'/checksums.json', json_encode($checksums, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $newZip = new ZipArchive;
        $newZip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach (File::allFiles($sourceDir) as $file) {
            $newZip->addFile($file->getPathname(), str_replace('\\', '/', $file->getRelativePathname()));
        }
        $newZip->close();

        File::deleteDirectory($sourceDir);

        return $target;
    }
}
