<?php

use App\Models\Page;
use App\Models\Post;
use App\Support\AdminCapabilities;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Snapshot transients (pending uploads, work folders, old pre-restore backups)
// would otherwise grow unbounded. See docs/operations/snapshots.md.
Schedule::command('snapshot:prune')->daily();

// Public scope hides expired content immediately; scheduler records the transition.
Artisan::command('cms:expire-content', function () {
    foreach ([Page::class, Post::class] as $type) {
        $type::where('status', 'published')->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->eachById(fn ($content) => $content->update(['status' => 'draft']));
    }
    DB::table('editorial_drafts')->where('expires_at', '<=', now())->delete();
})->purpose('Expire scheduled content and prune expired recovery drafts');
Schedule::command('cms:expire-content')->everyMinute()->withoutOverlapping();

Artisan::command('cms:capabilities', function () {
    $this->line(json_encode(app(AdminCapabilities::class)->discovery(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
})->purpose('Discover deployment profile, capabilities and template contracts without secrets');

// No database or frontend request is needed to recover a broken public layout.
Artisan::command('cms:custom-code {action=status : disable, enable or status}', function () {
    $path = storage_path('app/private/custom-code.disabled');
    $action = $this->argument('action');
    if (! in_array($action, ['disable', 'enable', 'status'], true)) { $this->error('Use disable, enable or status.'); return 1; }
    if ($action === 'disable') { if (! is_dir(dirname($path))) mkdir(dirname($path), 0750, true); file_put_contents($path, now()->toISOString(), LOCK_EX); }
    if ($action === 'enable' && is_file($path)) unlink($path);
    $this->line(app(\App\Support\CustomCodeRenderer::class)->enabled() ? 'Custom Code enabled.' : 'Custom Code disabled.');
})->purpose('Recover public rendering by disabling all Custom Code without accessing the frontend');
