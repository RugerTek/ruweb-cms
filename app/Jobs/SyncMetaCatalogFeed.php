<?php

namespace App\Jobs;

use App\Connectors\Meta\Graph\MetaGraphException;
use App\Services\Connectors\Meta\MetaCatalogSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Retries a Meta catalog sync step that failed because Meta did not answer (plan-meta-v2, track 2c): create or reuse
 * the scheduled feed (PROVISION), point it at the regenerated feed URL (REFRESH_URL) or ask Meta to read it now
 * (UPLOAD). It holds only the action: the connection and its token are read when it runs, so the token never sits in
 * the queue and a disconnection meanwhile makes it a no-op. Transient errors are released with backoff; after the last
 * try the connection records «Meta no respondió». It never throws to the caller (the sync driver runs it inline
 * and cannot wait, so there the failure is recorded at once).
 */
class SyncMetaCatalogFeed implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const PROVISION = 'provision';

    public const REFRESH_URL = 'refresh_url';

    public const UPLOAD = 'upload';

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public readonly string $action) {}

    public function handle(MetaCatalogSync $sync): void
    {
        try {
            $sync->runQueued($this->action);
        } catch (MetaGraphException $e) {
            // The sync driver cannot wait: it records the failure at once (retry with «Sincronizar ahora»).
            if ($this->job !== null && $this->job->getConnectionName() !== 'sync' && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 3600);

                return;
            }
            $sync->giveUp($e);
        }
    }
}
