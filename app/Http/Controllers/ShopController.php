<?php

namespace App\Http\Controllers;

use App\Models\Locale;
use App\Models\Product;
use App\Models\Taxonomy;
use App\Support\CustomCodeDestinations;
use App\Support\ShopModule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Public shop catalog (optional module, cms.shop.mode). Mirrors BlogController: one listing per language, published
 * rows only (status, published_at and expires_at), and a secondary-language request without its own row falls back
 * to the base language. Commerce fields (prices, stock, variants) are read from the base-language row of the group.
 */
class ShopController extends Controller
{
    public const VARIANT_QUERY = 'variante';

    public function index(Request $request): View|RedirectResponse
    {
        $locale = $request->route('locale');
        if (is_string($locale) && ! $this->isSecondaryPublicLocale($locale)) {
            return redirect($this->basePath(), 302);
        }

        $request->attributes->set('custom_code_target', ['type' => 'indexes', 'id' => 'shop', 'locale' => app()->getLocale()]);

        $current = app()->getLocale();
        $query = Product::published()
            ->where('locale', $current)
            ->with($this->catalogRelations())
            ->latest('published_at')
            ->latest('id');

        $activeFilters = [];
        foreach ($this->filterTypes() as $type) {
            $slug = $request->query($type);
            if (! is_string($slug) || $slug === '') {
                continue;
            }
            $activeFilters[$type] = $slug;
            $query->whereHas('taxonomies', fn (Builder $q) => $q->where('type', $type)
                ->where('locale', $current)
                ->where('slug', $slug));
        }

        $search = $request->query('q');
        $search = is_string($search) ? trim($search) : '';
        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn (Builder $q) => $q->where('title', 'like', $like)->orWhere('excerpt', 'like', $like));
        }

        return view('shop.index', [
            'products' => $query->paginate(12)->withQueryString(),
            'filters' => $this->filterOptions($current),
            'activeFilters' => $activeFilters,
            'search' => $search,
        ]);
    }

    public function show(Request $request): View|RedirectResponse
    {
        $slug = (string) $request->route('slug');
        $locale = $request->route('locale');

        if (is_string($locale) && ! $this->isSecondaryPublicLocale($locale)) {
            return redirect($this->basePath().'/'.$slug, 302);
        }

        $product = Product::published()
            ->where('locale', app()->getLocale())
            ->where('slug', $slug)
            ->first();

        if (! $product && app()->getLocale() !== Locale::baseCode()) {
            $base = Product::published()
                ->where('locale', Locale::baseCode())
                ->where('slug', $slug)
                ->first();

            return redirect($base?->url() ?: $this->basePath(), 302);
        }

        abort_if(! $product, 404);

        $product->load([...$this->catalogRelations(), 'taxonomies']);

        $request->attributes->set('custom_code_target', app(CustomCodeDestinations::class)->forModel($product));

        return view('shop.show', [
            'product' => $product,
            'page' => $product,
        ]);
    }

    /** Taxonomy types the catalog filters by: the module's types that are registered in cms.taxonomy_types. */
    private function filterTypes(): array
    {
        return array_values(array_intersect(
            config('cms.shop.taxonomy_types', []),
            config('cms.taxonomy_types', []),
        ));
    }

    /** @return array<string, Collection<int, Taxonomy>> terms in use by published products of this language */
    private function filterOptions(string $locale): array
    {
        $productIds = Product::published()->where('locale', $locale)->select('id');
        $usedTermIds = DB::table('taxables')
            ->where('taxable_type', (new Product)->getMorphClass())
            ->whereIn('taxable_id', $productIds)
            ->select('taxonomy_id');

        $options = [];
        foreach ($this->filterTypes() as $type) {
            $terms = Taxonomy::ofType($type)
                ->where('locale', $locale)
                ->whereIn('id', $usedTermIds)
                ->ordered()
                ->get();
            if ($terms->isNotEmpty()) {
                $options[$type] = $terms;
            }
        }

        return $options;
    }

    /** Media and the base-language row with its variants, where prices and stock live. */
    private function catalogRelations(): array
    {
        $base = Locale::baseCode();

        return [
            'media',
            'variants',
            'translations' => fn ($query) => $query->where('locale', $base)->with('variants'),
        ];
    }

    private function isSecondaryPublicLocale(string $locale): bool
    {
        return $locale !== Locale::baseCode() && Locale::secondaryPublicCodes()->contains($locale);
    }

    private function basePath(): string
    {
        return '/'.app(ShopModule::class)->path();
    }
}
