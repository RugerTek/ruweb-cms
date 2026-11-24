<?php

namespace App\Connectors\Meta\Graph;

/**
 * `GET /{catalog}/check_batch_request_status?handle=…&load_ids_of_invalid_requests=true`. An invalid value of an
 * UPDATE may come back in `warnings` (not `errors`) with errors_total_count 0 and the item unchanged (spike §3.3.4):
 * read both.
 */
final class MetaBatchStatus
{
    public const FINISHED = 'finished';

    /**
     * @param  list<MetaBatchIssue>  $errors
     * @param  list<MetaBatchIssue>  $warnings
     * @param  list<string>  $invalidRetailerIds
     */
    public function __construct(
        public readonly string $handle,
        public readonly string $status,
        public readonly int $errorsTotalCount = 0,
        public readonly array $errors = [],
        public readonly array $warnings = [],
        public readonly array $invalidRetailerIds = [],
    ) {}

    /** @param  array<string, mixed>  $json  one element of `data` */
    public static function fromGraph(array $json): self
    {
        $issues = fn (mixed $list): array => is_array($list) ? array_values(array_map(fn (array $issue) => MetaBatchIssue::fromGraph($issue), array_filter($list, 'is_array'))) : [];

        return new self(
            (string) ($json['handle'] ?? ''),
            (string) ($json['status'] ?? ''),
            (int) ($json['errors_total_count'] ?? 0),
            $issues($json['errors'] ?? []),
            $issues($json['warnings'] ?? []),
            is_array($json['ids_of_invalid_requests'] ?? null) ? array_values(array_map('strval', $json['ids_of_invalid_requests'])) : [],
        );
    }

    public function finished(): bool
    {
        return $this->status === self::FINISHED;
    }

    public function hasIssues(): bool
    {
        return $this->errors !== [] || $this->warnings !== [] || $this->invalidRetailerIds !== [];
    }
}
