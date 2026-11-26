<?php

namespace App\Connectors;

use App\ValueObjects\Money;
use InvalidArgumentException;

/**
 * One sellable unit read from a catalog source: a product without variants or one variant. Immutable and neutral
 * (no destination format): connectors decide limits, truncation and absolute URLs.
 */
final class CatalogItem
{
    public const IN_STOCK = 'in stock';

    public const OUT_OF_STOCK = 'out of stock';

    /**
     * @param  string  $id  unique within the source (`p12` / `v34` or the SKU)
     * @param  string|null  $groupId  shared by the variants of one product; null without variants; $id when none is loaded (self::lacksVariants())
     * @param  string  $description  plain text, no HTML
     * @param  string  $url  public path relative to the site (`/productos/mate?variante=34`)
     * @param  list<int>  $additionalImageMediaIds  gallery, in display order
     * @param  Money|null  $price  regular price; null when the record has none
     * @param  Money|null  $salePrice  current price when it is lower than the regular one, otherwise null
     * @param  string|null  $availability  self::IN_STOCK, self::OUT_OF_STOCK or null (the connector's default)
     * @param  string|null  $brand  brand term name; null means the connector's default brand
     * @param  array<string, string>  $variantAttributes  variant attributes (`color` => `Rojo`)
     * @param  string|null  $editUrl  panel URL to edit the record, for the skipped list
     * @param  list<string>  $categoryPath  names of the record's category from the top level down (`['Ropa', 'Remeras']`); empty without one
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $groupId,
        public readonly string $title,
        public readonly string $description,
        public readonly string $url,
        public readonly ?int $imageMediaId = null,
        public readonly array $additionalImageMediaIds = [],
        public readonly ?Money $price = null,
        public readonly ?Money $salePrice = null,
        public readonly ?string $availability = null,
        public readonly ?string $brand = null,
        public readonly array $variantAttributes = [],
        public readonly ?string $editUrl = null,
        public readonly array $categoryPath = [],
    ) {
        if ($id === '') {
            throw new InvalidArgumentException('A catalog item needs an id.');
        }
        if (! in_array($availability, [null, self::IN_STOCK, self::OUT_OF_STOCK], true)) {
            throw new InvalidArgumentException('Invalid catalog item availability: '.json_encode($availability).'.');
        }
        if ($salePrice !== null && ($price === null || $salePrice->currency !== $price->currency || $salePrice->amount >= $price->amount)) {
            throw new InvalidArgumentException('A sale price needs a higher regular price in the same currency.');
        }
    }

    /**
     * Whether the item stands for a product with variants enabled but none loaded: sources emit it with
     * `groupId === id` so connectors leave it out with FeedWarning::NO_VARIANTS instead of it silently disappearing.
     */
    public function lacksVariants(): bool
    {
        return $this->groupId !== null && $this->groupId === $this->id;
    }
}
