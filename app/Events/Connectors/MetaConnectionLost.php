<?php

namespace App\Events\Connectors;

use App\Connectors\Meta\Connect\MetaConnection;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The Meta v2 connection just went to needs_reconnect (plan-meta-v2, track 3c): Meta revoked the token (190/102) or
 * the client unshared the chosen catalog (100/33), seen by the daily health check (MetaHealthCheckCommand), the feed
 * sync (MetaCatalogSync, 2c) or items_batch (3a). Dispatched by MetaConnectionStore::markNeedsReconnect() only on the
 * transition (never again while the reconnection is pending). $connection holds no secret; $reason is the text kept
 * as the connection's last error, in the panel language.
 */
final class MetaConnectionLost
{
    use Dispatchable;

    public function __construct(
        public readonly MetaConnection $connection,
        public readonly string $reason,
    ) {}
}
