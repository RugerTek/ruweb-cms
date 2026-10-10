<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePostRequest;
use App\Http\Requests\Admin\UpdatePostRequest;
use App\Models\Locale;
use App\Models\Post;
use App\Models\Taxonomy;
use App\Services\Content\ContentSchemaRegistry;
use App\Services\Editorial\EditorialHistory;
use App\Services\Posts\PostOperations;
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

        $post = app(PostOperations::class)->save($request->user(), $request->validated());

        return redirect()
            ->route('admin.posts.edit', ['post' => $post, 'return' => $this->normalizeReturnUrl($request->input('return', $request->query('return')))])
            ->with('success', __('admin.post_created'));
    }

    public function edit(Request $request, Post $post): View
    {
        app(EditorialHistory::class)->capture($post);
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

        $staged = $request->user()->isEditor() && $post->status === 'published';
        $post = app(PostOperations::class)->save($request->user(), $request->validated(), $post, base: $request->input('_editor_base'));
        if ($staged) {
            if ($request->wantsJson()) {
                return response()->json(['saved' => true, 'staged' => true, 'fingerprint' => app(EditorialHistory::class)->fingerprint($post)]);
            }

            return back()->with('success', __('editorial.staged'));
        }

        if ($request->wantsJson()) {
            return response()->json(['saved' => true, 'fingerprint' => app(EditorialHistory::class)->fingerprint($post->fresh())]);
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

        app(PostOperations::class)->delete(request()->user(), $post);

        return redirect()
            ->route('admin.posts.index')
            ->with('success', __('admin.post_deleted'));
    }

    public function translate(Request $request, Post $post, string $locale): RedirectResponse
    {
        $this->authorize('create', Post::class);
        $translation = app(PostOperations::class)->translate($request->user(), $post, $locale);

        return redirect()
            ->route('admin.posts.edit', ['post' => $translation, 'return' => $this->normalizeReturnUrl($request->input('return'))])
            ->with('success', __('admin.post_created'));
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
