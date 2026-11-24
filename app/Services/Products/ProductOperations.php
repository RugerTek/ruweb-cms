<?php

namespace App\Services\Products;

use App\Mcp\ContentPatch;
use App\Models\Locale;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Content\ContentDeletion;
use App\Services\Content\RevisionGuard;
use App\Services\Editorial\EditorialActor;
use App\Services\Editorial\EditorialHistory;
use App\Services\Revisions\ContentRevisionLedger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Shop product writes shared by the panel and MCP (PLAN.md §2.2), modelled on PostOperations: explicit actor,
 * Gate::forUser, one ledger transaction per operation, optimistic revisions, and only administrators publish.
 * Variants are part of the product: a submitted `variants` list replaces the stored one (rows with an `id` are
 * kept and updated, rows without one are created, missing ones are deleted) and they count in the revision.
 */
final class ProductOperations
{
    /** Columns a full (non-patch) save resets when the input omits them, as a form that cleared them would. */
    public const NULLABLE_FIELDS = ['excerpt', 'description', 'meta_json'];

    /** Commerce columns of a base-language row after a full save that omits them (simple product, no price). */
    public const COMMERCE_DEFAULTS = ['sku' => null, 'price_amount' => null, 'compare_at_price_amount' => null, 'has_variants' => false,
        'track_stock' => false, 'allow_backorder' => false, 'stock' => null, 'weight_grams' => null, 'dimensions_json' => null];

    /**
     * Create ($product null) or update. $patch edits in place (omitted keys survive, meta_json merges) and keeps the
     * status; $draftOnly is the MCP draft scope: creates drafts and only touches drafts. An editor's full save of a
     * published product stages a proposal version instead of writing it.
     */
    public function save(User $actor, array $input, ?Product $product = null, bool $draftOnly = false, bool $patch = false): Product
    {
        Gate::forUser($actor)->authorize($product ? 'update' : 'create', $product ?? Product::class);
        $patch = $patch && $product !== null;

        return $this->transaction($actor, function () use ($actor, $input, $product, $draftOnly, $patch) {
            $product = $product ? Product::query()->lockForUpdate()->findOrFail($product->id) : null;
            if ($draftOnly && $product && $product->status !== 'draft') {
                throw new AuthorizationException(__('editorial.mcp_draft_required'));
            }
            if ($patch && $product->status !== 'draft' && ! $actor->isAdmin()) {
                throw new AuthorizationException(__('editorial.mcp_live_edit_admin_only'));
            }
            if ($draftOnly && ! $product) {
                $input['status'] = 'draft';
            }
            $partial = ($draftOnly || $patch) && $product !== null;
            $data = (new ProductInput($input, $product, $partial, $draftOnly))->validate();
            $this->checkRevision($product, $data['expected_revision'] ?? null);
            unset($data['expected_revision'], $data['acknowledged_fields']);
            if (! $draftOnly && ! $patch && $product && $actor->isEditor() && $product->status === 'published') {
                app(ProductPreview::class)->stage($product, $input);

                return $product;
            }

            $data['status'] = $draftOnly ? 'draft' : ($patch ? $product->status : $data['status']);
            if ($data['status'] === 'published' && ! $actor->isAdmin()) {
                throw new AuthorizationException(__('editorial.publish_forbidden'));
            }
            $data['locale'] = $data['locale'] ?? $product?->locale ?? Locale::baseCode();
            $data['updated_by'] = $actor->id;
            if ($data['status'] === 'published' && empty($data['published_at'])) {
                $data['published_at'] = $product?->published_at ?? now();
            }
            $baseLocale = $data['locale'] === Locale::baseCode();
            $relations = [];
            foreach (['featured_image', 'gallery', 'brands', 'categories', 'variants'] as $key) {
                if (! $partial || array_key_exists($key, $data)) {
                    $relations[$key] = $data[$key] ?? null;
                }
                unset($data[$key]);
            }
            if (! $baseLocale) {
                unset($relations['variants']);
            }
            if ($partial && isset($data['meta_json'])) {
                $data['meta_json'] = ContentPatch::apply($product->meta_json ?? [], $data['meta_json']);
            }
            if (! $partial) {
                foreach (self::NULLABLE_FIELDS as $key) {
                    $data[$key] = $data[$key] ?? null;
                }
                if ($baseLocale) {
                    $data += self::COMMERCE_DEFAULTS;
                }
            }
            // Turning variants off makes the product simple again: its variant rows go with the flag.
            if ($baseLocale && array_key_exists('has_variants', $data) && ! $data['has_variants']) {
                $relations['variants'] = [];
            }

            if ($product) {
                $product->update($data);
            } else {
                $product = Product::create($data + ['created_by' => $actor->id]);
            }
            if ($product->isBaseLocale()) {
                $product->source_fingerprint = $product->translatableFingerprint();
                $product->source_field_hashes = $product->translatableFieldFingerprints();
            } elseif (! $partial) {
                $product->syncTranslationFromBase();
            }
            $product->save();
            $this->syncRelations($product, $relations);
            $product = $product->fresh();
            app(EditorialHistory::class)->capture($product);

            return $product;
        });
    }

    public function publish(User $actor, Product $product, ?string $expectedRevision = null): Product
    {
        return $this->changePublication($actor, $product, fn (Product $p) => ['status' => 'published', 'published_at' => $p->published_at && $p->published_at->lte(now()) ? $p->published_at : now()], $expectedRevision);
    }

    /** Publish at a future date; the public catalog hides it until then. */
    public function schedule(User $actor, Product $product, string $publishedAt, ?string $expectedRevision = null): Product
    {
        return $this->changePublication($actor, $product, function (Product $p) use ($publishedAt) {
            Validator::make(['published_at' => $publishedAt, 'expires_at' => $p->expires_at], [
                'published_at' => ['required', 'date', 'after:now'],
                'expires_at' => ['nullable', 'date', 'after:published_at'],
            ])->validate();

            return ['status' => 'published', 'published_at' => $publishedAt];
        }, $expectedRevision);
    }

