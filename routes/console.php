<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Snapshot transients (pending uploads, work folders, old pre-restore backups)
// would otherwise grow unbounded. See docs/operations/snapshots.md.
Schedule::command('snapshot:prune')->daily();

// Public scope hides expired content immediately; scheduler records the transition.
Artisan::command('cms:expire-content', function () {
    foreach ([\App\Models\Page::class, \App\Models\Post::class] as $type) {
        $type::where('status', 'published')->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->eachById(fn ($content) => $content->update(['status' => 'draft']));
    }
    \Illuminate\Support\Facades\DB::table('editorial_drafts')->where('expires_at', '<=', now())->delete();
})->purpose('Expire scheduled content and prune expired recovery drafts');
Schedule::command('cms:expire-content')->everyMinute()->withoutOverlapping();
