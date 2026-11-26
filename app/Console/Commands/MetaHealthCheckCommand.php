<?php

namespace App\Console\Commands;

use App\Connectors\Meta\Connect\MetaConnection;
use App\Connectors\Meta\Connect\MetaConnectionStore;
use App\Connectors\Meta\Graph\MetaGraphException;
use App\Contracts\Connectors\MetaGraphClient;
use App\Services\Connectors\Meta\MetaCatalogSync;
use App\Services\Connectors\Meta\MetaSyncStatus;
use App\Services\Connectors\Meta\MetaUnreachableStreak;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Daily health check of the Meta v2 connection («Conectar con Facebook», plan-meta-v2 track 3c), scheduled by
 * routes/console.php. Only in connected mode with a working token (manual v1 sites and pending reconnections: nothing).
 *
 * 1. `GET /me`: 190 (any subcode) / 102 → the client removed the app: needs_reconnect.
 * 2. `GET /{catalog}` of the chosen catalog: 100/33 → the client unshared it: needs_reconnect, with the reason.
 * 3. The Ruweb feed, read through MetaCatalogSync::status() (fresh): a feed deleted in Commerce Manager is flagged
 *    there (never recreated here; «Sincronizar ahora» does it).
 * 4. All fine → MetaConnectionStore::recordHealth(null).
 *
 * Meta not answering (network, 5xx, rate limits) or any other error never drops the token: it only records the last
 * error. From UNREACHABLE_DAYS consecutive days on (MetaUnreachableStreak) the detail shows «Meta no responde desde
 * hace N días; revisá la conexión del servidor» and the administrators get one email, but the connection stays and
 * recovers by itself the first day Meta answers. Only an explicit rejection (190/102, 100/33) goes to needs_reconnect,
 * which dispatches MetaConnectionLost (MetaConnectionStore): one «Volver a conectar» email. No token is printed or
 * logged (MetaGraphException never carries it).
 */
class MetaHealthCheckCommand extends Command
{
    /** Consecutive days without a successful check before the warning and the email (the connection is kept). */
    public const UNREACHABLE_DAYS = MetaUnreachableStreak::WARN_DAYS;

    protected $signature = 'meta:health-check';

    protected $description = 'Daily check of the Meta connection («Conectar con Facebook»): token, chosen catalog and feed.';

    private MetaUnreachableStreak $streak;

    public function handle(MetaConnectionStore $store, MetaGraphClient $graph, MetaCatalogSync $sync, MetaUnreachableStreak $streak): int
    {
        $this->streak = $streak;
        $connection = $store->get();
        if (! $connection->isConnected()) {
            $this->line('Meta: manual mode, nothing to check.');

            return self::SUCCESS;
        }
        if ($connection->needsReconnect()) {
            $this->line('Meta: waiting for the client to connect again.');

            return self::SUCCESS;
        }

        try {
            $graph->me();
            if ($connection->catalogId !== null) {
                $graph->catalog($connection->catalogId);
            }
        } catch (MetaGraphException $e) {
            if ($e->revokesConnection()) {
                return $this->lost($store, $sync, $connection, MetaCatalogSync::text('meta_sync.error_revoked'));
            }
            if ($e->objectUnavailable() && $connection->catalogId !== null) {
                $name = $connection->catalogName ?? $connection->catalogId;

                return $this->lost($store, $sync, $connection, MetaCatalogSync::text('meta_sync.error_catalog_unavailable', ['catalog' => $name]));
            }
            Log::warning('Meta health check failed.', ['code' => $e->graphCode, 'subcode' => $e->subcode, 'http_status' => $e->httpStatus, 'message' => $e->getMessage(), 'fbtrace_id' => $e->fbtraceId]);

            return $this->failed($store, $sync, $connection, $e->isTransient() || $e->isConnectionFailure() ? null : $e->displayMessage());
        }

        $message = null;
        if ($connection->catalogId !== null && $connection->productFeedId !== null) {
            $sync->forgetStatus();
            $status = $sync->status();
            if ($status->state === MetaSyncStatus::NEEDS_RECONNECT) {
                $this->forgetFailures($connection);
                $this->line('Meta: the connection was cut; the administrators were told to connect again.');

                return self::SUCCESS;
            }
            if ($status->state === MetaSyncStatus::UNAVAILABLE) {
                return $this->failed($store, $sync, $connection, null);
            }
            if ($status->state === MetaSyncStatus::NO_FEED) {
                $message = $status->message;
            }
        }

        $store->recordHealth($message);
        $sync->forgetStatus();
        $this->forgetFailures($connection);
        $this->line($message === null ? 'Meta: connection OK.' : 'Meta: connection OK; the Ruweb feed is missing in the catalog.');

        return self::SUCCESS;
    }

    private function lost(MetaConnectionStore $store, MetaCatalogSync $sync, MetaConnection $connection, string $reason): int
    {
        $store->markNeedsReconnect($reason);
        $sync->forgetStatus();
        $this->forgetFailures($connection);
        $this->line('Meta: the connection was cut; the administrators were told to connect again.');

        return self::SUCCESS;
    }

    /**
     * Meta did not answer (or answered another error, $metaMessage): keep it as the last error and count the day. From
     * UNREACHABLE_DAYS consecutive days on: warning in the panel + one email; the token and the state stay.
     */
    private function failed(MetaConnectionStore $store, MetaCatalogSync $sync, MetaConnection $connection, ?string $metaMessage): int
    {
        $days = $this->streak->fail($connection);
        if ($days >= self::UNREACHABLE_DAYS) {
            $store->recordHealth(MetaCatalogSync::text('meta_health.error_unreachable_days', ['days' => $days]));
            $this->streak->mailOnce($connection, $days);
        } else {
            $detail = $metaMessage ?? MetaCatalogSync::text('meta_sync.state_unavailable');
            $store->recordHealth(MetaCatalogSync::text('meta_health.error_unreachable', ['message' => $detail]));
        }
        $sync->forgetStatus();
        $this->warn('Meta: could not check the connection ('.$days.' day(s) in a row; the connection is kept).');

        return self::SUCCESS;
    }

    private function forgetFailures(MetaConnection $connection): void
    {
        $this->streak->reset($connection);
    }
}
