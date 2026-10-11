<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTaxonomyRequest;
use App\Http\Requests\Admin\UpdateTaxonomyRequest;
use App\Models\Taxonomy;
use App\Models\Locale;
use App\Services\Content\ContentSchemaRegistry;
use App\Services\Content\TaxonomyOperations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TaxonomyController extends Controller
{
    public function index(Request $request, string $type): View
    {
        $this->authorize('viewAny', Taxonomy::class);

        $query = Taxonomy::ofType($type)
            ->where('locale', $request->input('locale', Locale::baseCode()))
            ->roots()
            ->ordered()
            ->with(['children', 'translations']);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        return view('admin.taxonomies.index', [
            'taxonomies' => $query->paginate(20)->withQueryString(),
            'type'       => $type,
            'totalCount' => Taxonomy::ofType($type)->count(),
            'locales' => Locale::where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }

    public function create(string $type): View
    {
        $this->authorize('create', Taxonomy::class);

        return view('admin.taxonomies.create', [
            'type'    => $type,
            'parents' => Taxonomy::ofType($type)->where('locale', Locale::baseCode())->roots()->ordered()->get(),
        ]);
    }

    public function store(StoreTaxonomyRequest $request, string $type): JsonResponse|RedirectResponse
    {
        $this->authorize('create', Taxonomy::class);

        $taxonomy = app(TaxonomyOperations::class)->create($request->user(), $type, $request->validated());

        if ($request->wantsJson()) {
            return response()->json(['id' => $taxonomy->id, 'name' => $taxonomy->name]);
        }

        return redirect()
            ->route('admin.taxonomies.index', $type)
            ->with('success', __('admin.taxonomy_created'));
    }

    public function edit(string $type, Taxonomy $taxonomy): View
    {
        $this->authorize('update', $taxonomy);

        return view('admin.taxonomies.edit', [
            'taxonomy' => $taxonomy,
            'type'     => $type,
            'parents'  => Taxonomy::ofType($type)->where('locale', $taxonomy->locale)->roots()->ordered()->where('id', '!=', $taxonomy->id)->get(),
            'staleFieldNames' => app(ContentSchemaRegistry::class)->formNamesFor($taxonomy, $taxonomy->staleTranslatableFields()),
        ]);
    }

    public function update(UpdateTaxonomyRequest $request, string $type, Taxonomy $taxonomy): RedirectResponse
    {
        $this->authorize('update', $taxonomy);

        $data = $request->validated();
        unset($data['acknowledged_fields'], $data['locale']);
        // Saving a translation from the panel form marks it reviewed against its source.
        app(TaxonomyOperations::class)->update($request->user(), $taxonomy, $data, reviewed: true);

        return redirect()
            ->route('admin.taxonomies.index', $type)
            ->with('success', __('admin.taxonomy_updated'));
    }

    public function translate(Request $request, string $type, Taxonomy $taxonomy, string $locale): RedirectResponse
    {
        $this->authorize('create', Taxonomy::class);
        abort_unless(Locale::where('code', $locale)->where('is_active', true)->exists(), 404);

        $existing = $taxonomy->translations()->where('locale', $locale)->first();
        if ($existing) {
            return redirect()->route('admin.taxonomies.edit', [$type, $existing]);
        }

        $translation = app(TaxonomyOperations::class)->translate($request->user(), $taxonomy, $locale);

        return redirect()
            ->route('admin.taxonomies.edit', [$type, $translation])
            ->with('success', __('admin.taxonomy_created'));
    }

    public function destroy(string $type, Taxonomy $taxonomy): RedirectResponse
    {
        $this->authorize('delete', $taxonomy);

        app(TaxonomyOperations::class)->delete(request()->user(), $taxonomy);

        return redirect()
            ->route('admin.taxonomies.index', $type)
            ->with('success', __('admin.taxonomy_deleted'));
    }
}
