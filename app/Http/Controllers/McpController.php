<?php

namespace App\Http\Controllers;

use App\Mcp\ContentResponse;
use App\Mcp\SanitizationNotice;
use App\Models\Locale;
use App\Models\Media;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Models\Taxonomy;
use App\Services\Content\OperationReceipt;
use App\Services\Editorial\EditorialHistory;
use App\Services\Media\MediaOperations;
use App\Services\Menus\MenuOperations;
use App\Services\Pages\PageOperations;
use App\Services\Posts\PostOperations;
use App\Services\Settings\SettingOperations;
use App\Support\AdminCapabilities;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class McpController extends Controller
{
    public function capabilities(Request $request): JsonResponse
    {
        $this->authorizeCmsEditor($request);

        return response()->json(['data' => app(AdminCapabilities::class)->discovery()]);
    }

    public function pagesList(Request $request): JsonResponse
    {
        $this->authorizeCmsEditor($request);

        $validated = Validator::make($request->all(), [
            'fields' => ['sometimes', 'array', 'max:30'],
            'fields.*' => ['string', 'max:100'],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(config('cms.statuses'))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ])->validate();

        $query = Page::query()->latest()->orderByDesc('id');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['search'])) {
            $query->where('title', 'like', '%'.$validated['search'].'%');
        }

        $pages = $query->paginate($validated['per_page'] ?? config('cms.mcp.per_page', 20), ['*'], 'page', $validated['page'] ?? 1);

        return response()->json([
            'data' => $pages->getCollection()->map(fn (Page $page) => $this->project($this->serializePage($page), $request))->values(),
            'meta' => [
                'total' => $pages->total(),
                'current_page' => $pages->currentPage(),
                'last_page' => $pages->lastPage(),
                'per_page' => $pages->perPage(),
            ],
        ]);
    }

    public function pagesGet(Request $request, Page $page): JsonResponse
    {
        $this->authorizeCmsEditor($request);

        return response()->json([
            'data' => $this->project($this->serializePage($page), $request),
        ]);
    }

    public function pagesCreateDraft(Request $request): JsonResponse
    {
        $page = app(OperationReceipt::class)->run($request->user(), $request->input('operation_key'), 'pages_create_draft', $request->all(), fn () => app(PageOperations::class)->save($request->user(), $request->all(), draftOnly: true));

        return response()->json(['data' => $this->serializePage($page)] + $this->sanitizationWarnings($request, $page), 201);
    }

    public function pagesUpdateDraft(Request $request, Page $page): JsonResponse
    {
        Validator::make($request->all(), ['expected_revision' => ['nullable', 'string', 'size:64', 'regex:/^[a-f0-9]+$/']])->validate();
        $page = app(PageOperations::class)->save($request->user(), $request->all(), $page, draftOnly: true);

        return response()->json(['data' => $this->serializePage($page)] + $this->sanitizationWarnings($request, $page));
    }

    public function pagesPublish(Request $request, Page $page): JsonResponse
    {
        Validator::make($request->all(), ['expected_revision' => ['nullable', 'string', 'size:64', 'regex:/^[a-f0-9]+$/']])->validate();
        $page = app(PageOperations::class)->publish($request->user(), $page, $request->input('expected_revision'));

        return response()->json(['data' => $this->serializePage($page)]);
    }

    /** Live Editor parity: patch a draft or, for administrators, published content in place. */
    public function pagesUpdate(Request $request, Page $page): JsonResponse
    {
        Validator::make($request->all(), ['expected_revision' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/']])->validate();
        $page = app(PageOperations::class)->save($request->user(), $request->all(), $page, patch: true);

        return response()->json(['data' => $this->serializePage($page)] + $this->sanitizationWarnings($request, $page));
    }

    public function pagesDelete(Request $request, Page $page): JsonResponse
    {
        Validator::make($request->all(), ['expected_revision' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/']])->validate();
        $deleted = ['type' => 'pages', 'id' => $page->id, 'title' => $page->title, 'locale' => $page->locale, 'url' => $page->url()];
        $warnings = app(PageOperations::class)->delete($request->user(), $page, $request->input('expected_revision'));

        return response()->json(['data' => ['deleted' => $deleted]] + ($warnings === [] ? [] : ['warnings' => $warnings]));
    }

    public function postsList(Request $request): JsonResponse
    {
        $this->authorizeForUser($request->user(), 'viewAny', Post::class);

        $validated = Validator::make($request->all(), [
            'fields' => ['sometimes', 'array', 'max:30'],
            'fields.*' => ['string', 'max:100'],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(config('cms.statuses'))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ])->validate();

        $query = Post::query()->latest()->orderByDesc('id');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['search'])) {
            $query->where(function ($q) use ($validated) {
                $q->where('title', 'like', '%'.$validated['search'].'%')
                    ->orWhere('slug', 'like', '%'.$validated['search'].'%');
            });
        }

        $posts = $query->paginate($validated['per_page'] ?? config('cms.mcp.per_page', 20), ['*'], 'page', $validated['page'] ?? 1);

        return response()->json([
            'data' => $posts->getCollection()->map(fn (Post $post) => $this->project($this->serializePost($post), $request))->values(),
            'meta' => [
                'total' => $posts->total(),
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
            ],
        ]);
    }

    public function postsGet(Request $request, Post $post): JsonResponse
    {
        $this->authorizeForUser($request->user(), 'view', $post);

        return response()->json([
            'data' => $this->project($this->serializePost($post), $request),
        ]);
    }

    public function postsCreateDraft(Request $request): JsonResponse
    {
        $post = app(OperationReceipt::class)->run($request->user(), $request->input('operation_key'), 'posts_create_draft', $request->all(), fn () => app(PostOperations::class)->save($request->user(), $request->all(), draftOnly: true));

        return response()->json(['data' => $this->serializePost($post)] + $this->sanitizationWarnings($request, $post), 201);
    }

    public function postsUpdateDraft(Request $request, Post $post): JsonResponse
    {
        Validator::make($request->all(), ['expected_revision' => ['nullable', 'string', 'size:64', 'regex:/^[a-f0-9]+$/']])->validate();
        $post = app(PostOperations::class)->save($request->user(), $request->all(), $post, draftOnly: true);

        return response()->json(['data' => $this->serializePost($post)] + $this->sanitizationWarnings($request, $post));
    }

    public function postsPublish(Request $request, Post $post): JsonResponse
    {
        Validator::make($request->all(), ['expected_revision' => ['nullable', 'string', 'size:64', 'regex:/^[a-f0-9]+$/']])->validate();
        $post = app(PostOperations::class)->publish($request->user(), $post, $request->input('expected_revision'));

        return response()->json(['data' => $this->serializePost($post)]);
    }

    /** Live Editor parity; see pagesUpdate(). */
    public function postsUpdate(Request $request, Post $post): JsonResponse
    {
        Validator::make($request->all(), ['expected_revision' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/']])->validate();
        $post = app(PostOperations::class)->save($request->user(), $request->all(), $post, patch: true);

        return response()->json(['data' => $this->serializePost($post)] + $this->sanitizationWarnings($request, $post));
    }

    public function postsDelete(Request $request, Post $post): JsonResponse
    {
        Validator::make($request->all(), ['expected_revision' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/']])->validate();
        $deleted = ['type' => 'posts', 'id' => $post->id, 'title' => $post->title, 'locale' => $post->locale, 'url' => $post->url()];
        $warnings = app(PostOperations::class)->delete($request->user(), $post, $request->input('expected_revision'));

        return response()->json(['data' => ['deleted' => $deleted]] + ($warnings === [] ? [] : ['warnings' => $warnings]));
    }

    public function mediaList(Request $request): JsonResponse
    {
        $this->authorizeCmsEditor($request);

        $validated = Validator::make($request->all(), [
            'fields' => ['sometimes', 'array', 'max:30'],
            'fields.*' => ['string', 'max:100'],
            'search' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', Rule::in(['image', 'application'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'locale' => ['sometimes', 'string', Rule::in($this->mediaLocales())],
        ])->validate();

        $query = Media::query()->with('translations')->latest()->orderByDesc('id');

        if (! empty($validated['type'])) {
            $query->where('mime_type', 'like', $validated['type'].'/%');
        }

        if (! empty($validated['search'])) {
            $query->where(function ($q) use ($validated) {
                $q->where('original_filename', 'like', '%'.$validated['search'].'%')
                    ->orWhere('title', 'like', '%'.$validated['search'].'%')
                    ->orWhere('alt', 'like', '%'.$validated['search'].'%')
                    ->orWhereHas('translations', fn ($translations) => $translations
                        ->where('title', 'like', '%'.$validated['search'].'%')
                        ->orWhere('alt', 'like', '%'.$validated['search'].'%'));
            });
        }

        $media = $query->paginate($validated['per_page'] ?? config('cms.mcp.per_page', 20), ['*'], 'page', $validated['page'] ?? 1);

        return response()->json([
            'data' => $media->getCollection()->map(fn (Media $item) => $this->project($this->serializeMedia($item, $validated['locale'] ?? null), $request))->values(),
            'meta' => [
                'total' => $media->total(),
                'current_page' => $media->currentPage(),
                'last_page' => $media->lastPage(),
                'per_page' => $media->perPage(),
            ],
        ]);
    }

    public function mediaGet(Request $request, Media $media): JsonResponse
    {
        $this->authorizeCmsEditor($request);

        $validated = Validator::make($request->all(), [
            'locale' => ['sometimes', 'string', Rule::in($this->mediaLocales())],
        ])->validate();

        return response()->json([
            'data' => $this->serializeMedia($media->load('translations'), $validated['locale'] ?? null),
        ]);
    }

    public function mediaAttachMetadata(Request $request, Media $media): JsonResponse
    {
        Validator::make($request->all(), ['expected_revision' => ['nullable', 'string', 'size:64', 'regex:/^[a-f0-9]+$/']])->validate();
        $media = app(MediaOperations::class)->update($request->user(), $media, $request->all(), $request->input('expected_revision'));

        return response()->json(['data' => $this->serializeMedia($media->load('translations'), $request->input('locale'))]);
    }

    protected function mediaLocales(): array
    {
        return array_values(array_unique([Locale::baseCode(), ...Locale::where('is_active', true)->pluck('code')->all()]));
    }

    public function settingsListGrouped(Request $request): JsonResponse
    {
        $this->authorizeCmsEditor($request);

        $groups = Setting::allGrouped()->map(function ($settings) {
            return $settings->map(fn (Setting $setting) => $this->serializeSetting($setting))->values();
        });

        return response()->json([
            'data' => $groups,
        ]);
    }

    public function settingsGet(Request $request, string $key): JsonResponse
    {
        $this->authorizeCmsEditor($request);

        $setting = Setting::query()->where('key', $key)->first();

        if (! $setting) {
            return response()->json(['message' => 'Setting not found.'], 404);
        }

        return response()->json([
            'data' => $this->serializeSetting($setting),
        ]);
    }

    public function settingsUpdate(Request $request, string $key): JsonResponse
    {
        if (! $request->user()->isAdmin()) {
            throw new AuthorizationException('Only admins can update settings through MCP.');
        }
        // Existence first: an unknown key is reported as unknown, not as a protected one.
        if (! Setting::where('key', $key)->exists()) {
            return response()->json(['message' => 'Setting not found.'], 404);
        }
        if (! in_array($key, config('cms.mcp.settings_writable_keys', []), true)) {
            return response()->json(['message' => 'Setting key is read-only in MCP.'], 422);
        }
        $validated = Validator::make($request->all(), ['value' => ['present'], 'locale' => ['nullable', 'string'], 'expected_revision' => ['nullable', 'string', 'size:64']])->validate();
        app(SettingOperations::class)->update($request->user(), [$key => $validated['value']], $validated['locale'] ?? null, isset($validated['expected_revision']) ? [$key => $validated['expected_revision']] : []);

        return response()->json(['data' => $this->serializeSetting(Setting::where('key', $key)->firstOrFail())]);
    }

    public function settingsReset(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), ['keys' => ['required', 'array', 'max:50'], 'keys.*' => ['string'], 'locale' => ['nullable', 'string'], 'expected_revisions' => ['sometimes', 'array'], 'expected_revisions.*' => ['string', 'size:64']])->validate();
        app(SettingOperations::class)->reset($request->user(), $data['keys'], $data['locale'] ?? null, $data['expected_revisions'] ?? []);

        return response()->json(['data' => Setting::whereIn('key', $data['keys'])->get()->map(fn ($setting) => $this->serializeSetting($setting))->values()]);
    }

    public function settingsUpdateBatch(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), ['settings' => ['required', 'array', 'max:50'], 'locale' => ['nullable', 'string'], 'expected_revisions' => ['sometimes', 'array'], 'expected_revisions.*' => ['string', 'size:64']])->validate();
        app(SettingOperations::class)->update($request->user(), $data['settings'], $data['locale'] ?? null, $data['expected_revisions'] ?? []);

        return response()->json(['data' => Setting::whereIn('key', array_keys($data['settings']))->get()->map(fn ($setting) => $this->serializeSetting($setting))->values()]);
    }

    // -------------------------------------------------------------------------
    // Menus
    // -------------------------------------------------------------------------

    public function menusList(Request $request): JsonResponse
    {
        $this->authorizeForUser($request->user(), 'viewAny', Menu::class);

        $validated = Validator::make($request->all(), ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']])->validate();
        $menus = Menu::withCount('items')->latest()->orderByDesc('id')->paginate($validated['per_page'] ?? config('cms.mcp.per_page', 20), ['*'], 'page', $validated['page'] ?? 1);

        return response()->json([
            'data' => $menus->getCollection()->map(fn (Menu $menu) => $this->serializeMenu($menu))->values(),
            'meta' => ['total' => $menus->total(), 'current_page' => $menus->currentPage(), 'last_page' => $menus->lastPage(), 'per_page' => $menus->perPage()],
        ]);
    }

    public function menusGet(Request $request, Menu $menu): JsonResponse
    {
        $this->authorizeForUser($request->user(), 'view', $menu);

        return response()->json([
            'data' => $this->serializeMenu($menu, withTree: true),
        ]);
    }

    public function menusCreate(Request $request): JsonResponse
    {
        $menu = app(MenuOperations::class)->create($request->user(), $request->all());

        return response()->json(['data' => $this->serializeMenu($menu)], 201);
    }

    public function menusUpdate(Request $request, Menu $menu): JsonResponse
    {
        $menu = app(MenuOperations::class)->update($request->user(), $menu, $request->all());

        return response()->json(['data' => $this->serializeMenu($menu)]);
    }

    public function menusSyncItems(Request $request, Menu $menu): JsonResponse
    {
        $menu = app(MenuOperations::class)->syncItems($request->user(), $menu, $request->all());

        return response()->json(['data' => $this->serializeMenu($menu, withTree: true)]);
    }

    public function menusDelete(Request $request, Menu $menu): JsonResponse
    {
        $deleted = ['type' => 'menus', 'id' => $menu->id, 'name' => $menu->name, 'locale' => $menu->locale, 'location' => $menu->location];
        app(MenuOperations::class)->delete($request->user(), $menu, $request->all());

        return response()->json(['data' => ['deleted' => $deleted]]);
    }

    protected function project(array $data, Request $request): array
    {
        if (! $request->has('fields')) {
            return $data;
        }
        $fields = Validator::make($request->all(), ['fields' => ['required', 'array', 'max:30'], 'fields.*' => ['string', Rule::in(array_keys($data))]])->validate()['fields'];

        return array_intersect_key($data, array_flip([...$fields, 'id', 'revision', 'truncated_fields', 'recovery']));
    }

    protected function authorizeCmsEditor(Request $request): void
    {
        if (! in_array($request->user()->role, config('cms.roles', []), true)) {
            throw new AuthorizationException('Unauthorized role for MCP operation.');
        }
    }

    private function sanitizationWarnings(Request $request, Page|Post $entity): array
    {
        $warnings = $entity instanceof Page
            ? SanitizationNotice::forContent($request->input('content_json'), $entity->template_key)
            : SanitizationNotice::forPost($request->input('content'));

        return $warnings === [] ? [] : ['warnings' => $warnings];
    }

    protected function serializePage(Page $page): array
    {
        return ContentResponse::bounded([
            'id' => $page->id,
            'revision' => app(EditorialHistory::class)->fingerprint($page),
            'translation_status' => $page->translation_status,
            'stale_fields' => $page->staleTranslatableFields(),
            'title' => $page->title,
            'locale' => $page->locale,
            'translation_group_id' => $page->translation_group_id,
            'slug' => $page->slug,
            'url' => $page->url(),
            'template_key' => $page->template_key,
            'status' => $page->status,
            'published_at' => $page->published_at?->toISOString(),
            'content_json' => $page->content_json,
            'featured_image' => $page->featuredImage() ? $this->serializeMedia($page->featuredImage()) : null,
            'created_at' => $page->created_at?->toISOString(),
            'updated_at' => $page->updated_at?->toISOString(),
        ]);
    }

    protected function serializePost(Post $post): array
    {
        return ContentResponse::bounded([
            'id' => $post->id,
            'revision' => app(EditorialHistory::class)->fingerprint($post),
            'translation_status' => $post->translation_status,
            'stale_fields' => $post->staleTranslatableFields(),
            'categories' => $post->taxonomies()->where('type', 'category')->pluck('taxonomies.id')->all(),
            'title' => $post->title,
            'locale' => $post->locale,
            'translation_group_id' => $post->translation_group_id,
            'slug' => $post->slug,
            'url' => $post->url(),
            'excerpt' => $post->excerpt,
            'content' => $post->content,
            'meta_json' => $post->meta_json,
            'status' => $post->status,
            'published_at' => $post->published_at?->toISOString(),
            'featured_image' => $post->featuredImage() ? $this->serializeMedia($post->featuredImage()) : null,
            'created_at' => $post->created_at?->toISOString(),
            'updated_at' => $post->updated_at?->toISOString(),
        ]);
    }

    protected function serializeMedia(Media $media, ?string $locale = null): array
    {
        $locale ??= Locale::baseCode();

        return [
            'id' => $media->id,
            'revision' => app(MediaOperations::class)->fingerprint($media),
            'filename' => $media->filename,
            'original_filename' => $media->original_filename,
            'mime_type' => $media->mime_type,
            'extension' => $media->extension,
            'size' => $media->size,
            'formatted_size' => $media->formatted_size,
            'is_image' => $media->is_image,
            'alt' => $media->localizedAlt($locale),
            'title' => $media->localizedTitle($locale),
            'metadata' => $media->metadata,
            'disk' => $media->disk,
            'path' => $media->path,
            'url' => $media->url,
            'created_at' => $media->created_at?->toISOString(),
            'updated_at' => $media->updated_at?->toISOString(),
        ];
    }

    protected function serializeSetting(Setting $setting): array
    {
        return [
            'key' => $setting->key,
            'revision' => app(SettingOperations::class)->fingerprint($setting),
            'writable' => in_array($setting->key, config('cms.mcp.settings_writable_keys', []), true),
            'value' => $setting->type === 'password' ? null : Setting::get($setting->key),
            'redacted' => $setting->type === 'password',
            'type' => $setting->type,
            'group' => $setting->group,
        ];
    }

    protected function serializeMenu(Menu $menu, bool $withTree = false): array
    {
        $data = [
            'id' => $menu->id,
            'revision' => app(MenuOperations::class)->fingerprint($menu),
            'name' => $menu->name,
            'slug' => $menu->slug,
            'locale' => $menu->locale,
            'location' => $menu->location,
            'items_count' => $menu->items_count ?? $menu->items()->count(),
            'created_at' => $menu->created_at?->toISOString(),
            'updated_at' => $menu->updated_at?->toISOString(),
        ];

        if ($withTree) {
            $data['items'] = $this->serializeMenuTree($menu->tree());
        }

        return $data;
    }

    protected function serializeMenuTree(Collection $items): array
    {
        return $items->map(fn (MenuItem $item) => [
            'id' => $item->id,
            'label' => $item->label,
            'type' => $item->type,
            'url' => $item->resolveUrl(),
            'raw_url' => $item->url,
            'linkable_id' => $item->linkable_id,
            'linkable_type' => $item->linkable_type,
            'target' => $item->target,
            'order' => $item->order,
            'children' => $this->serializeMenuTree($item->children ?? collect()),
        ])->values()->all();
    }

    protected function resolveLinkableType(string $type): ?string
    {
        return match ($type) {
            'page' => Page::class,
            'post' => Post::class,
            'taxonomy' => Taxonomy::class,
            default => null,
        };
    }
}
