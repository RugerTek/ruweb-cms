<?php

namespace App\Connectors\Sources;

use App\Connectors\CatalogItem;
use App\Contracts\Catalog\CatalogSource;
use App\Http\Controllers\ShopController;
use App\Models\Locale;
use App\Models\Media;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Taxonomy;
use App\Support\ShopModule;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\LazyCollection;

/**
 * Catalog source over the shop products (`cms.catalog_sources.products`, PLAN.md §4.2). Live products of the base
 * language only; one item per product without variants and one per variant otherwise. A product with variants
 * enabled but none loaded yields a single item whose `groupId` equals its `id` (CatalogItem::lacksVariants()), so the
 * connector can leave it out with `no_variants` instead of it silently disappearing.
 */
class ShopProductSource implements CatalogSource
{
    public const KEY = 'products';

    /** Products read per query while streaming; relations are eager loaded per chunk. */
    private const CHUNK = 200;

    /** Deepest category level walked up to the top one; guards against a parent loop in the data. */
    private const CATEGORY_DEPTH = 10;

    /** @var array<int, array{name: string, parent_id: ?int}>|null category id => name and parent, loaded once per items() run */
    private ?array $categoryTree = null;

    public function __construct(private readonly ShopModule $shop) {}

    /** Whether $item stands for a product with variants enabled but none loaded; see CatalogItem::lacksVariants(). */
    public static function lacksVariants(CatalogItem $item): bool
    {
        return $item->lacksVariants();
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function available(): bool
    {
        return $this->shop->catalog();
    }

    public function items(): LazyCollection
    {
        $this->categoryTree = null;

        return $this->products()
            ->with(['variants', 'media', 'brands', 'categories'])
            ->lazyById(self::CHUNK)
            ->flatMap(fn (Product $product) => $this->productItems($product));
    }

    /**
     * Items of these products only, with the same rules as items() (live, base language; unknown, unpublished or
     * translated ids yield nothing), so a change batch (MetaItemsPublisher) does not walk the whole catalog. Product
     * ids are queried in chunks of CHUNK.
     *
     * @param  array<int|string>  $productIds
     * @return LazyCollection<int, CatalogItem>
     */
    public function itemsFor(array $productIds): LazyCollection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), fn (int $id): bool => $id > 0)));
        sort($ids);
        $this->categoryTree = null;

        return LazyCollection::make(function () use ($ids) {
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $products = $this->products()->whereIn('id', $chunk)->with(['variants', 'media', 'brands', 'categories'])->get();
                foreach ($products as $product) {
                    foreach ($this->productItems($product) as $item) {
                        yield $item;
                    }
                }
            }
        });
    }

    public function count(): int
    {
        $withoutVariantRows = $this->products()
            ->where(fn (Builder $query) => $query->where('has_variants', false)->orWhereDoesntHave('variants'))
            ->count();

        $variants = ProductVariant::query()
            ->whereIn('product_id', $this->products()->where('has_variants', true)->select('id'))
            ->count();

        return $withoutVariantRows + $variants;
    }

    public function groupCount(): int
    {
        return $this->products()->count();
    }

    /** Live products of the base language, in a stable order. */
    private function products(): Builder
    {
        return Product::query()
            ->published()
            ->where('locale', Locale::baseCode())
            ->orderBy('id');
    }

    /** @return list<CatalogItem> */
    private function productItems(Product $product): array
    {
        $productId = $this->productId($product);
        $common = [
            'description' => $this->plainText($product),
            'imageMediaId' => $this->imageId($product),
            'additionalImageMediaIds' => $this->galleryIds($product),
            'brand' => $this->brand($product),
            'editUrl' => $this->editUrl($product),
            'categoryPath' => $this->categoryPath($product),
        ];

        if (! $product->has_variants) {
            return [$this->item($product, $productId, null, $product->title, $product->url(),
                $product->price_amount, $product->compare_at_price_amount, $product->stock, [], $common)];
        }

        if ($product->variants->isEmpty()) {
            return [$this->item($product, $productId, $productId, $product->title, $product->url(),
                $product->price_amount, $product->compare_at_price_amount, $product->stock, [], $common)];
        }

        return $product->variants->map(fn (ProductVariant $variant) => $this->item(
            $product,
            filled($variant->sku) ? (string) $variant->sku : 'v'.$variant->id,
            $productId,
            filled($variant->name) ? $product->title.' - '.$variant->name : $product->title,
            $product->url().'?'.ShopController::VARIANT_QUERY.'='.$variant->id,
            $variant->price_amount,
            $variant->compare_at_price_amount,
            $variant->stock,
            $this->attributes($variant),
            $common,
        ))->values()->all();
    }

    /**
     * @param  array<string, string>  $attributes
     * @param  array<string, mixed>  $common
     */
    private function item(Product $product, string $id, ?string $groupId, string $title, string $url, ?Money $price, ?Money $compareAt, ?int $stock, array $attributes, array $common): CatalogItem
    {
        [$regular, $sale] = $this->prices($price, $compareAt);

        return new CatalogItem(
            id: $id,
            groupId: $groupId,
            title: $title,
            description: $common['description'],
            url: $url,
            imageMediaId: $common['imageMediaId'],
            additionalImageMediaIds: $common['additionalImageMediaIds'],
            price: $regular,
            salePrice: $sale,
            availability: $this->availability($product, $stock),
            brand: $common['brand'],
            variantAttributes: $attributes,
            editUrl: $common['editUrl'],
            categoryPath: $common['categoryPath'],
        );
    }

    private function productId(Product $product): string
    {
        return filled($product->sku) ? (string) $product->sku : 'p'.$product->id;
    }

    /**
     * A compare-at price above the current one makes it the regular price and the current one the sale price.
     *
     * @return array{0: ?Money, 1: ?Money}
     */
    private function prices(?Money $price, ?Money $compareAt): array
    {
        if ($price !== null && $compareAt !== null && $compareAt->currency === $price->currency && $compareAt->amount > $price->amount) {
            return [$compareAt, $price];
        }

        return [$price, null];
    }

    /** Tracked stock decides unless backorders are allowed; without tracking the connector's default applies (null). */
    private function availability(Product $product, ?int $stock): ?string
    {
        if (! $product->track_stock) {
            return null;
        }
        if ($product->allow_backorder) {
            return CatalogItem::IN_STOCK;
        }

        return (int) $stock > 0 ? CatalogItem::IN_STOCK : CatalogItem::OUT_OF_STOCK;
    }

    /** Excerpt, or the rich description, as plain text: no tags, no links, entities decoded, whitespace collapsed. */
    private function plainText(Product $product): string
    {
        foreach ([$product->excerpt, $product->description] as $text) {
            $html = (string) $text;
            $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
            $html = preg_replace('#<br\s*/?>|</(p|div|li|h[1-6]|blockquote|tr)>#i', "\n", $html) ?? $html;
            $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $plain = preg_replace('/[^\S\n]+/u', ' ', $plain) ?? $plain;
            $plain = trim(preg_replace('/\s*\n\s*/u', "\n", $plain) ?? $plain);
            if ($plain !== '') {
                return $plain;
            }
        }

        return '';
    }

    private function imageId(Product $product): ?int
    {
        $media = $product->featuredImage();

        return $media instanceof Media && $media->isImage() ? (int) $media->id : null;
    }

    /** @return list<int> gallery images in display order, without the featured image */
    private function galleryIds(Product $product): array
    {
        $featured = $this->imageId($product);

        return $product->media
            ->filter(fn (Media $media) => $media->pivot->collection === 'gallery' && $media->isImage() && (int) $media->id !== $featured)
            ->map(fn (Media $media) => (int) $media->id)
            ->unique()
            ->values()
            ->all();
    }

    private function brand(Product $product): ?string
    {
        $name = $product->brands->sortBy('id')->first()?->name;

        return filled($name) ? (string) $name : null;
    }

    /**
     * Names of the product's first category (lowest id, like the brand) and its parents, top level first.
     *
     * @return list<string>
     */
    private function categoryPath(Product $product): array
    {
        $category = $product->categories->sortBy('id')->first();
        if (! $category instanceof Taxonomy) {
            return [];
        }
        $tree = $this->categoryTree ??= Taxonomy::query()->ofType('category')->get(['id', 'name', 'parent_id'])
            ->mapWithKeys(fn (Taxonomy $term) => [(int) $term->id => ['name' => (string) $term->name, 'parent_id' => $term->parent_id === null ? null : (int) $term->parent_id]])
            ->all();

        $path = [];
        $id = (int) $category->id;
        while ($id !== null && isset($tree[$id]) && count($path) < self::CATEGORY_DEPTH) {
            if (filled($tree[$id]['name'])) {
                array_unshift($path, $tree[$id]['name']);
            }
            $id = $tree[$id]['parent_id'];
        }

        return $path;
    }

    /** @return array<string, string> scalar variant attributes (`color` => `Rojo`) */
    private function attributes(ProductVariant $variant): array
    {
        $attributes = [];
        foreach ($variant->attributes_json ?? [] as $key => $value) {
            if (is_string($key) && $key !== '' && is_scalar($value) && (string) $value !== '') {
                $attributes[$key] = (string) $value;
            }
        }

        return $attributes;
    }

    private function editUrl(Product $product): ?string
    {
        return Route::has('admin.products.edit') ? route('admin.products.edit', $product, false) : null;
    }
}
