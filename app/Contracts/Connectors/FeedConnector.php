<?php

namespace App\Contracts\Connectors;

use App\Connectors\CatalogItem;
use App\Connectors\FeedWarning;

/**
 * A connector that publishes a catalog source as a file another platform downloads on a schedule
 * (`GET /feeds/{connector}/{token}.xml`, PLAN.md §4.7). The connector decides which items enter and writes the
 * file; reading the catalog belongs to the source (App\Contracts\Catalog\CatalogSource).
 */
interface FeedConnector extends Connector
{
    /** Key of the catalog source in `cms.catalog_sources` (`products`). */
    public function source(): string;

    /**
     * Reasons an item is left out and notices that do not leave it out. An item enters the feed only when no
     * returned warning `skips`. May prepare derived assets (JPEG copies of images), never per feed request.
     *
     * @param  array<string, mixed>  $settings  effective settings (defaults merged with the stored ones)
     * @return list<FeedWarning>
     */
    public function check(CatalogItem $item, array $settings): array;

    /**
     * Write the whole document to an open, writable stream. Receives only items that passed check(); relative
     * URLs in the items become absolute here (App\Support\SiteUrl::origin()).
     *
     * @param  iterable<CatalogItem>  $items
     * @param  array<string, mixed>  $settings  effective settings
     * @param  resource  $stream
     */
    public function write(iterable $items, array $settings, $stream): void;

    /** HTTP Content-Type of the document (`application/xml; charset=UTF-8`). */
    public function contentType(): string;

    /** File extension of the generated document, without the dot (`xml`). */
    public function extension(): string;

    /**
     * Lowercase fragments of the user-agents the destination platform downloads the feed with
     * (`facebookexternalhit`). A matching fetch updates `last_meta_fetched_at` (ConnectorOperations::recordFetch()).
     *
     * @return list<string>
     */
    public function fetchAgents(): array;
}
