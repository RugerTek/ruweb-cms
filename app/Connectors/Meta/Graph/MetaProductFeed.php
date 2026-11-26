<?php

namespace App\Connectors\Meta\Graph;

/**
 * A scheduled product feed (`GET /{feed}?fields=id,name,product_count,default_currency,schedule,latest_upload`).
 * Meta fills the schedule hour/minute with "now" and America/Los_Angeles when they are not sent (spike §3.3).
 */
final class MetaProductFeed
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $name = null,
        public readonly ?string $scheduleUrl = null,
        public readonly ?string $scheduleInterval = null,
        public readonly ?string $defaultCurrency = null,
        public readonly ?int $productCount = null,
        public readonly ?string $latestUploadId = null,
    ) {}

    /** @param  array<string, mixed>  $json */
    public static function fromGraph(array $json): self
    {
        $schedule = is_array($json['schedule'] ?? null) ? $json['schedule'] : [];
        $upload = is_array($json['latest_upload'] ?? null) ? $json['latest_upload'] : [];

        return new self(
            (string) ($json['id'] ?? ''),
            MetaGraphValues::string($json['name'] ?? null),
            MetaGraphValues::string($schedule['url'] ?? null),
            MetaGraphValues::string($schedule['interval'] ?? null),
            MetaGraphValues::string($json['default_currency'] ?? null),
            MetaGraphValues::int($json['product_count'] ?? null),
            MetaGraphValues::string($upload['id'] ?? null),
        );
    }
}
