<?php

namespace App\Console\Commands;

use App\Services\Snapshots\EnvironmentReportService;
use App\Services\Snapshots\UrlRewriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rewrite a site origin inside stored content on a live database, for
 * installations that moved domain without going through a snapshot.
 */
class RewriteUrlCommand extends Command
{
    protected $signature = 'cms:rewrite-url
        {--from= : Origin to replace, e.g. http://localhost:8000}
        {--to= : Origin to write instead (defaults to APP_URL)}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Replace one site origin with another inside every stored text and JSON column.';

    /** Tables whose contents never carry site URLs and must not be touched. */
    protected const SKIPPED = ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions', 'password_reset_tokens', 'users'];

    public function __construct(
        protected EnvironmentReportService $environment
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $rewriter = UrlRewriter::between((string) $this->option('from'), (string) ($this->option('to') ?: config('app.url')));

        if (! $rewriter) {
            $this->error('Provide --from and --to with two different origins.');

            return Command::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info(($dryRun ? '[dry-run] ' : '')."Rewriting {$rewriter->from()} → {$rewriter->to()}");

        $summary = [];

        foreach ($this->environment->currentSchemaTables() as $table) {
            if (in_array($table, self::SKIPPED, true)) {
                continue;
            }

            if (! Schema::hasColumn($table, 'id')) {
                $this->warn("Skipping {$table}: no id column.");

                continue;
            }

            $changed = 0;

            foreach (DB::table($table)->orderBy('id')->cursor() as $row) {
                $original = (array) $row;
                $rewritten = $rewriter->rewriteRow($original);

                if ($rewritten === $original) {
                    continue;
                }

                $changed++;

                if (! $dryRun) {
                    DB::table($table)->where('id', $original['id'])->update(array_diff_assoc($rewritten, $original));
                }
            }

            if ($changed > 0) {
                $summary[] = [$table, $changed];
            }
        }

        if ($summary === []) {
            $this->info('Nothing to rewrite.');
        } else {
            $this->table(['Table', 'Rows '.($dryRun ? 'that would change' : 'updated')], $summary);
        }

        if (! $dryRun) {
            $this->call('optimize:clear');
        }

        return Command::SUCCESS;
    }
}
