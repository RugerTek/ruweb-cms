<?php

namespace App\Connectors;

/** Outcome of walking a source through a feed connector (preview, MCP, or the generated file). */
final class FeedResult
{
    /**
     * @param  int  $included  items that enter the feed
     * @param  int  $skipped  items left out
     * @param  list<CatalogItem>  $sample  first included items, in source order
     * @param  list<FeedWarning>  $warnings  every warning, skipping or not, in source order
     */
    public function __construct(
        public readonly int $included,
        public readonly int $skipped,
        public readonly array $sample,
        public readonly array $warnings,
    ) {}

    public static function empty(): self
    {
        return new self(0, 0, [], []);
    }

    /**
     * Warnings that left an item out.
     *
     * @return list<FeedWarning>
     */
    public function skippedWarnings(): array
    {
        return array_values(array_filter($this->warnings, fn (FeedWarning $warning): bool => $warning->skips));
    }

    /**
     * Warnings about items that still entered the feed: a skipped item is listed once, with its skip reasons, and
     * its other warnings are left out.
     *
     * @return list<FeedWarning>
     */
    public function notices(): array
    {
        $skippedIds = array_flip(array_map(fn (FeedWarning $warning): string => $warning->itemId, $this->skippedWarnings()));

        return array_values(array_filter($this->warnings, fn (FeedWarning $warning): bool => ! $warning->skips && ! isset($skippedIds[$warning->itemId])));
    }
}