    /** Null clears the expiry; cms:expire-content unpublishes once it passes. */
    public function setExpiry(User $actor, Product $product, ?string $expiresAt, ?string $expectedRevision = null): Product
    {
        return $this->changePublication($actor, $product, function (Product $p) use ($expiresAt) {
            Validator::make(['expires_at' => $expiresAt, 'published_at' => $p->published_at], ['expires_at' => ['nullable', 'date', 'after:now', 'after:published_at']])->validate();

            return ['expires_at' => $expiresAt];
        }, $expectedRevision);
    }

    public function unpublish(User $actor, Product $product, ?string $expectedRevision = null): Product
    {
        return $this->changePublication($actor, $product, fn () => ['status' => 'draft'], $expectedRevision);
    }

    /** Returns what the deletion left behind; see ContentDeletion. Variants are deleted with the product. */
    public function delete(User $actor, Product $product, ?string $expectedRevision = null): array
    {
        Gate::forUser($actor)->authorize('delete', $product);

        return $this->transaction($actor, function () use ($product, $expectedRevision) {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            $this->checkRevision($product, $expectedRevision);
            $warnings = app(ContentDeletion::class)->cleanUp($product);
            app(EditorialHistory::class)->capture($product);
            $product->variants()->get()->each->delete();
            $product->media()->detach();
            $product->taxonomies()->detach();
            $product->delete();

            return $warnings;
        });
    }

    /**
     * Optimistic revision clients send back as `expected_revision`: the editorial fingerprint, whose payload holds
     * the variant list too, so it is the same value content_* tools and RevisionGuard use.
     */
    public function revision(Product $product): string
    {
        return app(EditorialHistory::class)->fingerprint($product);
    }

    /** Stable representation of a stored variant (revision, previews, staged proposals). */
    public static function variantPayload(ProductVariant $variant): array
    {
        return [
            'id' => $variant->id,
            'sku' => $variant->sku,
            'name' => $variant->name,
            'attributes_json' => $variant->attributes_json,
            'price_amount' => $variant->price_amount?->amount,
            'compare_at_price_amount' => $variant->compare_at_price_amount?->amount,
            'stock' => $variant->stock,
            'weight_grams' => $variant->weight_grams,
            'order' => $variant->order,
        ];
    }

    private function syncRelations(Product $product, array $relations): void
    {
        if (array_key_exists('featured_image', $relations)) {
            $product->media()->wherePivot('collection', 'featured_image')->detach();
            if ($relations['featured_image']) {
                $product->attachMedia((int) $relations['featured_image'], 'featured_image');
            }
        }
        if (array_key_exists('gallery', $relations)) {
            $product->media()->wherePivot('collection', 'gallery')->detach();
            foreach (array_values($relations['gallery'] ?? []) as $order => $mediaId) {
                $product->attachMedia((int) $mediaId, 'gallery', $order);
            }
        }
        if (array_key_exists('brands', $relations)) {
            $product->syncTaxonomies(array_map('intval', $relations['brands'] ?? []), Product::BRAND_TAXONOMY);
        }
        if (array_key_exists('categories', $relations)) {
            $product->syncTaxonomies(array_map('intval', $relations['categories'] ?? []), Product::CATEGORY_TAXONOMY);
        }
        if (array_key_exists('variants', $relations)) {
            $this->replaceVariants($product, $relations['variants'] ?? []);
        }
    }

    /** The submitted list is the whole list; its position is the display order. */
    private function replaceVariants(Product $product, array $variants): void
    {
        $existing = $product->variants()->get()->keyBy('id');
        $kept = array_filter(array_column($variants, 'id'));
        foreach ($existing as $id => $variant) {
            if (! in_array($id, $kept, true)) {
                $variant->delete();
            }
        }
        // Free the kept rows' skus first so two variants can swap skus without hitting the unique index.
        foreach ($variants as $row) {
            if ($row['id'] && $existing[$row['id']]->sku !== $row['sku'] && $existing[$row['id']]->sku !== null) {
                $existing[$row['id']]->update(['sku' => null]);
            }
        }
        foreach (array_values($variants) as $order => $row) {
            $attributes = ['order' => $order] + array_intersect_key($row, array_flip(['sku', 'name', 'attributes_json', 'price_amount', 'compare_at_price_amount', 'stock', 'weight_grams']));
            if ($row['id']) {
                $existing[$row['id']]->update($attributes);
            } else {
                $product->variants()->create($attributes);
            }
        }
    }

    private function changePublication(User $actor, Product $product, \Closure $data, ?string $expectedRevision): Product
    {
        Gate::forUser($actor)->authorize('update', $product);
        if (! $actor->isAdmin()) {
            throw new AuthorizationException(__('editorial.publish_forbidden'));
        }

        return $this->transaction($actor, function () use ($actor, $product, $data, $expectedRevision) {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            $this->checkRevision($product, $expectedRevision);
            $product->update($data($product) + ['updated_by' => $actor->id]);
            app(EditorialHistory::class)->capture($product->fresh());

            return $product->fresh();
        });
    }

    /** Null skips (compatible clients), a stale value is a 409. */
    private function checkRevision(?Product $product, ?string $expected): void
    {
        if ($product) {
            RevisionGuard::check($product, $expected);
        }
    }

    private function transaction(User $actor, \Closure $callback): mixed
    {
        return EditorialActor::run($actor, fn () => app(ContentRevisionLedger::class)->transaction($callback, 3));
    }
}
