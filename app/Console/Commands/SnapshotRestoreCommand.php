<?php

namespace App\Console\Commands;

use App\Services\Snapshots\SnapshotException;
use App\Services\Snapshots\SnapshotRisk;
use App\Services\Snapshots\SnapshotService;
use Illuminate\Console\Command;

class SnapshotRestoreCommand extends Command
{
    protected $signature = 'snapshot:restore {archive}
        {--accept=* : Risk key to accept (repeatable), e.g. --accept=driver_mismatch}
        {--yes : Accept every warning without asking}
        {--force : Alias of --yes, kept for older scripts}';

    protected $description = 'Restore a portable .appbackup snapshot into the current database and public uploads.';

    public function __construct(
        protected SnapshotService $snapshots
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $archive = (string) $this->argument('archive');

        try {
            $report = $this->snapshots->inspect($archive);
        } catch (SnapshotException $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        SnapshotInspectCommand::render($this, $report);
        $this->newLine();

        if ($report->blocking() !== []) {
            $this->error('The snapshot cannot be restored until the blocking issues above are resolved.');

            return Command::FAILURE;
        }

        $accepted = array_values(array_filter(array_map('strval', (array) $this->option('accept'))));

        if ($this->option('yes') || $this->option('force')) {
            $accepted = array_map(fn (SnapshotRisk $risk): string => $risk->key, $report->warnings());
        }

        foreach ($report->pendingWarnings($accepted) as $risk) {
            if (! $this->input->isInteractive()) {
                $this->error("Unaccepted risk [{$risk->key}]. Re-run with --accept={$risk->key} or --yes.");

                return Command::FAILURE;
            }

            if (! $this->confirm("Accept risk [{$risk->key}] and continue?", false)) {
                $this->warn('Restore cancelled.');

                return Command::FAILURE;
            }

            $accepted[] = $risk->key;
        }

        if ($this->input->isInteractive() && ! $this->option('yes') && ! $this->option('force')) {
            if (! $this->confirm('This replaces the destination database and uploads. Continue?', false)) {
                $this->warn('Restore cancelled.');

                return Command::FAILURE;
            }
        }

        try {
            $result = $this->snapshots->restore($archive, $accepted);
        } catch (SnapshotException $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $this->info('Snapshot restored.');
        $this->line('Pre-restore backup: '.$result['pre_restore_backup']);
        $this->line('Tables restored: '.$result['tables']);
        $this->line('Upload files restored: '.$result['uploads_files']);

        return Command::SUCCESS;
    }
}
