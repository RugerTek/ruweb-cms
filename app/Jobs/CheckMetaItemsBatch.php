<?php

namespace App\Jobs;

use App\Connectors\Meta\Graph\MetaGraphException;
use App\Services\Connectors\Meta\MetaItemsPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Reads `check_batch_request_status` of the items_batch handles PublishMetaItems sent (plan-meta-v2, track 3a) and
 * hands their errors and warnings to the panel (MetaItemsPublisher::checkBatches()). Meta processes a batch in
 * seconds to minutes: while it is not finished, or Meta does not answer, the job is released with BACKOFF. It holds
 * only the handles and the retailer ids they carried (never the token). On the sync driver it cannot wait: an
 * unfinished batch is left for the hourly feed's upload report.
 */
class CheckMetaItemsBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Seconds after the batch before the first check. */
    public const FIRST_CHECK_SECONDS = 10;

    public int $tries = 7;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120, 300, 900];

    /**
     * @param  list<string>  $handles
     * @param  list<string>  $retailerIds  every retailer id the handles carried
     * @param  list<string>  $deletedIds  the ones sent as DELETE
     */
    public function __construct(
        public readonly array $handles,
        public readonly array $retailerIds = [],
        public readonly array $deletedIds = [],
    ) {}

    public function handle(MetaItemsPublisher $publisher): void
    {
        try {
            if ($publisher->checkBatches($this->handles, $this->retailerIds, $this->deletedIds)) {
                return;
            }
            $reason = 'Meta is still processing the items batch.';
        } catch (MetaGraphException $e) {
            $reason = 'Meta did not answer the items batch status: '.$e->getMessage();
        }
        if ($this->job !== null && $this->job->getConnectionName() !== 'sync' && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 900);

            return;
        }
        Log::info('Meta items batch status left unread.', ['reason' => $reason, 'handles' => count($this->handles)]);
    }
}
