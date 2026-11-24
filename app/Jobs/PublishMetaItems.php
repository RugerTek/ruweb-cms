<?php

namespace App\Jobs;

use App\Connectors\Meta\Graph\MetaGraphException;
use App\Services\Connectors\Meta\MetaItemsPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends the pending catalog changes of a connected Meta site as one `items_batch` (plan-meta-v2, track 3a; see
 * MetaItemsPublisher). Queued MetaItemsPublisher::DEBOUNCE_SECONDS after the first change so the next ones join it;
 * on the sync driver it runs once after the response. It holds nothing: the pending products are in the cache and the
 * token is read when the call is made, so neither sits in the queue and a disconnection meanwhile makes it a no-op.
 * Paused by Meta's usage, rate limited or Meta down → released for the seconds the publisher asks; after the last try
 * (or on the sync driver, which cannot wait) the connection records «Meta no respondió» and the products stay pending
 * for the next change (the hourly feed carries them anyway).
 */
class PublishMetaItems implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function handle(MetaItemsPublisher $publisher): void
    {
        $retryIn = $publisher->publishPending(max(1, $this->attempts()));
        if ($retryIn === null) {
            return;
        }
        if ($this->job !== null && $this->job->getConnectionName() !== 'sync' && $this->attempts() < $this->tries) {
            $this->release($retryIn);

            return;
        }
        $publisher->giveUp(new MetaGraphException('Meta asked to wait '.$retryIn.' s before the next items batch.', 0, MetaGraphException::CODE_CONNECTION, transient: true));
    }
}
