<?php

namespace App\Console\Commands;

use App\Models\SupportAudit;
use App\Models\SupportSession;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Retention of the support record (docs/operations/support-access.md#retention): sessions that started more than
 * cms.support_access.retention_days ago go with their activity, and used pass ids are forgotten once the pass could
 * no longer be valid. Support accounts stay (their names keep the editorial history readable). Scheduled daily.
 */
class SupportPruneCommand extends Command
{
    protected $signature = 'support:prune';

    protected $description = 'Delete support sessions and activity older than the retention period, and expired pass ids';

    public function handle(): int
    {
        $days = max(1, (int) config('cms.support_access.retention_days', 365));
        $cutoff = Carbon::now()->subDays($days);
        $sessions = 0;
        SupportSession::query()->where('started_at', '<', $cutoff)->select('id')->chunkById(500, function ($chunk) use (&$sessions): void {
            $ids = $chunk->pluck('id')->all();
            SupportAudit::query()->whereIn('session_id', $ids)->delete();
            $sessions += SupportSession::query()->whereIn('id', $ids)->delete();
        });
        $passes = DB::table('support_pass_jtis')->where('expires_at', '<', Carbon::now())->delete();

        $this->line("Support sessions deleted: {$sessions} (older than {$days} days). Expired pass ids forgotten: {$passes}.");

        return self::SUCCESS;
    }
}
