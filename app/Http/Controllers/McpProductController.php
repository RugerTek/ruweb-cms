<?php

namespace App\Http\Controllers;

use App\Mcp\ContentResponse;
use App\Mcp\SanitizationNotice;
use App\Models\Locale;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Content\OperationReceipt;
use App\Services\Products\ProductOperations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * MCP tools and REST mirror of the optional shop catalog (`products_*`, docs/operations/mcp.md#shop-catalog).
 * Registered only while `cms.shop.mode` enables the catalog. Every write goes through ProductOperations, the same
 * operation the panel uses; `revision` is ProductOperations::revision() (it includes the variant list).
 */
class McpProductController extends McpController
{
    private const REVISION = ['nullable', 'string', 'size:64', 'regex:/^[a-f0-9]+$/'];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeForUser($request->user(), 'viewAny', Product::class);
        $validated = Validator::make($request->all(), [
            'fields' => ['sometimes', 'array', 'max:30'],
            'fields.*' => ['string', 'max:100'],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(config('cms.statuses'))],
            'locale' => ['nullable', 'string', Rule::in(Locale::installedCodes())],
            'brand' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ])->validate();

        $query = Product::query()->with(['media', 'variants'])->latest()->orderByDesc('id');
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['locale'])) {
            $query->where('locale', $validated['locale']);
        }
        if (! empty($validated['brand'])) {
            $query->whereHas('taxonomies', fn ($q) => $q->where('taxonomies.id', $validated['brand'])->where('type', Product::BRAND_TAXONOMY));
        }
        if (! empty($validated['search'])) {
            $query->where(fn ($q) => $q->where('title', 'like', '%'.$validated['search'].'%')
                ->orWhere('slug', 'like', '%'.$validated['search'].'%')
                ->orWhere('sku', 'like', '%'.$validated['search'].'%'));
        }
        $products = $query->paginate($validated['per_page'] ?? config('cms.mcp.per_page', 20), ['*'], 'page', $validated['page'] ?? 1);

        return response()->json([
            'data' => $products->getCollection()->map(fn (Product $product) => $this->project($this->serializeProduct($product, summary: true), $request))->values(),
            'meta' => ['total' => $products->total(), 'current_page' => $products->currentPage(), 'last_page' => $products->lastPage(), 'per_page' => $products->perPage()],
        ]);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->authorizeForUser($request->user(), 'view', $product);

        return response()->json(['data' => $this->project($this->serializeProduct($product), $request)]);
    }

    public function storeDraft(Request $request): JsonResponse
    {
        $product = app(OperationReceipt::class)->run($request->user(), $request->input('operation_key'), 'products_create_draft', $request->all(),
            fn () => app(ProductOperations::class)->save($request->user(), $request->except('operation_key'), draftOnly: true));

        return response()->json(['data' => $this->serializeProduct($product)] + $this->warnings($request), 201);
    }

    public function updateDraft(Request $request, Product $product): JsonResponse
    {
        Validator::make($request->all(), ['expected_revision' => self::REVISION])->validate();
        $product = app(ProductOperations::class)->save($request->user(), $request->all(), $product, draftOnly: true);

        return response()->json(['data' => $this->serializeProduct($product)] + $this->warnings($request));
    }

    /** Live Editor parity: patch a draft or, for administrators, a published product in place. */
    public function update(Request $request, Product $product): JsonResponse
    {
        Validator::make($request->all(), ['expected_revision' => ['required', ...array_slice(self::REVISION, 1)]])->validate();
        $product = app(ProductOperations::class)->save($request->user(), $request->all(), $product, patch: true);

        return response()->json(['data' => $this->serializeProduct($product)] + $this->warnings($request));
    }

    public function publish(Request $request, Product $product): JsonResponse
    {
        Validator::make($request->all(), ['expected_revision' => self::REVISION])->validate();
        $product = app(ProductOperations::class)->publish($request->user(), $product, $request->input('expected_revision'));

        return response()->json(['data' => $this->serializeProduct($product)]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        Validator::make($request->all(), ['expected_revision' => ['required', ...array_slice(self::REVISION, 1)]])->validate();
        $deleted = ['type' => 'products', 'id' => $product->id, 'title' => $product->title, 'locale' => $product->locale, 'url' => $product->url()];
        $warnings = app(ProductOperations::class)->delete($request->user(), $product, $request->input('expected_revision'));

        return response()->json(['data' => ['deleted' => $deleted]] + ($warnings === [] ? [] : ['warnings' => $warnings]));
    }

    /** Markup the sanitizer removed from the submitted description is reported, never dropped silently. */
    private function warnings(Request $request): array
    {
        $warnings = array_map(fn (array $warning) => ['field' => 'description'] + $warning, SanitizationNotice::forPost($request->input('description')));

        return $warnings === [] ? [] : ['warnings' => $warnings];
    }

    private function serializeProduct(Product $product, bool $summary = false): array
    {
        $product->loadMissing(['media', 'variants']);
        $featured = $product->featuredImage();
        $data = [
            'id' => $product->id,
            'revision' => app(ProductOperations::class)->revision($product),
            'title' => $product->title,
            'slug' => $product->slug,
            'locale' => $product->locale,
            'translation_group_id' => $product->translation_group_id,
            'url' => $product->url(),
            'status' => $product->status,
            'published_at' => $product->published_at?->toISOString(),
            'expires_at' => $product->expires_at?->toISOString(),
            'sku' => $product->sku,
            'currency' => config('cms.shop.currency'),
            'price_amount' => $product->price_amount?->amount,
            'compare_at_price_amount' => $product->compare_at_price_amount?->amount,
            'display_price_amount' => $product->displayPrice()?->amount,
            'has_variants' => (bool) $product->has_variants,
            'featured_image' => $featured ? $this->serializeMedia($featured) : null,
        ];
        if ($summary) {
            return $data;
        }

        return ContentResponse::bounded($data + [
            'translation_status' => $product->translation_status,
            'stale_fields' => $product->staleTranslatableFields(),
            'excerpt' => $product->excerpt,
            'description' => $product->description,
            'meta_json' => $product->meta_json,
            'brands' => $product->brands()->pluck('taxonomies.id')->all(),
            'categories' => $product->categories()->orderBy('taxonomies.id')->pluck('taxonomies.id')->all(),
            'gallery' => $product->mediaByCollection('gallery')->get()->map(fn ($media) => $this->serializeMedia($media))->values()->all(),
            'track_stock' => (bool) $product->track_stock,
            'allow_backorder' => (bool) $product->allow_backorder,
            'stock' => $product->stock,
            'weight_grams' => $product->weight_grams,
            'dimensions_json' => $product->dimensions_json,
            'variants' => $product->variants->map(fn (ProductVariant $variant) => ProductOperations::variantPayload($variant))->values()->all(),
            'created_at' => $product->created_at?->toISOString(),
            'updated_at' => $product->updated_at?->toISOString(),
        ]);
    }
}
