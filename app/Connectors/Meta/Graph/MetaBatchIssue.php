<?php

namespace App\Connectors\Meta\Graph;

/** One error or warning of check_batch_request_status: `id` is the retailer id (the feed's g:id), `line` 1-based. */
final class MetaBatchIssue
{
    public function __construct(
        public readonly ?string $retailerId,
        public readonly ?int $line,
        public readonly string $message,
    ) {}

    /** @param  array<string, mixed>  $json */
    public static function fromGraph(array $json): self
    {
        return new self(MetaGraphValues::string($json['id'] ?? null), MetaGraphValues::int($json['line'] ?? null), (string) ($json['message'] ?? ''));
    }
}
