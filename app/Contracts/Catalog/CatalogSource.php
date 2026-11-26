<?php

namespace App\Contracts\Catalog;

use App\Connectors\CatalogItem;
use Illuminate\Support\LazyCollection;

/**
 * Where a feed connector reads its items from (`cms.catalog_sources`, key => class, resolved through the
 * container). The source owns which records are public and how they map to App\Connectors\CatalogItem; the
 * connector owns the destination format.
 */
interface CatalogSource
{
    /** Registry key, equal to its `cms.catalog_sources` key (`products`). */
    public function key(): string;

    /** Whether the records behind it exist on this site right now (the shop catalog is on). Read per call. */
    public function available(): bool;

    /**
     * Public items in a stable order, one per sellable unit (a variant, or the product when it has none). Must
     * stream from a cursor: never loads the whole catalog in memory.
     *
     * @return LazyCollection<int, CatalogItem>
     */
    public function items(): LazyCollection;

    /** Number of items items() yields, without building them. */
    public function count(): int;

    /**
     * Number of products behind items(), without building them: the items of one group (the variants of a
     * product) count once. Shown to the administrator, who thinks in products, next to count().
     */
    public function groupCount(): int;
}
