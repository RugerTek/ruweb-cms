<?php

namespace App\Services\Products;

use App\Mcp\ContentPatch;
use App\Models\Media;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Taxonomy;
use App\Models\User;
use App\Services\Editorial\EditorialActor;
use App\Services\Editorial\EditorialHistory;
use App\Services\Editorial\EditorialOperations;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Product previews that never write the product: an in-memory proposal rendered with the public view (panel
 * "preview" button, MCP preview) and the proposal version an editor's change to a live product becomes.
 * Unsaved changes are validated with the same ProductInput the save uses.
 *
 * With $patch the changes merge over the saved state (MCP); without, they are the whole form (panel): omitted
 * fields are empty in the preview, as they would be after saving.
 */
final class ProductPreview
{
    /** Render the product as it would look after $changes ($changes null = saved state). */
    public function render(User $actor, Product $product, ?array $changes = null, bool $patch = true): Response
    {
        Gate::forUser($actor)->authorize('update', $product);
        $proposal = $changes === null ? $product->fresh(['media', 'taxonomies', 'variants']) : $this->proposal($product, $changes, $patch);
        $previous = app()->getLocale();
        app()->setLocale($proposal->locale);
        try {
            $html = view($proposal->previewView(), $proposal->previewData() + ['isEditorPreview' => true])->render();
        } finally {
            app()->setLocale($previous);
        }

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer')->header('X-Frame-Options', 'SAMEORIGIN');
    }

    /** Unsaved copy of the product after the changes, with media, terms and variants loaded as relations. */
    public function proposal(Product $product, array $changes, bool $patch = true): Product
    {
        [$proposal, $payload] = $this->compose($product, $changes, $patch);
        $media = collect();
        foreach ($payload['media'] as $entry) {
            $item = Media::find($entry['id']);
            if ($item) {
                $item->setRelation('pivot', new Pivot(['collection' => $entry['collection'], 'order' => $entry['order']]));
                $media->push($item);
            }
        }
        $proposal->setRelation('media', $media);
        $proposal->setRelation('taxonomies', Taxonomy::whereIn('id', $payload['taxonomies'])->get());
        $proposal->setRelation('variants', $this->variantModels($product, $payload['variants']));

        return $proposal;
    }

    /**
     * Confidential `/draft-preview/<token>` link (EditorialPreviewController), as for pages and posts. $changes null
     * shares the saved state; otherwise the changes become a proposal version and the product is not written.
     *
     * @return array{link: array, preview_url: string, url: string, expires_at: string, revision: string}
     */
    public function link(User $actor, Product $product, ?array $changes = null, int $hours = 1, bool $patch = true): array
    {
        Gate::forUser($actor)->authorize('update', $product);
        $history = app(EditorialHistory::class);
        $version = EditorialActor::run($actor, function () use ($history, $product, $changes, $patch) {
            if ($changes !== null) {
                return $history->storeProposal($product, $this->payload($product, $changes, $patch));
            }
            $history->capture($product);

            return (int) $history->query($product)->where('is_proposal', false)->latest('id')->value('id');
        });

        return app(EditorialOperations::class)->share($actor, $product, $hours, $version);
    }

    /** Revoke a shared preview link; its author or an administrator. */
    public function revoke(User $actor, Product $product, int $link): object
    {
        Gate::forUser($actor)->authorize('update', $product);

        return app(EditorialOperations::class)->revoke($actor, $product, $link);
    }

    /**
     * Unsaved variant models for stored variant payload rows (version payloads and proposals).
     *
     * @return Collection<int, ProductVariant>
     */
    public function variantModels(Product $product, array $rows): Collection
    {
        return collect($rows)->map(function (array $row) use ($product) {
            $variant = new ProductVariant(Arr::except($row, ['id']));
            $variant->id = $row['id'] ?? null;
            $variant->product_id = $product->id;

            return $variant;
        });
    }

    /**
     * Editorial version payload the product would have after the changes: the shape EditorialHistory stores
     * (attributes, media pivot, terms and `variants`), so approving or restoring it rebuilds the variants too.
     */
    public function payload(Product $product, array $changes, bool $patch = true): array
    {
        return $this->compose($product, $changes, $patch)[1];
    }

    /** Store an editor's full-form change to a live product as a proposal version; the product is not written. */
    public function stage(Product $product, array $changes): int
    {
        return app(EditorialHistory::class)->storeProposal($product, $this->payload($product, $changes, false));
    }

    /** @return array{0: Product, 1: array} the unsaved proposal model and its version payload */
    private function compose(Product $product, array $changes, bool $patch): array
    {
        $data = (new ProductInput($changes, $product, true))->validate();
        $base = app(EditorialHistory::class)->payload($product);
        $baseLocale = $product->isBaseLocale();

        $attributes = Arr::only($data, $product->getFillable());
        unset($attributes['status'], $attributes['locale'], $attributes['published_at'], $attributes['expires_at']);
        if (! $patch) {
            foreach (ProductOperations::NULLABLE_FIELDS as $key) {
                $attributes[$key] = $attributes[$key] ?? null;
            }
            if ($baseLocale) {
                $attributes += ProductOperations::COMMERCE_DEFAULTS;
            }
        } elseif (array_key_exists('meta_json', $attributes)) {
            $attributes['meta_json'] = ContentPatch::apply($product->meta_json ?? [], (array) $attributes['meta_json']);
        }
        $proposal = $product->newInstance([], true);
        $proposal->setRawAttributes($product->getAttributes(), true);
        $proposal->fill($attributes);
        $payloadAttributes = EditorialHistory::plainAttributes($proposal->attributesToArray());
        unset($payloadAttributes['id'], $payloadAttributes['created_at'], $payloadAttributes['updated_at']);
        $base['attributes'] = $payloadAttributes;

        $media = collect($base['media']);
        if (! $patch || array_key_exists('featured_image', $data)) {
            $media = $media->reject(fn ($item) => $item['collection'] === 'featured_image');
            if (! empty($data['featured_image'])) {
                $media->push(['id' => (int) $data['featured_image'], 'collection' => 'featured_image', 'order' => 0]);
            }
        }
        if (! $patch || array_key_exists('gallery', $data)) {
            $media = $media->reject(fn ($item) => $item['collection'] === 'gallery');
            foreach (array_values($data['gallery'] ?? []) as $order => $id) {
                $media->push(['id' => (int) $id, 'collection' => 'gallery', 'order' => $order]);
            }
        }
        $base['media'] = $media->values()->all();

        if (! $patch || array_key_exists('brands', $data)) {
            $others = Taxonomy::whereIn('id', $base['taxonomies'])->where('type', '!=', Product::BRAND_TAXONOMY)->pluck('id')->all();
            $base['taxonomies'] = array_values([...$others, ...array_map('intval', $data['brands'] ?? [])]);
        }

        $variants = [];
        if ($baseLocale && $proposal->has_variants) {
            $variants = $product->variants()->get()->map(fn (ProductVariant $variant) => ProductOperations::variantPayload($variant))->all();
            if (! $patch || array_key_exists('variants', $data)) {
                $variants = [];
                foreach (array_values($data['variants'] ?? []) as $order => $row) {
                    $variants[] = ['id' => $row['id'], 'sku' => $row['sku'], 'name' => $row['name'], 'attributes_json' => $row['attributes_json'],
                        'price_amount' => $row['price_amount'], 'compare_at_price_amount' => $row['compare_at_price_amount'],
                        'stock' => $row['stock'], 'weight_grams' => $row['weight_grams'], 'order' => $order];
                }
            }
        }
        $base['variants'] = $variants;

        return [$proposal, $base];
    }
}
