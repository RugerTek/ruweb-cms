<?php

namespace App\Services\Connectors\Meta;

use DateTimeImmutable;

/**
 * What the panel shows about the Meta catalog sync (MetaCatalogSync::status(), cached a few minutes): the state, the
 * last feed upload Meta processed (when, how many products it read and kept) and its problems, plus the item problems
 * of the last items_batch (track 3a). Holds no secret: safe for the panel and for MCP (toArray()).
 */
final class MetaSyncStatus
{
    /** Manual mode (v1): nothing is synced by API. */
    public const NOT_CONNECTED = 'not_connected';

    /** Meta revoked the token or the catalog: «Volver a conectar». */
    public const NEEDS_RECONNECT = 'needs_reconnect';

    /** Connected, but no catalog chosen yet. */
    public const NO_CATALOG = 'no_catalog';

    /** Catalog chosen, the Ruweb feed is not created in Meta yet (queued after a Meta outage, or deleted there). */
    public const NO_FEED = 'no_feed';

    /** The feed exists and Meta has not finished reading it (or never did). */
    public const PROCESSING = 'processing';

    /** The last upload finished without errors. */
    public const SYNCED = 'synced';

    /** The last upload finished with errors (some products, or the whole file). */
    public const WITH_ERRORS = 'with_errors';

    /** Meta did not answer now: the panel says so and keeps working. */
    public const UNAVAILABLE = 'unavailable';

    /**
     * @param  list<MetaSyncIssue>  $issues  at most MetaCatalogSync::ISSUES_LIMIT
     */
    public function __construct(
        public readonly string $state,
        public readonly ?string $catalogId = null,
        public readonly ?string $catalogName = null,
        public readonly ?string $feedId = null,
        public readonly ?int $productCount = null,
        public readonly ?string $uploadId = null,
        public readonly ?DateTimeImmutable $uploadStartedAt = null,
        public readonly ?DateTimeImmutable $uploadFinishedAt = null,
        public readonly int $detectedItems = 0,
        public readonly int $persistedItems = 0,
        public readonly int $invalidItems = 0,
        public readonly int $errorCount = 0,
        public readonly int $warningCount = 0,
        public readonly array $issues = [],
        public readonly int $issuesTotal = 0,
        public readonly ?string $message = null,
        public readonly ?DateTimeImmutable $checkedAt = null,
    ) {}

    public static function of(string $state, ?string $message = null): self
    {
        return new self($state, message: $message, checkedAt: new DateTimeImmutable);
    }

    public function isConnected(): bool
    {
        return $this->state !== self::NOT_CONNECTED;
    }

    /** The panel offers «Sincronizar ahora» (a catalog is chosen and the token works). */
    public function canSyncNow(): bool
    {
        return in_array($this->state, [self::NO_FEED, self::PROCESSING, self::SYNCED, self::WITH_ERRORS, self::UNAVAILABLE], true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'catalog_id' => $this->catalogId,
            'catalog_name' => $this->catalogName,
            'feed_id' => $this->feedId,
            'product_count' => $this->productCount,
            'upload_id' => $this->uploadId,
            'upload_started_at' => $this->uploadStartedAt?->format(DATE_ATOM),
            'upload_finished_at' => $this->uploadFinishedAt?->format(DATE_ATOM),
            'detected_items' => $this->detectedItems,
            'persisted_items' => $this->persistedItems,
            'invalid_items' => $this->invalidItems,
            'error_count' => $this->errorCount,
            'warning_count' => $this->warningCount,
            'issues' => array_map(fn (MetaSyncIssue $issue): array => $issue->toArray(), $this->issues),
            'issues_total' => $this->issuesTotal,
            'message' => $this->message,
            'checked_at' => $this->checkedAt?->format(DATE_ATOM),
        ];
    }
}
