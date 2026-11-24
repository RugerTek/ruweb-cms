<?php

namespace App\Listeners\Connectors;

use App\Models\Media;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Connectors\Meta\MetaItemsPublisher;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Catalog changes that Meta v2 publishes at once (plan-meta-v2, track 3a), wired by
 * App\Providers\ConnectorServiceProvider with Event::subscribe(): the same changes that mark the feeds stale
 * (product, variant, image) plus the stock a paid, cancelled or refunded order moves (App\Services\Shop\StockLedger
 * writes with the query builder, so only the order's `stock_applied_at` tells). Each one hands the affected product
 * ids — and the retailer ids that disappear (a deleted variant, an old SKU) — to MetaItemsPublisher, which does nothing
 * unless the site is connected with a catalog chosen. Method names do not start with `handle` on purpose (Laravel's
 * listener discovery would register them twice). Errors are logged and never break the save.
 */
final class MetaCatalogChanges
{
    /** @return array<string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            'eloquent.saved: '.Product::class => 'onProductSaved',
            'eloquent.deleting: '.Product::class => 'onProductDeleting',
            'eloquent.saved: '.ProductVariant::class => 'onVariantSaved',
            'eloquent.deleted: '.ProductVariant::class => 'onVariantDeleted',
            'eloquent.updated: '.Order::class => 'onOrderUpdated',
            'eloquent.updated: '.Media::class => 'onMediaChanged',
            'eloquent.deleted: '.Media::class => 'onMediaChanged',
        ];
    }

    public function onProductSaved(Product $product): void
    {
        $this->safely(function () use ($product): void {
            $gone = [];
            // wasChanged() only reports updates (an insert leaves it empty), even on an instance created earlier.
            if ($product->wasChanged(['sku', 'has_variants'])) {
                // What it stood for before: its old SKU, or the variants it no longer shows (or now shows instead).
                $gone[] = MetaItemsPublisher::productRetailerId($product, (string) $product->getOriginal('sku'));
                if ((bool) $product->getOriginal('has_variants')) {
                    array_push($gone, ...$product->variants()->get()->map(fn (ProductVariant $variant): string => MetaItemsPublisher::variantRetailerId($variant))->all());
                }
            }
            $this->publisher()->productsChanged([(int) $product->id], $gone);
        });
    }

    public function onProductDeleting(Product $product): void
    {
        // Before the delete: its variants are still there to tell their retailer ids.
        $this->safely(fn () => $this->publisher()->productsChanged(
            [(int) $product->id],
            array_values(array_unique([MetaItemsPublisher::productRetailerId($product), ...MetaItemsPublisher::retailerIds($product)])),
        ));
    }

    public function onVariantSaved(ProductVariant $variant): void
    {
        $this->safely(function () use ($variant): void {
            $gone = $variant->wasChanged('sku')
                ? [MetaItemsPublisher::variantRetailerId($variant, (string) $variant->getOriginal('sku'))]
                : [];
            $this->publisher()->productsChanged([(int) $variant->product_id], $gone);
        });
    }

    public function onVariantDeleted(ProductVariant $variant): void
    {
        $this->safely(fn () => $this->publisher()->productsChanged([(int) $variant->product_id], [MetaItemsPublisher::variantRetailerId($variant)]));
    }

    /** A payment took the stock, or a cancellation or refund gave it back. */
    public function onOrderUpdated(Order $order): void
    {
        if (! $order->wasChanged('stock_applied_at')) {
            return;
        }
        $this->safely(fn () => $this->publisher()->productsChanged(
            $order->items()->whereNotNull('product_id')->pluck('product_id')->map(fn ($id): int => (int) $id)->unique()->values()->all(),
        ));
    }

    /** An image replaced or deleted: the products that show it. */
    public function onMediaChanged(Media $media): void
    {
        $this->safely(fn () => $this->publisher()->productsChanged(
            DB::table('mediables')->where('media_id', $media->id)->where('mediable_type', (new Product)->getMorphClass())
                ->pluck('mediable_id')->map(fn ($id): int => (int) $id)->unique()->values()->all(),
        ));
    }

    /** Resolved per event, not when the subscriber is registered: booting the app stays cheap. */
    private function publisher(): MetaItemsPublisher
    {
        return app(MetaItemsPublisher::class);
    }

    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Log::warning('Meta items batch: a catalog change could not be queued.', ['message' => $e->getMessage(), 'exception' => $e::class]);
        }
    }
}
