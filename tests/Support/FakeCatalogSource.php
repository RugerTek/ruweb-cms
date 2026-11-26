<?php

namespace Tests\Support;

use App\Connectors\CatalogItem;
use App\Contracts\Catalog\CatalogSource;
use App\Support\ShopModule;
use App\ValueObjects\Money;
use Illuminate\Support\LazyCollection;

/**
 * In-memory catalog source for tests (TestCase::useFakeConnectors() binds one shared instance). Fill $items with
 * self::item(); availability follows the shop catalog unless $available forces it.
 */
final class FakeCatalogSource implements CatalogSource
{
    public string $key = 'products';

    /** @var list<CatalogItem> */
    public array $items = [];

    /** null = follow ShopModule::catalog(). */
    public ?bool $available = null;

    /**
     * A complete item (price, image, brand) unless overridden: `price` / `salePrice` take integers or null.
     *
     * @param  array<string, mixed>  $overrides  CatalogItem constructor arguments by name
     */
    public static function item(string $id, array $overrides = []): CatalogItem
    {
        foreach (['price', 'salePrice'] as $money) {
            if (array_key_exists($money, $overrides) && is_int($overrides[$money])) {
                $overrides[$money] = Money::fromInt($overrides[$money]);
            }
        }

        return new CatalogItem(...array_replace([
            'id' => $id,
            'groupId' => null,
            'title' => 'Item '.$id,
            'description' => 'Description of item '.$id,
            'url' => '/productos/item-'.$id,
            'imageMediaId' => 1,
            'additionalImageMediaIds' => [],
            'price' => Money::fromInt(150000),
            'salePrice' => null,
            'availability' => CatalogItem::IN_STOCK,
            'brand' => 'Brand',
            'variantAttributes' => [],
            'editUrl' => '/admin/products/'.$id.'/edit',
        ], $overrides));
    }

    public function key(): string
    {
        return $this->key;
    }

    public function available(): bool
    {
        return $this->available ?? app(ShopModule::class)->catalog();
    }

    public function items(): LazyCollection
    {
        $items = $this->items;

        return LazyCollection::make(function () use ($items) {
            yield from $items;
        });
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function groupCount(): int
    {
        return count(array_unique(array_map(fn (CatalogItem $item): string => $item->groupId ?? $item->id, $this->items)));
    }
}
