<?php

use App\Models\ContactSubmission;
use App\Models\Locale;
use App\Models\McpApiKey;
use App\Models\Media;
use App\Models\Page;
use App\Models\User;
use App\Services\Snapshots\SnapshotService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Tester\CommandTester;

$app = require __DIR__.'/phase-ten-bootstrap.php';
$storage = getenv('PHASE0_STORAGE');
$action = $argv[1] ?? '';
function manifest(): array
{
    $tables = ['pages', 'posts', 'media', 'media_translations', 'mediables', 'taxonomies', 'taxables', 'menus', 'menu_items', 'locales', 'settings', 'setting_translations'];
    $rows = [];
    foreach ($tables as $table) {
        if (Schema::hasTable($table)) {
            $rows[$table] = DB::table($table)->orderBy(Schema::hasColumn($table, 'id') ? 'id' : Schema::getColumnListing($table)[0])->get()->map(fn ($r) => (array) $r)->all();
        }
    }
    $permissions = User::orderBy('id')->get()->map(fn ($u) => ['id' => $u->id, 'role' => $u->role])->all();
    $uploads = [];
    foreach (Storage::disk('public')->allFiles() as $file) {
        if (! str_ends_with($file, '.gitignore')) {
            $uploads[$file] = hash_file('sha256', Storage::disk('public')->path($file));
        }
    } ksort($uploads);
    $urls = Page::orderBy('id')->get()->map(fn ($p) => ['id' => $p->id, 'locale' => $p->locale, 'slug' => $p->slug, 'status' => $p->status, 'published_at' => $p->getRawOriginal('published_at'), 'url' => parse_url($p->localizedUrl(), PHP_URL_PATH)])->all();

    return ['rows' => $rows, 'permissions' => $permissions, 'uploads' => $uploads, 'urls' => $urls];
}
try {
    if ($action === 'install') {
        $config = config('database.connections.mysql');
        $config['database'] = null;
        config(['database.connections.create_fixture' => $config]);
        if (DB::connection('create_fixture')->select('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?', [getenv('PHASE0_DATABASE')])) {
            throw new RuntimeException('Refuse existing schema.');
        }
        $tester = new CommandTester(Artisan::all()['cms:install']);
        $tester->setInputs([config('database.connections.mysql.host'), (string) config('database.connections.mysql.port'), getenv('PHASE0_DATABASE'), config('database.connections.mysql.username'), config('database.connections.mysql.password') ?: '', 'es', 'Phase Ten', 'phase10@example.test', 'Phase10-Disposable-Only!']);
        if ($tester->execute([]) !== 0) {
            throw new RuntimeException('Isolated installer failed (private output).');
        }
        if (config('database.connections.mysql.database') !== getenv('PHASE0_DATABASE')) {
            throw new RuntimeException('Installer changed isolation.');
        }
        // The installer writes its documented default URL; fixture bootstrap overrides it on the next process.
        echo "PASS real cms:install on previously absent MySQL schema\n";
    } elseif ($action === 'baseline') {
        if (! Locale::where('code', 'en')->exists()) {
            Locale::create(['code' => 'en', 'name' => 'English', 'is_base' => false, 'is_active' => true, 'is_public' => true]);
        }
        $mid = Media::where('placeholder_key', 'horizontal')->firstOrFail()->id;
        $base = Page::create(['title' => 'Upgrade baseline ES', 'slug' => 'upgrade-baseline', 'template_key' => 'default', 'locale' => 'es', 'status' => 'published', 'published_at' => now()->subDay(), 'content_json' => ['meta' => ['title' => 'Migration SEO ES', 'description' => 'Preserved description', 'featured_image' => $mid], 'sections' => ['content' => ['heading' => 'Migration baseline ES', 'body' => '<p>Existing bilingual content</p>', 'is_visible' => true]]]]);
        Page::create(['title' => 'Upgrade baseline EN', 'slug' => 'upgrade-baseline-en', 'template_key' => 'default', 'locale' => 'en', 'translation_group_id' => $base->translation_group_id, 'status' => 'published', 'published_at' => now()->subDay(), 'content_json' => ['meta' => ['title' => 'Migration SEO EN', 'description' => 'English description'], 'sections' => ['content' => ['heading' => 'Migration baseline EN', 'body' => '<p>Existing English content</p>', 'is_visible' => true]]]]);
        foreach (['draft', 'scheduled'] as $status) {
            Page::create(['title' => 'Baseline '.$status, 'slug' => 'upgrade-'.$status, 'template_key' => 'default', 'locale' => 'es', 'status' => $status === 'scheduled' ? 'published' : 'draft', 'published_at' => $status === 'scheduled' ? now()->addDay() : null]);
        }
        User::factory()->create(['role' => 'editor', 'email' => 'phase10-editor@example.test']);
        file_put_contents($storage.'/baseline.json', json_encode(manifest(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo "PASS baseline bilingual/publication/media/permission manifest\n";
    } elseif ($action === 'manifest') {
        echo json_encode(manifest(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    } elseif ($action === 'portable-history') {
        $result = [];
        foreach (['editorial_versions', 'editorial_reviews', 'editorial_review_comments', 'content_revisions', 'custom_code_versions', 'contact_submission_notes', 'submission_events'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $rows = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            $result[$table] = ['rows' => count($rows), 'sha256' => hash('sha256', json_encode($rows))];
        }
        $result['proposals'] = Schema::hasTable('editorial_versions') ? DB::table('editorial_versions')->where('is_proposal', true)->count() : 0;
        echo json_encode($result);
    } elseif ($action === 'snapshot') {
        echo app(SnapshotService::class)->create($argv[2] ?? 'phase-ten');
    } elseif ($action === 'migrate') {
        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new RuntimeException('Migration failed.');
        }echo Artisan::output();
    } elseif ($action === 'cache') {
        foreach (['view:clear', 'config:cache', 'route:cache', 'queue:restart'] as $cmd) {
            if (Artisan::call($cmd) !== 0) {
                throw new RuntimeException('Cache/restart failed.');
            }
        }echo "PASS config/routes cache and queue restart signal\n";
    } elseif ($action === 'worker') {
        Artisan::call('queue:work', ['--stop-when-empty' => true, '--sleep' => 0]);
        echo 'PASS queue worker completed';
    } elseif ($action === 'keys') {
        $admin = User::where('email', 'phase10@example.test')->firstOrFail();
        if (Schema::hasColumn('users', 'can_manage_custom_code')) {
            $admin->forceFill(['can_manage_custom_code' => true])->save();
        }
        $keys = ['full' => McpApiKey::issue($admin, 'Phase ten full', 'full', now()->addHour())];
        if (Schema::hasColumn('users', 'can_manage_custom_code')) {
            $keys['custom-code'] = McpApiKey::issue($admin, 'Phase ten explicit code', 'custom-code', now()->addHour());
        }
        file_put_contents($storage.'/keys.json', json_encode($keys));
        chmod($storage.'/keys.json', 0600);
        echo 'PASS disposable scoped connections issued';
    } elseif ($action === 'inspect') {
        echo json_encode(app(SnapshotService::class)->inspect($argv[2])->toArray(), JSON_PRETTY_PRINT);
    } elseif ($action === 'restore') {
        $accepted = json_decode($argv[3] ?? '[]', true);
        echo json_encode(app(SnapshotService::class)->restore($argv[2], $accepted), JSON_PRETTY_PRINT);
    } elseif ($action === 'lifecycle') {
        $sub = ContactSubmission::create(['name' => 'Phase ten visitor', 'email' => 'visitor@example.test', 'message' => 'Portable history']);
        $sub->forceFill(['notification_status' => 'sending', 'notification_token' => 'old-generation', 'notification_claim' => 'old-claim'])->save();
        $sent = ContactSubmission::create(['name' => 'Already notified', 'email' => 'sent@example.test', 'message' => 'Do not repeat']);
        $sent->forceFill(['notification_status' => 'sent', 'notified_at' => now()])->save();
        echo 'PASS notification generations seeded for import assertions';
    } elseif ($action === 'verify-import') {
        foreach (['mcp_api_keys', 'oauth_access_tokens', 'oauth_authorization_codes', 'admin_sessions', 'editorial_preview_links', 'editorial_drafts', 'mcp_operation_receipts', 'editorial_operation_receipts', 'submission_operation_receipts', 'custom_code_receipts'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->count()) {
                throw new RuntimeException('Restored operational authority: '.$table);
            }
        }
        if (DB::table('jobs')->count() || DB::table('failed_jobs')->count()) {
            throw new RuntimeException('Destination queue not empty.');
        }
        if (User::where('can_manage_custom_code', true)->exists() || DB::table('custom_code_snippets')->where('state', 'active')->exists() || DB::table('custom_code_snippets')->whereNotNull('active_version_id')->exists()) {
            throw new RuntimeException('Restored executable authority.');
        }
        $pending = ContactSubmission::where('email', 'visitor@example.test')->firstOrFail();
        if ($pending->notification_status !== 'failed' || $pending->notification_token !== null || $pending->notification_claim !== null) {
            throw new RuntimeException('Pending delivery revived.');
        }
        if (ContactSubmission::where('email', 'sent@example.test')->firstOrFail()->notification_status !== 'sent') {
            throw new RuntimeException('Sent delivery changed.');
        }
        echo 'PASS receipts/credentials/previews/code inactive and notification generation reset';
    } elseif ($action === 'cleanup') {
        $config = config('database.connections.mysql');
        $config['database'] = null;
        config(['database.connections.drop_fixture' => $config]);
        DB::connection('drop_fixture')->statement('DROP DATABASE `'.getenv('PHASE0_DATABASE').'`');
        echo 'PASS guarded schema removed';
    } else {
        throw new RuntimeException('Unknown guarded action.');
    }

} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage().'\n');
    exit(1);
}
