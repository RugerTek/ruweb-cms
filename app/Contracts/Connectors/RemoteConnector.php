<?php

namespace App\Contracts\Connectors;

/**
 * A connector that works through a connection made with an external account (Meta v2 «Conectar con Facebook»), which
 * the other side can revoke. ConnectorRegistry::status() reports needs_reconnect while needsReconnect() is true
 * (after unmet requirements, before connected).
 */
interface RemoteConnector extends Connector
{
    /** The stored connection was revoked by the other side and the administrator must connect again. */
    public function needsReconnect(): bool;
}
