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
