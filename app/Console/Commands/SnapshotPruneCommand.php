<?php

namespace App\Console\Commands;

use App\Services\Snapshots\SnapshotHousekeeping;
use Illuminate\Console\Command;

class SnapshotPruneCommand extends Command
{
    protected $signature = 'snapshot:prune
        {--stale-hours=24 : Remove pending uploads and work folders older than this}
        {--keep-pre-restore=3 : Automatic pre-restore backups to keep}
        {--revision-days=90 : Content revisions older than this may be removed}
        {--revision-min=10000 : Content revisions always kept, whatever their age}';

    protected $description = 'Remove transient snapshot data: stale pending uploads, work folders and old pre-restore backups.';

    public function __construct(
        protected SnapshotHousekeeping $housekeeping
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $removed = $this->housekeeping->prune(
            (int) $this->option('stale-hours'),
            (int) $this->option('keep-pre-restore'),
            (int) $this->option('revision-days'),
            (int) $this->option('revision-min')
        );

        $this->table(
            ['Category', 'Files/rows removed', 'Bytes reclaimed'],
            collect($removed)->map(fn (array $stats, string $key): array => [$key, $stats['files'], $stats['bytes']])->values()->all()
        );

        return Command::SUCCESS;
    }
}
