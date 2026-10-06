<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePostRequest;
use App\Http\Requests\Admin\UpdatePostRequest;
use App\Models\Locale;
use App\Models\Post;
use App\Models\Taxonomy;
use App\Services\Content\ContentSchemaRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class PostController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Post::class);

        $baseLocale = Locale::baseCode();
        $query = Post::query()->where('locale', $baseLocale)->with('translations')->latest();

        if ($request->filled('status') && in_array($request->input('status'), config('cms.statuses'))) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        return view('admin.posts.index', [
            'posts' => $query->paginate(15)->withQueryString(),
            'totalCount' => Post::where('locale', $baseLocale)->count(),
            'publishedCount' => Post::where('locale', $baseLocale)->where('status', 'published')->count(),
            'draftCount' => Post::where('locale', $baseLocale)->where('status', 'draft')->count(),
            'currentStatus' => $request->input('status'),
            'locales' => Locale::where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Post::class);

        return view('admin.posts.create', [
            'categories' => Taxonomy::ofType('category')->where('locale', Locale::baseCode())->ordered()->get(),
        ] + $this->resolveEditorBackLink($request, 'admin.posts.index', __('admin.back_to_posts')));
    }

    public function store(StorePostRequest $request): RedirectResponse
    {
        $this->authorize('create', Post::class);

        $data = $request->validated();
        $data['locale'] = $data['locale'] ?? Locale::baseCode();
        $data['created_by'] = $request->user()->id;
        $data['updated_by'] = $request->user()->id;

        if ($data['status'] === 'published' && empty($data['published_at'])) {
            $data['published_at'] = now();
        }

        $featuredImage = $data['featured_image'] ?? null;
        $categories = $data['categories'] ?? [];
        unset($data['featured_image'], $data['categories'], $data['acknowledged_fields']);

        $post = Post::create($data);
        $post->source_fingerprint = $post->translatableFingerprint();
        $post->source_field_hashes = $post->translatableFieldFingerprints();
        $post->save();

        if ($featuredImage) {
            $post->attachMedia($featuredImage, 'featured_image');
        }

        $post->syncTaxonomies($categories, 'category');

        return redirect()
            ->route('admin.posts.edit', ['post' => $post, 'return' => $this->normalizeReturnUrl($request->input('return', $request->query('return')))])
            ->with('success', __('admin.post_created'));
    }

    public function edit(Request $request, Post $post): View
    {
        $this->authorize('update', $post);

        return view('admin.posts.edit', [
            'editorLease' => $request->attributes->get('editorLease'),
            'post' => $post,
            'categories' => Taxonomy::ofType('category')->where('locale', $post->locale)->ordered()->get(),
            'locales' => Locale::where('is_active', true)->orderBy('sort_order')->get(),
            'staleFieldNames' => app(ContentSchemaRegistry::class)->formNamesFor($post, $post->staleTranslatableFields()),
        ] + $this->resolveEditorBackLink($request, 'admin.posts.index', __('admin.back_to_posts')));
    }

    public function update(UpdatePostRequest $request, Post $post): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $post);

        $data = $request->validated();
        $data['locale'] = $data['locale'] ?? $post->locale;
        $data['updated_by'] = $request->user()->id;

        if ($data['status'] === 'published' && ! $post->published_at) {
            $data['published_at'] = now();
        }

        $featuredImage = $data['featured_image'] ?? null;
        $categories = $data['categories'] ?? [];
        unset($data['featured_image'], $data['categories']);

        $post->update($data);
        if ($post->isBaseLocale()) {
            $post->source_fingerprint = $post->translatableFingerprint();
            $post->source_field_hashes = $post->translatableFieldFingerprints();
        } else {
            $post->syncTranslationFromBase();
        }
        $post->save();

        $post->media()->wherePivot('collection', 'featured_image')->detach();
        if ($featuredImage) {
            $post->attachMedia($featuredImage, 'featured_image');
        }

        $post->syncTaxonomies($categories, 'category');

        if ($request->wantsJson()) {
            return response()->json(['saved' => true]);
        }

        return redirect()
            ->route('admin.posts.edit', ['post' => $post, 'return' => $this->normalizeReturnUrl($request->input('return', $request->query('return')))])
            ->with('success', __('admin.post_updated'));
    }

    public function previewRender(Request $request, Post $post): Response
    {
        $post->title = $request->input('title', $post->title);
        $post->excerpt = $request->input('excerpt', $post->excerpt);
        $post->content = $request->input('content', $post->content);
        app()->setLocale($post->locale);

        $html = view($post->previewView(), $post->previewData() + ['isEditorPreview' => true])->render();

        return response($html)->header('Content-Type', 'text/html');
    }

    public function destroy(Post $post): RedirectResponse
    {
        $this->authorize('delete', $post);

        $post->media()->detach();
        $post->delete();

        return redirect()
            ->route('admin.posts.index')
            ->with('success', __('admin.post_deleted'));
    }

    public function translate(Request $request, Post $post, string $locale): RedirectResponse
    {
        $this->authorize('create', Post::class);
        abort_unless(Locale::where('code', $locale)->where('is_active', true)->exists(), 404);

        $existing = $post->translations()->where('locale', $locale)->first();
        if ($existing) {
            return redirect()->route('admin.posts.edit', ['post' => $existing, 'return' => $this->normalizeReturnUrl($request->input('return'))]);
        }

        $base = $post->baseTranslation() ?: $post;
        $translation = $base->replicate(['slug', 'published_at']);
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

        $translation->syncTaxonomies($this->translatedTaxonomyIds($base, 'category', $locale), 'category');

        return redirect()
            ->route('admin.posts.edit', ['post' => $translation, 'return' => $this->normalizeReturnUrl($request->input('return'))])
            ->with('success', __('admin.post_created'));
    }

    private function uniqueTranslatedSlug(string $slug, string $locale): string
    {
        $candidate = $slug;
        $counter = 2;

        while (Post::where('locale', $locale)->where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.$counter;
            $counter++;
        }

        return $candidate;
    }

    private function translatedTaxonomyIds(Post $base, string $type, string $locale): array
    {
        return $base->taxonomiesByType($type)
            ->with('translations')
            ->get()
            ->map(fn (Taxonomy $taxonomy) => $taxonomy->translations->firstWhere('locale', $locale)?->id)
            ->filter()
            ->values()
            ->all();
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
