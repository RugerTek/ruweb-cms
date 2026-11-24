<?php

namespace App\Services\Marketing;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\ValueObjects\Money;

/**
 * The `custom_data` of the Meta Pixel events (Meta Pixel reference, standard events and object properties, consulted
 * 2026-11-24): content_ids, contents, content_type, value, currency and num_items. The IDs are the `g:id` and
 * `g:item_group_id` of the Meta catalog feed (App\Connectors\Sources\ShopProductSource), so Meta matches each event
 * with the catalog item: a variant is its SKU or `v{id}`; a product without variants (or with none loaded yet) is its
 * SKU or `p{id}` and the product group of its variants is that same ID. tests/Feature/MetaPixelEventsTest.php checks
 * them against the feed source. Values are in the site currency, which has no decimals for Guaraníes.
 */
final class MetaPixelContent
{
    public const TYPE_PRODUCT = 'product';

    public const TYPE_PRODUCT_GROUP = 'product_group';

    /** The base-language record the feed lists (a translated product page shows its translation). */
    public static function catalogProduct(Product $product): Product
    {
        return $product->isBaseLocale() ? $product : ($product->baseTranslation() ?? $product);
    }

    public static function productId(Product $product): string
    {
        return filled($product->sku) ? (string) $product->sku : 'p'.$product->id;
    }

    public static function variantId(ProductVariant $variant): string
    {
        return filled($variant->sku) ? (string) $variant->sku : 'v'.$variant->id;
    }

    /**
     * A product page (ViewContent) or the product put in the cart (AddToCart). With variants and none chosen, the
     * event names the product group.
     *
     * @return array<string, mixed>
     */
    public static function forProduct(Product $product, ?ProductVariant $variant = null, int $quantity = 1): array
    {
        $product = self::catalogProduct($product);
        $quantity = max(1, $quantity);
        if ($variant !== null && (int) $variant->product_id === (int) $product->id) {
            $id = self::variantId($variant);
            $type = self::TYPE_PRODUCT;
            $price = $variant->price_amount ?? $product->price_amount;
        } else {
            $id = self::productId($product);
            $type = $product->has_variants && $product->variants()->exists() ? self::TYPE_PRODUCT_GROUP : self::TYPE_PRODUCT;
            $price = $type === self::TYPE_PRODUCT ? $product->price_amount : $product->displayPrice();
        }

        return self::withValue([
            'content_ids' => [$id],
            'content_type' => $type,
            'content_name' => (string) $product->title,
            'contents' => [['id' => $id, 'quantity' => $quantity]],
        ], $price?->multiply($quantity));
    }

    /**
     * The checkout (InitiateCheckout): the lines of App\Services\Shop\CartService::summary() that can be bought.
     *
     * @param  array{lines: list<array<string, mixed>>, subtotal: Money, count: int}  $summary
     * @return array<string, mixed>
     */
    public static function forCart(array $summary): array
    {
        $contents = [];
        foreach ($summary['lines'] as $line) {
            $product = $line['product'] ?? null;
            if (! $product instanceof Product || ($line['problem'] ?? null) !== null) {
                continue;
            }
            $variant = $line['variant'] ?? null;
            $id = $variant instanceof ProductVariant ? self::variantId($variant) : self::productId(self::catalogProduct($product));
            $contents[] = ['id' => $id, 'quantity' => (int) $line['quantity']];
        }

        return self::withValue(self::fromContents($contents), $summary['subtotal']);
    }

    /**
     * A paid order (Purchase). Lines whose product was deleted keep the frozen SKU, or the variant/product number.
     *
     * @return array<string, mixed>
     */
    public static function forOrder(Order $order): array
    {
        $contents = [];
        foreach ($order->items()->with(['product', 'variant'])->get() as $item) {
            $contents[] = ['id' => self::orderItemId($item), 'quantity' => (int) $item->quantity];
        }

        return self::withValue(self::fromContents($contents), $order->total_amount) + ['order_id' => (string) $order->number];
    }

    public static function orderItemId(OrderItem $item): string
    {
        if ($item->variant_id !== null) {
            return $item->variant instanceof ProductVariant ? self::variantId($item->variant) : 'v'.$item->variant_id;
        }
        if ($item->product instanceof Product) {
            return self::productId($item->product);
        }

        return filled($item->sku) ? (string) $item->sku : 'p'.$item->product_id;
    }

    /**
     * @param  list<array{id: string, quantity: int}>  $contents
     * @return array<string, mixed>
     */
    private static function fromContents(array $contents): array
    {
        return [
            'content_ids' => array_values(array_unique(array_column($contents, 'id'))),
            'content_type' => self::TYPE_PRODUCT,
            'contents' => $contents,
            'num_items' => array_sum(array_column($contents, 'quantity')),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function withValue(array $data, ?Money $value): array
    {
        $data['currency'] = $value?->currency ?? (string) config('cms.shop.currency', 'PYG');
        if ($value !== null) {
            $data['value'] = $value->amount;
        }

        return $data;
    }
}
