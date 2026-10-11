<?php

namespace App\Http\Controllers;

use App\Models\Locale;
use App\Models\Taxonomy;
use App\Services\Content\OperationReceipt;
use App\Services\Content\TaxonomyOperations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Taxonomy terms (categories) for agents; the panel uses the same TaxonomyOperations. */
class McpTaxonomyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeForUser($request->user(), 'viewAny', Taxonomy::class);
        $data = Validator::make($request->all(), [
            'type' => ['required', 'string', Rule::in(config('cms.taxonomy_types', []))],
            'locale' => ['nullable', 'string', Rule::in(Locale::installedCodes())],
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ])->validate();
        $query = Taxonomy::query()->where('type', $data['type'])
            ->when($data['locale'] ?? null, fn ($query, $locale) => $query->where('locale', $locale))
            ->when($data['search'] ?? null, fn ($query, $search) => $query->where(fn ($inner) => $inner->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')->orWhere('slug', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->orderBy('locale')->orderBy('order')->orderBy('name');
        $page = $query->paginate($data['per_page'] ?? 20, ['*'], 'page', $data['page'] ?? 1);

        return response()->json(['data' => collect($page->items())->map(fn (Taxonomy $taxonomy) => $this->serialize($taxonomy))->values(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()]]);
    }

    public function show(Request $request, Taxonomy $taxonomy): JsonResponse
    {
        $this->authorizeForUser($request->user(), 'viewAny', Taxonomy::class);

        return response()->json(['data' => $this->serialize($taxonomy)]);
    }

    public function store(Request $request): JsonResponse
    {
        $type = (string) $request->input('type');
        $taxonomy = app(OperationReceipt::class)->run($request->user(), $request->input('operation_key'), 'taxonomies_create', $request->all(),
            fn () => app(TaxonomyOperations::class)->create($request->user(), $type, $request->except(['type', 'operation_key'])));

        return response()->json(['data' => $this->serialize($taxonomy)], 201);
    }

    public function update(Request $request, Taxonomy $taxonomy): JsonResponse
    {
        Validator::make($request->all(), ['expected_revision' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/']])->validate();
        $taxonomy = app(TaxonomyOperations::class)->update($request->user(), $taxonomy, $request->except(['expected_revision']), $request->input('expected_revision'));

        return response()->json(['data' => $this->serialize($taxonomy)]);
    }

    public function translate(Request $request, Taxonomy $taxonomy): JsonResponse
    {
        Validator::make($request->all(), ['locale' => ['required', 'string', Rule::in(Locale::installedCodes())]])->validate();

        return response()->json(['data' => $this->serialize(app(TaxonomyOperations::class)->translate($request->user(), $taxonomy, $request->input('locale')))]);
    }

    public function destroy(Request $request, Taxonomy $taxonomy): JsonResponse
    {
        Validator::make($request->all(), ['expected_revision' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/']])->validate();
        $deleted = ['type' => 'taxonomies', 'id' => $taxonomy->id, 'name' => $taxonomy->name, 'locale' => $taxonomy->locale];
        $warnings = app(TaxonomyOperations::class)->delete($request->user(), $taxonomy, $request->input('expected_revision'));

        return response()->json(['data' => ['deleted' => $deleted]] + ($warnings === [] ? [] : ['warnings' => $warnings]));
    }

    private function serialize(Taxonomy $taxonomy): array
    {
        return ['id' => $taxonomy->id, 'revision' => app(TaxonomyOperations::class)->revision($taxonomy), 'type' => $taxonomy->type, 'locale' => $taxonomy->locale,
            'name' => $taxonomy->name, 'slug' => $taxonomy->slug, 'description' => $taxonomy->description, 'parent_id' => $taxonomy->parent_id, 'order' => $taxonomy->order,
            'translation_group_id' => $taxonomy->translation_group_id, 'translation_status' => $taxonomy->translation_status, 'stale_fields' => $taxonomy->staleTranslatableFields(),
            'usage_count' => DB::table('taxables')->where('taxonomy_id', $taxonomy->id)->count()];
    }
}
