<?php

namespace App\Connectors\Meta\Graph;

/**
 * One entry of the `x-business-use-case-usage` header (keyed by the catalog or business id): percentages of the rate
 * limit used and the minutes until access comes back. Types seen in the spike: catalog_management,
 * catalog_batch_catalog_level_per_minute (items_batch) and business_integration_system_user_platform_endpoints.
 */
final class MetaUsage
{
    public const HEADER = 'x-business-use-case-usage';

    /** Percentage of call_count from which batches should wait (spike §5). */
    public const SLOW_DOWN_AT = 75;

    public function __construct(
        public readonly string $objectId,
        public readonly string $type,
        public readonly int $callCount,
        public readonly int $totalCputime,
        public readonly int $totalTime,
        public readonly int $estimatedTimeToRegainAccess,
    ) {}

    /**
     * Parse the header; a missing or malformed one is an empty list.
     *
     * @return list<MetaUsage>
     */
    public static function fromHeader(?string $header): array
    {
        $json = $header === null || $header === '' ? null : json_decode($header, true);
        if (! is_array($json)) {
            return [];
        }
        $usage = [];
        foreach ($json as $objectId => $entries) {
            foreach (is_array($entries) ? $entries : [] as $entry) {
                if (is_array($entry)) {
                    $usage[] = new self((string) $objectId, (string) ($entry['type'] ?? ''), (int) ($entry['call_count'] ?? 0), (int) ($entry['total_cputime'] ?? 0), (int) ($entry['total_time'] ?? 0), (int) ($entry['estimated_time_to_regain_access'] ?? 0));
                }
            }
        }

        return $usage;
    }

    public function shouldSlowDown(): bool
    {
        return $this->estimatedTimeToRegainAccess > 0 || max($this->callCount, $this->totalCputime, $this->totalTime) >= self::SLOW_DOWN_AT;
    }
}
