<?php

namespace App\Services\Shop;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Stock movements of orders (PLAN.md E3.2, P3): stock is taken when the payment is confirmed and given back when a
 * paid order is cancelled or refunded. Both calls must run inside the caller's transaction with the order row
 * already locked; they lock the product and variant rows (ids ascending, so two orders never deadlock) and write
 * with the query builder, because a sale is not an editorial change (no editorial history, no content revision,
 * and the editor publication lock does not apply). `orders.stock_applied_at` makes both calls idempotent.
 *
 * Only products with `track_stock` move: a variant follows its product's flag. With `allow_backorder` the stock may
 * go negative (units owed). Without it, a line that does not fit takes what is left, the stock stays at 0 and the
 * line goes to the returned shortfall (the payment is never rejected: decision 6.2). Restoring gives back exactly
 * what was taken, using the stored shortfall for the lines that fell short.
 */
final class StockLedger
{
    /**
     * Take the order's stock once. Returns the lines that fell short (empty when everything fit or the stock was
     * already taken) and stores them in `orders.stock_shortfall`.
     *
     * @return list<array{product_id: int|null, variant_id: int|null, title: string, requested: int, available: int}>
     */
    public function take(Order $order): array
    {
        $this->assertInTransaction();
        if ($order->stock_applied_at !== null) {
            return [];
        }

        $shortfall = [];
        foreach ($this->lines($order) as $line) {
            $available = max(0, $line['stock']);
            $taken = $line['backorder'] ? $line['requested'] : min($line['requested'], $available);
            if (! $line['backorder'] && $line['requested'] > $available) {
                $shortfall[] = [
                    'product_id' => $line['product_id'],
                    'variant_id' => $line['variant_id'],
                    'title' => $line['title'],
                    'requested' => $line['requested'],
                    'available' => $available,
                ];
            }
            if ($taken > 0) {
                $this->write($line, $line['stock'] - $taken);
            }
        }

        $order->forceFill(['stock_applied_at' => now(), 'stock_shortfall' => $shortfall === [] ? null : $shortfall])->save();

        return $shortfall;
    }

    /** Give back what take() took; nothing when the stock was not taken (or was already given back). */
    public function restore(Order $order): void
    {
        $this->assertInTransaction();
        if ($order->stock_applied_at === null) {
            return;
        }

        $short = [];
        foreach ($order->stock_shortfall ?? [] as $entry) {
            $short[$this->key($entry['product_id'] ?? null, $entry['variant_id'] ?? null)] = (int) ($entry['available'] ?? 0);
        }
        foreach ($this->lines($order) as $line) {
            $taken = $short[$line['key']] ?? $line['requested'];
            if ($taken > 0) {
                $this->write($line, $line['stock'] + $taken);
            }
        }

        $order->forceFill(['stock_applied_at' => null])->save();
    }

    /**
     * The order's tracked lines grouped by product and variant (one entry per stock row), each with its locked
     * current stock. Lines whose product or variant was deleted, untracked products, and variant lines whose
     * variant is gone are skipped.
     *
     * @return list<array{key: string, product_id: int, variant_id: int|null, title: string, requested: int, stock: int, backorder: bool}>
     */
    private function lines(Order $order): array
    {
        $items = $order->items()->get()->filter(fn (OrderItem $item) => $item->product_id !== null);
        if ($items->isEmpty()) {
            return [];
        }

        $products = Product::query()->whereIn('id', $items->pluck('product_id')->unique()->sort()->values())
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $variantIds = $items->pluck('variant_id')->filter()->unique()->sort()->values();
        $variants = $variantIds->isEmpty() ? collect() : ProductVariant::query()->whereIn('id', $variantIds)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $lines = [];
        foreach ($items as $item) {
            $product = $products->get($item->product_id);
            if ($product === null || ! $product->track_stock) {
                continue;
            }
            $variant = $item->variant_id !== null ? $variants->get($item->variant_id) : null;
            if (($item->variant_id !== null && $variant === null) || ($item->variant_id === null && $product->has_variants)) {
                continue;
            }
            $key = $this->key($product->id, $variant?->id);
            if (isset($lines[$key])) {
                $lines[$key]['requested'] += $item->quantity;

                continue;
            }
            $lines[$key] = [
                'key' => $key,
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'title' => $item->variant_name ? $item->title.' ('.$item->variant_name.')' : $item->title,
                'requested' => $item->quantity,
                'stock' => (int) ($variant !== null ? $variant->stock : $product->stock),
                'backorder' => (bool) $product->allow_backorder,
            ];
        }

        return array_values($lines);
    }

    /** @param  array{product_id: int, variant_id: int|null}  $line */
    private function write(array $line, int $stock): void
    {
        $query = $line['variant_id'] !== null
            ? ProductVariant::query()->whereKey($line['variant_id'])
            : Product::query()->whereKey($line['product_id']);
        $query->toBase()->update(['stock' => $stock, 'updated_at' => now()]);
    }

    private function key(?int $productId, ?int $variantId): string
    {
        return ($productId ?? 0).':'.($variantId ?? 0);
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('StockLedger must run inside the transaction that locked the order.');
        }
    }
}
