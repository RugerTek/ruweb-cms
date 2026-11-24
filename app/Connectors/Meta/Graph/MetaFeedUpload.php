<?php

namespace App\Connectors\Meta\Graph;

use DateTimeImmutable;

/**
 * One feed upload (`GET /{upload}?fields=…`): end_time is missing while Meta is still processing (the spike saw ~11 s).
 * An upload of a URL that answers 404 ends with error_count 1 and keeps the existing products (spike §3.3.5).
 */
final class MetaFeedUpload
{
    public const FIELDS = 'id,start_time,end_time,url,error_count,warning_count,num_detected_items,num_persisted_items,num_deleted_items,num_invalid_items';

    public function __construct(
        public readonly string $id,
        public readonly ?DateTimeImmutable $startTime = null,
        public readonly ?DateTimeImmutable $endTime = null,
        public readonly int $errorCount = 0,
        public readonly int $warningCount = 0,
        public readonly int $detectedItems = 0,
        public readonly int $persistedItems = 0,
        public readonly int $invalidItems = 0,
        public readonly int $deletedItems = 0,
    ) {}

    /** @param  array<string, mixed>  $json */
    public static function fromGraph(array $json): self
    {
        return new self(
            (string) ($json['id'] ?? ''),
            MetaGraphValues::date($json['start_time'] ?? null),
            MetaGraphValues::date($json['end_time'] ?? null),
            (int) ($json['error_count'] ?? 0),
            (int) ($json['warning_count'] ?? 0),
            (int) ($json['num_detected_items'] ?? 0),
            (int) ($json['num_persisted_items'] ?? 0),
            (int) ($json['num_invalid_items'] ?? 0),
            (int) ($json['num_deleted_items'] ?? 0),
        );
    }

    public function finished(): bool
    {
        return $this->endTime !== null;
    }
}
