<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\RevisionConflict;
use App\Helpers\MoneyHelper;
use App\Http\Controllers\Controller;
use App\Models\Locale;
use App\Models\Product;
use App\Models\Taxonomy;
use App\Services\Content\TranslationOperations;
use App\Services\Products\ProductOperations;
use App\Services\Products\ProductPreview;
use App\Support\AdminCapabilities;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Panel CRUD for shop products (Full profile). Thin over ProductOperations / ProductInput / ProductPreview, which
 * MCP shares: this class only maps the form to the operation's input shape. In Essential the index is the read-only
 * operational list (/admin/content/products) and every other action is a 404 (RequireLiveEditor in the routes).
 */
class ProductController extends Controller
{
    public function index(Request $request): View
    {
        if (! app(AdminCapabilities::class)->liveEditor()) {
            return app(ContentStatusController::class)->index($request, 'products');
        }
        $this->authorize('viewAny', Product::class);

        $base = Product::query()->where('locale', Locale::baseCode());
        $query = (clone $base)->with(['translations', 'variants'])->latest();
        if ($request->filled('status') && in_array($request->input('status'), config('cms.statuses'), true)) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('brand')) {
            $query->whereHas('taxonomies', fn ($q) => $q->where('taxonomies.id', (int) $request->input('brand')));
        }
        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where(fn ($q) => $q->where('title', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")
                ->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', "%{$search}%")));
        }

        return view('admin.products.index', [
            'products' => $query->paginate(15)->withQueryString(),
            'brands' => $this->brands(Locale::baseCode()),
            'totalCount' => (clone $base)->count(),
            'publishedCount' => (clone $base)->where('status', 'published')->count(),
            'draftCount' => (clone $base)->where('status', 'draft')->count(),
            'currentStatus' => $request->input('status'),
            'locales' => Locale::where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Product::class);

        return view('admin.products.create', [
            'product' => null,
            'brands' => $this->brands(Locale::baseCode()),
        ] + $this->backLink($request));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Product::class);
        $product = app(ProductOperations::class)->save($request->user(), $this->payload($request, null));

        return redirect()->route('admin.products.edit', ['product' => $product, 'return' => $this->returnUrl($request)])
            ->with('success', __('shop.product_created'));
    }

    public function edit(Request $request, Product $product): View
    {
        $this->authorize('update', $product);

        return view('admin.products.edit', [
            'product' => $product->load(['media', 'taxonomies', 'variants', 'translations']),
            'brands' => $this->brands($product->locale),
            'locales' => Locale::where('is_active', true)->orderBy('sort_order')->get(),
            'revision' => app(ProductOperations::class)->revision($product),
        ] + $this->backLink($request));
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);
        $staged = $request->user()->isEditor() && $product->status === 'published';
        try {
            app(ProductOperations::class)->save($request->user(), $this->payload($request, $product) + ['expected_revision' => $request->input('expected_revision')], $product);
        } catch (RevisionConflict) {
            return back()->withInput()->withErrors(['expected_revision' => __('editorial.conflict')]);
        }

