<?php

namespace App\Services\Connectors\Meta;

use RuntimeException;

/**
 * Why MetaCatalogSync could not do what the panel asked (plan-meta-v2, track 2c). The message is already a text for
 * the store owner in the panel language (lang/{es,en}/meta_sync.php), safe to flash: it never carries the token. Meta being unreachable is NOT
 * one of these: the sync is queued (SyncMetaCatalogFeed, with backoff) and the call returns normally.
 */
final class MetaCatalogSyncException extends RuntimeException
{
    /** There is no usable connection (manual mode, or Meta revoked it): connect first. */
    public const NOT_CONNECTED = 'not_connected';

    /** Meta answered 190/102: the client removed the app. The connection is now needs_reconnect. */
    public const REVOKED = 'revoked';

    /** Meta answered 100/33 on the catalog: it is no longer shared with Ruweb (or was deleted). */
    public const CATALOG_UNAVAILABLE = 'catalog_unavailable';

    /** The catalog is not «Productos online» (`vertical != commerce`): Meta rejects the feed there. */
    public const CATALOG_NOT_COMMERCE = 'catalog_not_commerce';

    /** «Sincronizar ahora» was pressed again within MetaCatalogSync::MANUAL_COOLDOWN seconds. */
    public const TOO_SOON = 'too_soon';

    /** The connector has no readable feed token (never activated). */
    public const NO_FEED_URL = 'no_feed_url';

    /** Any other answer of Meta (its message, already localized, is in the text). */
    public const META_ERROR = 'meta_error';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /** @param  array<string, string|int>  $replace */
    public static function for(string $reason, array $replace = []): self
    {
        return new self($reason, MetaCatalogSync::text('meta_sync.error_'.$reason, $replace));
    }
}
