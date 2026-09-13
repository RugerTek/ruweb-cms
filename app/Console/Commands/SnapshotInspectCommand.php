<?php

namespace App\Console\Commands;

use App\Services\Snapshots\SnapshotException;
use App\Services\Snapshots\SnapshotReport;
use App\Services\Snapshots\SnapshotRisk;
use App\Services\Snapshots\SnapshotService;
use Illuminate\Console\Command;

class SnapshotInspectCommand extends Command
{
    protected $signature = 'snapshot:inspect {archive} {--json : Print the full report as JSON}';

    protected $description = 'Inspect a .appbackup snapshot against this installation without restoring it.';

    public function __construct(
        protected SnapshotService $snapshots
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $report = $this->snapshots->inspect((string) $this->argument('archive'));
        } catch (SnapshotException $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $report->blocking() === [] ? Command::SUCCESS : Command::FAILURE;
        }

        self::render($this, $report);

        return $report->blocking() === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Shared renderer so `snapshot:restore` prints the same report before
     * asking for confirmation.
     */
    public static function render(Command $command, SnapshotReport $report): void
    {
        $summary = $report->summary();

        $command->info('Snapshot');
        $command->table(['Field', 'Value'], [
            ['App version', ($summary['version'] ?? 'unknown').' → '.($report->destination['version'] ?? 'unknown')],
            ['Format version', $summary['format_version']],
            ['Created at', $summary['created_at'] ?? 'unknown'],
            ['Origin URL', $summary['origin_url'] ?? 'unknown'],
            ['Origin commit', $summary['origin_commit'] ?? 'unknown'],
            ['Driver', $summary['driver'] ?? 'unknown'],
            ['Tables / rows', $summary['tables'].' / '.$summary['rows']],
            ['Upload files', $summary['uploads_files']],
        ]);

        if ($report->risks === []) {
            $command->info('No risks found. The snapshot can be restored as is.');

            return;
        }

        foreach ($report->risks as $risk) {
            $label = match ($risk->severity) {
                SnapshotRisk::BLOCKING => '<fg=red>BLOCKING</>',
                SnapshotRisk::WARNING => '<fg=yellow>WARNING </>',
                default => '<fg=gray>INFO    </>',
            };

            $command->line("{$label} [{$risk->key}] {$risk->message}");
        }
    }
}