        return redirect()->route('admin.products.edit', ['product' => $product, 'return' => $this->returnUrl($request)])
            ->with('success', $staged ? __('editorial.staged') : __('shop.product_updated'));
    }

    /** The form as it is now (saved or not) rendered with the public view; nothing is written. */
    public function preview(Request $request, Product $product): Response
    {
        $this->authorize('update', $product);
        $changes = collect($this->payload($request, $product))->except(['status', 'locale', 'published_at', 'expires_at'])->all();

        return app(ProductPreview::class)->render($request->user(), $product, $changes, false);
    }

    /** Creates (or returns, if it already exists) the translation of the product's group into $locale, without commerce fields. */
    public function translate(Request $request, Product $product, string $locale): RedirectResponse
    {
        $this->authorize('create', Product::class);
        $translation = app(TranslationOperations::class)->create($request->user(), $product, $locale);

        return redirect()->route('admin.products.edit', ['product' => $translation, 'return' => $this->returnUrl($request)])
            ->with('success', __('shop.product_created'));
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('delete', $product);
        app(ProductOperations::class)->delete($request->user(), $product);

        return redirect()->route('admin.products.index')->with('success', __('shop.product_deleted'));
    }

    /**
     * Maps the form to ProductInput's shape. Prices are typed as "150.000" and become integers; an empty price
     * stays null (catalog item without public price). Commerce fields go only for base-language rows, and the
     * variant list only while the product has variants (turning them off empties it in the operation).
     */
    private function payload(Request $request, ?Product $product): array
    {
        $locale = $product?->locale ?? Locale::baseCode();
        $nullable = fn (string $key) => $request->filled($key) ? $request->input($key) : null;
        $items = (array) $request->input('items', []);

        $data = [
            'title' => $request->input('title'),
            'slug' => $request->input('slug'),
            'locale' => $locale,
            'status' => $request->input('status', 'draft'),
            'excerpt' => $nullable('excerpt'),
            'description' => $nullable('description'),
            'featured_image' => $nullable('featured_image'),
            'gallery' => collect($items['gallery'] ?? [])->pluck('media_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
            'brands' => array_values(array_map('intval', (array) $request->input('brands', []))),
            'meta_json' => array_filter((array) $request->input('meta_json', []), fn ($value) => filled($value)) ?: null,
        ];
        if ($request->user()->isAdmin()) {
            $data['published_at'] = $nullable('published_at');
            $data['expires_at'] = $nullable('expires_at');
        }
        if ($locale !== Locale::baseCode()) {
            return $data;
        }

        $hasVariants = $request->boolean('has_variants');
        $dimensions = array_filter((array) $request->input('dimensions_json', []), fn ($value) => filled($value));
        $data += [
            'sku' => $nullable('sku'),
            'price_amount' => $this->amount($request->input('price_amount')),
            'compare_at_price_amount' => $this->amount($request->input('compare_at_price_amount')),
            'has_variants' => $hasVariants,
            'track_stock' => $request->boolean('track_stock'),
            'allow_backorder' => $request->boolean('allow_backorder'),
            'stock' => $hasVariants ? null : $nullable('stock'),
            'weight_grams' => $nullable('weight_grams'),
            'dimensions_json' => $dimensions ?: null,
        ];
        if ($hasVariants) {
            $data['price_amount'] = $data['compare_at_price_amount'] = null;
            $data['variants'] = collect($items['variants'] ?? [])->filter(fn ($row) => is_array($row))->map(fn (array $row) => [
                'id' => filled($row['id'] ?? null) ? (int) $row['id'] : null,
                'sku' => filled($row['sku'] ?? null) ? $row['sku'] : null,
                'name' => $row['name'] ?? null,
                'attributes_json' => $this->attributes((string) ($row['attributes'] ?? '')),
                'price_amount' => $this->amount($row['price_amount'] ?? null),
                'compare_at_price_amount' => $this->amount($row['compare_at_price_amount'] ?? null),
                'stock' => filled($row['stock'] ?? null) ? $row['stock'] : null,
                'weight_grams' => filled($row['weight_grams'] ?? null) ? $row['weight_grams'] : null,
            ])->values()->all();
        }

        return $data;
    }

    /** "150.000" → 150000; empty → null; anything that is not a number goes through as typed so validation rejects it. */
    private function amount(mixed $value): mixed
    {
        if (! filled($value)) {
            return null;
        }
        $value = trim((string) $value);

        return preg_match('/^\d[\d.\s]*$/', $value) ? MoneyHelper::parseInput($value) : $value;
    }

    /** One "Name: Value" per line → ['Name' => 'Value']; blank lines are skipped. */
    private function attributes(string $text): ?array
    {
        $attributes = [];
        foreach (preg_split('/\R/', $text) as $line) {
            if (trim($line) === '') {
                continue;
            }
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $attributes[trim($name)] = trim($value);
        }

        return $attributes ?: null;
    }

    private function brands(string $locale): Collection
    {
        return Taxonomy::ofType(Product::BRAND_TAXONOMY)->where('locale', $locale)->ordered()->get();
    }

    private function backLink(Request $request): array
    {
        $return = $this->returnUrl($request);

        return [
            'editorReturn' => $return ?? route('admin.products.index'),
            'editorBackHref' => $return ?? route('admin.products.index'),
            'editorBackTitle' => $return ? __('admin.back_to_previous_page') : __('shop.back_to_products'),
        ];
    }

    /** Same-site return target only (the admin list or the public page the editor came from). */
    private function returnUrl(Request $request): ?string
    {
        $value = $request->input('return', $request->query('return'));
        if (! is_string($value) || $value === '' || preg_match('/[\\\\\x00-\x1f]/', $value)) {
            return null;
        }
        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return url($value);
        }
        $app = parse_url(url('/'));
        $candidate = parse_url($value);

        return is_array($app) && is_array($candidate)
            && ($candidate['scheme'] ?? null) === ($app['scheme'] ?? null)
            && ($candidate['host'] ?? null) === ($app['host'] ?? null)
            && ($candidate['port'] ?? null) === ($app['port'] ?? null) ? $value : null;
    }
}
