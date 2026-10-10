<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePageRequest;
use App\Http\Requests\Admin\UpdatePageRequest;
use App\Models\Locale;
use App\Models\Page;
use App\Services\Content\ContentSchemaRegistry;
use App\Services\Editorial\EditorialHistory;
use App\Services\Pages\PageOperations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class PageController extends Controller
{
    public function index(Request $request): View
    {
        $baseLocale = Locale::baseCode();
        $query = Page::query()->where('locale', $baseLocale)->with('translations')->latest();

        if ($request->filled('status') && in_array($request->input('status'), config('cms.statuses'))) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('title', 'like', "%{$search}%");
        }

        return view('admin.pages.index', [
            'pages' => $query->paginate(15)->withQueryString(),
            'totalCount' => Page::where('locale', $baseLocale)->count(),
            'publishedCount' => Page::where('locale', $baseLocale)->where('status', 'published')->count(),
            'draftCount' => Page::where('locale', $baseLocale)->where('status', 'draft')->count(),
            'currentStatus' => $request->input('status'),
            'locales' => Locale::where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.pages.create', [
            'templates' => config('cms.templates'),
        ] + $this->resolveEditorBackLink($request, 'admin.pages.index', __('admin.back_to_pages')));
    }

    public function store(StorePageRequest $request): RedirectResponse
    {
        $page = app(PageOperations::class)->save($request->user(), $request->all());

        return redirect()
            ->route('admin.pages.edit', ['page' => $page, 'return' => $this->normalizeReturnUrl($request->input('return', $request->query('return')))])
            ->with('success', __('admin.page_created'));
    }

    public function edit(Request $request, Page $page): View
    {
        app(EditorialHistory::class)->capture($page);

        return view('admin.pages.edit', [
            'editorLease' => $request->attributes->get('editorLease'),
            'page' => $page,
            'templates' => config('cms.templates'),
            'locales' => Locale::where('is_active', true)->orderBy('sort_order')->get(),
            'staleFieldNames' => app(ContentSchemaRegistry::class)->formNamesFor($page, $page->staleTranslatableFields()),
        ] + $this->resolveEditorBackLink($request, 'admin.pages.index', __('admin.back_to_pages')));
    }

    public function update(UpdatePageRequest $request, Page $page): RedirectResponse|JsonResponse
    {
        $staged = $request->user()->isEditor() && $page->status === 'published';
        $page = app(PageOperations::class)->save(
            $request->user(), $request->all(), $page,
            base: $request->filled('_editor_base') ? $request->input('_editor_base') : null,
        );
        if ($request->wantsJson()) {
            return response()->json(['saved' => true, ...($staged ? ['staged' => true] : []), 'fingerprint' => app(EditorialHistory::class)->fingerprint($page)]);
        }
        if ($staged) {
            return back()->with('success', __('editorial.staged'));
        }

        return redirect()
            ->route('admin.pages.edit', ['page' => $page, 'return' => $this->normalizeReturnUrl($request->input('return', $request->query('return')))])
            ->with('success', __('admin.page_updated'));
    }

    public function previewRender(Request $request, Page $page): Response
    {
        $page->title = $request->input('title', $page->title);
        $page->template_key = $request->input('template_key', $page->template_key);
        $page->content_json = $request->input('content_json', $page->content_json ?? []);
        app()->setLocale($page->locale);

        $html = view($page->previewView(), $page->previewData() + ['isEditorPreview' => true])->render();

        return response($html)->header('Content-Type', 'text/html');
    }

    public function destroy(Page $page): RedirectResponse
    {
        abort_if(request()->user()->isEditor() && $page->status === 'published', 403, __('editorial.publish_forbidden'));
        $page->media()->detach();
        $page->delete();

        return redirect()
            ->route('admin.pages.index')
            ->with('success', __('admin.page_deleted'));
    }

    public function translate(Request $request, Page $page, string $locale): RedirectResponse
    {
        abort_unless(Locale::where('code', $locale)->where('is_active', true)->exists(), 404);
        abort_if(! $page->isTranslationSchemaReady(), 422, 'This page template is not translation-ready.');

        $existing = $page->translations()->where('locale', $locale)->first();
        if ($existing) {
            return redirect()->route('admin.pages.edit', ['page' => $existing, 'return' => $this->normalizeReturnUrl($request->input('return'))]);
        }

        $base = $page->baseTranslation() ?: $page;
        $translation = $base->replicate(['slug', 'published_at', 'expires_at']);
        $translation->locale = $locale;
        $translation->slug = $this->uniqueTranslatedSlug($base->slug, $locale);
        $translation->status = 'draft';
        $translation->translation_status = 'needs_review';
        $translation->source_fingerprint = $base->translatableFingerprint();
        $translation->source_field_hashes = $base->translatableFieldFingerprints();
        $translation->created_by = $request->user()->id;
        $translation->updated_by = $request->user()->id;
        $translation->save();

        foreach ($base->media as $media) {
            $translation->attachMedia($media->id, $media->pivot->collection, $media->pivot->order);
        }

        return redirect()
            ->route('admin.pages.edit', ['page' => $translation, 'return' => $this->normalizeReturnUrl($request->input('return'))])
            ->with('success', __('admin.page_created'));
    }

    private function uniqueTranslatedSlug(string $slug, string $locale): string
    {
        $candidate = $slug;
        $counter = 2;

        while (Page::where('locale', $locale)->where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.$counter;
            $counter++;
        }

        return $candidate;
    }

    /**
     * @return array{editorBackHref:string,editorBackTitle:string}
     */
    private function resolveEditorBackLink(Request $request, string $defaultRoute, string $defaultTitle): array
    {
        $returnUrl = $this->normalizeReturnUrl($request->input('return', $request->query('return')));

        if ($returnUrl !== null) {
            return [
                'editorReturn' => $returnUrl,
                'editorBackHref' => $returnUrl,
                'editorBackTitle' => __('admin.back_to_previous_page'),
            ];
        }

        return [
            'editorReturn' => route($defaultRoute),
            'editorBackHref' => route($defaultRoute),
            'editorBackTitle' => $defaultTitle,
        ];
    }

    private function normalizeReturnUrl(mixed $returnUrl): ?string
    {
        if (! is_string($returnUrl) || $returnUrl === '' || preg_match('/[\\\\\x00-\x1f]/', $returnUrl)) {
            return null;
        }

        if (str_starts_with($returnUrl, '/') && ! str_starts_with($returnUrl, '//')) {
            return url($returnUrl);
        }

        $appUrl = parse_url(url('/'));
        $candidateUrl = parse_url($returnUrl);

        if (! is_array($appUrl) || ! is_array($candidateUrl)) {
            return null;
        }

        $sameScheme = ($candidateUrl['scheme'] ?? null) === ($appUrl['scheme'] ?? null);
        $sameHost = ($candidateUrl['host'] ?? null) === ($appUrl['host'] ?? null);
        $samePort = ($candidateUrl['port'] ?? null) === ($appUrl['port'] ?? null);

        return $sameScheme && $sameHost && $samePort ? $returnUrl : null;
    }
}
