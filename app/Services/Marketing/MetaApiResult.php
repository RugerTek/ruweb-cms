<?php

namespace App\Services\Marketing;

/**
 * What Meta answered to a Conversions API request. `message` is Meta's error text (or `connection` when Meta could not
 * be reached), never the token; `retryable` marks the failures worth retrying (connection, 5xx, 429).
 */
final class MetaApiResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly int $status,
        public readonly ?int $eventsReceived,
        public readonly ?string $message,
        public readonly ?string $traceId,
        public readonly bool $retryable = false,
    ) {}
}
