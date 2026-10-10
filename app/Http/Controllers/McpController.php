<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\Locale;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Models\Taxonomy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class McpController extends Controller
{
    public function pagesList(Request $request): JsonResponse
    {
        $this->authorizeCmsEditor($request);

        $validated = Validator::make($request->all(), [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(config('cms.statuses'))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ])->validate();

        $query = Page::query()->latest();

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['search'])) {
            $query->where('title', 'like', '%' . $validated['search'] . '%');
        }

        $pages = $query->paginate($validated['per_page'] ?? config('cms.mcp.per_page', 20));

        return response()->json([
            'data' => $pages->getCollection()->map(fn (Page $page) => $this->serializePage($page))->values(),
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
            'data' => $this->serializePage($page),
        ]);
    }

    public function pagesCreateDraft(Request $request): JsonResponse
    {
        $this->authorizeCmsEditor($request);

        $validated = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:pages,slug'],
            'template_key' => ['required', 'string', Rule::in(array_keys(config('cms.templates', [])))],
            'content_json' => ['nullable', 'array'],
            'content_json.meta' => ['nullable', 'array'],
            'content_json.sections' => ['nullable', 'array'],
            'featured_image' => ['nullable', 'integer', 'exists:media,id'],
        ])->validate();

        $userId = $request->user()->id;
        $featuredImageId = $validated['featured_image'] ?? null;
        unset($validated['featured_image']);

        $page = Page::create(array_merge($validated, [
            'status' => 'draft',
            'created_by' => $userId,
            'updated_by' => $userId,
        ]));

        if ($featuredImageId) {
            $page->attachMedia($featuredImageId, 'featured_image');
        }

        return response()->json([
            'data' => $this->serializePage($page->fresh()),
        ], 201);
    }

    public function pagesUpdateDraft(Request $request, Page $page): JsonResponse
    {
        $this->authorizeCmsEditor($request);

        $validated = Validator::make($request->all(), [
            'title' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', Rule::unique('pages', 'slug')->ignore($page->id)],
            'template_key' => ['sometimes', 'string', Rule::in(array_keys(config('cms.templates', [])))],
            'content_json' => ['sometimes', 'array'],
            'content_json.meta' => ['nullable', 'array'],
            'content_json.sections' => ['nullable', 'array'],
            'featured_image' => ['nullable', 'integer', 'exists:media,id'],
        ])->validate();

        $featuredImageId = $validated['featured_image'] ?? null;
        $hasFeaturedImage = array_key_exists('featured_image', $validated);
        unset($validated['featured_image']);

        $validated['status'] = 'draft';
        $validated['updated_by'] = $request->user()->id;

        $page->update($validated);

        if ($hasFeaturedImage) {
            $page->media()->wherePivot('collection', 'featured_image')->detach();
            if ($featuredImageId) {
                $page->attachMedia($featuredImageId, 'featured_image');
            }
        }

        return response()->json([
            'data' => $this->serializePage($page->fresh()),
        ]);
    }

    public function pagesPublish(Request $request, Page $page): JsonResponse
    {
        $this->authorizeCmsEditor($request);

        $page->update([
            'status' => 'published',
            'published_at' => $page->published_at ?? now(),
            'updated_by' => $request->user()->id,
        ]);

        return response()->json([
            'data' => $this->serializePage($page->fresh()),
        ]);
    }

    public function postsList(Request $request): JsonResponse
    {
        $this->authorizeForUser($request->user(), 'viewAny', Post::class);

        $validated = Validator::make($request->all(), [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(config('cms.statuses'))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ])->validate();

        $query = Post::query()->latest();

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['search'])) {
            $query->where(function ($q) use ($validated) {
                $q->where('title', 'like', '%' . $validated['search'] . '%')
                    ->orWhere('slug', 'like', '%' . $validated['search'] . '%');
            });
        }

        $posts = $query->paginate($validated['per_page'] ?? config('cms.mcp.per_page', 20));

        return response()->json([
            'data' => $posts->getCollection()->map(fn (Post $post) => $this->serializePost($post))->values(),
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
            'data' => $this->serializePost($post),
        ]);
    }

    public function postsCreateDraft(Request $request): JsonResponse
    {
        $this->authorizeForUser($request->user(), 'create', Post::class);

        $validated = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:posts,slug'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'content' => ['nullable', 'string'],
            'featured_image' => ['nullable', 'integer', 'exists:media,id'],
            'meta_json' => ['nullable', 'array'],
            'meta_json.title' => ['nullable', 'string', 'max:255'],
            'meta_json.description' => ['nullable', 'string', 'max:320'],
        ])->validate();

        $userId = $request->user()->id;
        $featuredImageId = $validated['featured_image'] ?? null;
        unset($validated['featured_image']);

        $post = Post::create(array_merge($validated, [
            'status' => 'draft',
            'created_by' => $userId,
            'updated_by' => $userId,
        ]));

        if ($featuredImageId) {
            $post->attachMedia($featuredImageId, 'featured_image');
        }

        return response()->json([
            'data' => $this->serializePost($post->fresh()),
        ], 201);
    }

    public function postsUpdateDraft(Request $request, Post $post): JsonResponse
    {
        $this->authorizeForUser($request->user(), 'update', $post);

        $validated = Validator::make($request->all(), [
            'title' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('posts', 'slug')->ignore($post->id)],
            'excerpt' => ['sometimes', 'nullable', 'string', 'max:500'],
            'content' => ['sometimes', 'nullable', 'string'],
            'featured_image' => ['nullable', 'integer', 'exists:media,id'],
            'meta_json' => ['sometimes', 'nullable', 'array'],
            'meta_json.title' => ['nullable', 'string', 'max:255'],
            'meta_json.description' => ['nullable', 'string', 'max:320'],
        ])->validate();

        $featuredImageId = $validated['featured_image'] ?? null;
        $hasFeaturedImage = array_key_exists('featured_image', $validated);
        unset($validated['featured_image']);

        $validated['status'] = 'draft';
        $validated['updated_by'] = $request->user()->id;

        $post->update($validated);

        if ($hasFeaturedImage) {
            $post->media()->wherePivot('collection', 'featured_image')->detach();
            if ($featuredImageId) {
                $post->attachMedia($featuredImageId, 'featured_image');
            }
        }

        return response()->json([
            'data' => $this->serializePost($post->fresh()),
        ]);
    }

    public function postsPublish(Request $request, Post $post): JsonResponse
    {
        $this->authorizeForUser($request->user(), 'update', $post);

        $post->update([
            'status' => 'published',
            'published_at' => $post->published_at ?? now(),
            'updated_by' => $request->user()->id,
        ]);

        return response()->json([
            'data' => $this->serializePost($post->fresh()),
        ]);
    }

    public function mediaList(Request $request): JsonResponse
    {
        $this->authorizeCmsEditor($request);

        $validated = Validator::make($request->all(), [
            'search' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', Rule::in(['image', 'application'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'locale' => ['sometimes', 'string', Rule::in($this->mediaLocales())],
        ])->validate();

        $query = Media::query()->with('translations')->latest();

        if (! empty($validated['type'])) {
            $query->where('mime_type', 'like', $validated['type'] . '/%');
        }

        if (! empty($validated['search'])) {
            $query->where(function ($q) use ($validated) {
                $q->where('original_filename', 'like', '%' . $validated['search'] . '%')
                    ->orWhere('title', 'like', '%' . $validated['search'] . '%')
                    ->orWhere('alt', 'like', '%' . $validated['search'] . '%')
                    ->orWhereHas('translations', fn ($translations) => $translations
                        ->where('title', 'like', '%'.$validated['search'].'%')
                        ->orWhere('alt', 'like', '%'.$validated['search'].'%'));
            });
        }

        $media = $query->paginate($validated['per_page'] ?? config('cms.mcp.per_page', 20));

        return response()->json([
            'data' => $media->getCollection()->map(fn (Media $item) => $this->serializeMedia($item, $validated['locale'] ?? null))->values(),
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
        $this->authorizeCmsEditor($request);

        $validated = Validator::make($request->all(), [
            'locale' => ['sometimes', 'string', Rule::in($this->mediaLocales())],
            'alt' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $locale = $validated['locale'] ?? Locale::baseCode();
        unset($validated['locale']);
        $media->updateMetadata($validated, $locale);

        return response()->json([
            'data' => $this->serializeMedia($media->fresh()->load('translations'), $locale),
        ]);
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

        $allowedKeys = config('cms.mcp.settings_writable_keys', []);

        if (! in_array($key, $allowedKeys, true)) {
            return response()->json([
                'message' => 'Setting key is read-only in MCP.',
            ], 422);
        }

        $setting = Setting::query()->where('key', $key)->first();

        if (! $setting) {
            return response()->json(['message' => 'Setting not found.'], 404);
        }

        $validated = Validator::make($request->all(), [
            'value' => ['nullable'],
        ])->validate();

        Setting::set($key, $validated['value'] ?? null);
        Setting::clearCache();

        return response()->json([
            'data' => $this->serializeSetting($setting->fresh()),
        ]);
    }

    // -------------------------------------------------------------------------
    // Menus
    // -------------------------------------------------------------------------

    public function menusList(Request $request): JsonResponse
    {
        $this->authorizeForUser($request->user(), 'viewAny', Menu::class);

        $menus = Menu::withCount('items')->latest()->get();

        return response()->json([
            'data' => $menus->map(fn (Menu $menu) => $this->serializeMenu($menu))->values(),
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
        $this->authorizeForUser($request->user(), 'create', Menu::class);

        $validated = Validator::make($request->all(), [
            'name'     => ['required', 'string', 'max:255'],
            'locale'   => ['nullable', 'string', Rule::in(Locale::installedCodes())],
            'slug'     => ['required', 'string', 'max:255', Rule::unique('menus', 'slug')->where('locale', $request->input('locale', Locale::baseCode()))],
            'location' => ['nullable', 'string', 'max:255', Rule::unique('menus', 'location')->where('locale', $request->input('locale', Locale::baseCode()))],
        ])->validate();

        $validated['locale'] = $validated['locale'] ?? Locale::baseCode();

        $menu = Menu::create($validated);

        return response()->json([
            'data' => $this->serializeMenu($menu->fresh()),
        ], 201);
    }

    public function menusUpdate(Request $request, Menu $menu): JsonResponse
    {
        $this->authorizeForUser($request->user(), 'update', $menu);

        $validated = Validator::make($request->all(), [
            'name'     => ['sometimes', 'string', 'max:255'],
            'locale'   => ['sometimes', 'string', Rule::in(Locale::installedCodes())],
            'slug'     => ['sometimes', 'string', 'max:255', Rule::unique('menus', 'slug')->where('locale', $request->input('locale', $menu->locale))->ignore($menu->id)],
            'location' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('menus', 'location')->where('locale', $request->input('locale', $menu->locale))->ignore($menu->id)],
        ])->validate();

        $menu->update($validated);

        return response()->json([
            'data' => $this->serializeMenu($menu->fresh()),
        ]);
    }

    public function menusSyncItems(Request $request, Menu $menu): JsonResponse
    {
        $this->authorizeForUser($request->user(), 'update', $menu);

        $validated = Validator::make($request->all(), [
            'items'               => ['present', 'array'],
            'items.*.id'          => ['nullable', 'integer'],
            'items.*.parent_id'   => ['nullable', 'integer'],
            'items.*.label'       => ['required', 'string', 'max:255'],
            'items.*.type'        => ['required', 'string', 'in:custom_link,page,post,taxonomy'],
            'items.*.linkable_id' => ['nullable', 'integer'],
            'items.*.url'         => ['nullable', 'string', 'max:2048'],
            'items.*.target'      => ['required', 'string', 'in:_self,_blank'],
            'items.*.order'       => ['required', 'integer', 'min:0'],
        ])->validate();

        \Illuminate\Support\Facades\DB::transaction(function () use ($validated, $menu) {
            $menu->items()->delete();

            $idMap = [];

            foreach ($validated['items'] as $itemData) {
                $menuItem = MenuItem::create([
                    'menu_id'       => $menu->id,
                    'parent_id'     => null,
                    'label'         => $itemData['label'],
                    'type'          => $itemData['type'],
                    'linkable_type' => $this->resolveLinkableType($itemData['type']),
                    'linkable_id'   => $itemData['type'] !== 'custom_link' ? ($itemData['linkable_id'] ?? null) : null,
                    'url'           => $itemData['type'] === 'custom_link' ? \App\Support\SiteUrl::toRelative($itemData['url'] ?? null) : null,
                    'target'        => $itemData['target'],
                    'order'         => $itemData['order'],
                ]);

                if (isset($itemData['id'])) {
                    $idMap[$itemData['id']] = $menuItem->id;
                }
            }

            foreach ($validated['items'] as $itemData) {
                if (
                    ! empty($itemData['parent_id']) &&
                    isset($idMap[$itemData['parent_id']]) &&
                    isset($itemData['id']) &&
                    isset($idMap[$itemData['id']])
                ) {
                    MenuItem::where('id', $idMap[$itemData['id']])
                        ->update(['parent_id' => $idMap[$itemData['parent_id']]]);
                }
            }
        });

        return response()->json([
            'data' => $this->serializeMenu($menu->fresh(), withTree: true),
        ]);
    }

    public function menusDelete(Request $request, Menu $menu): JsonResponse
    {
        $this->authorizeForUser($request->user(), 'delete', $menu);

        $menu->delete();

        return response()->json(null, 204);
    }

    protected function authorizeCmsEditor(Request $request): void
    {
        if (! in_array($request->user()->role, config('cms.roles', []), true)) {
            throw new AuthorizationException('Unauthorized role for MCP operation.');
        }
    }

    protected function serializePage(Page $page): array
    {
        return [
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'url' => $page->url(),
            'template_key' => $page->template_key,
            'status' => $page->status,
            'published_at' => $page->published_at?->toISOString(),
            'content_json' => $page->content_json,
            'featured_image' => $page->featuredImage() ? $this->serializeMedia($page->featuredImage()) : null,
            'created_at' => $page->created_at?->toISOString(),
            'updated_at' => $page->updated_at?->toISOString(),
        ];
    }

    protected function serializePost(Post $post): array
    {
        return [
            'id' => $post->id,
            'title' => $post->title,
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
        ];
    }

    protected function serializeMedia(Media $media, ?string $locale = null): array
    {
        $locale ??= Locale::baseCode();
        return [
            'id' => $media->id,
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
            'value' => Setting::get($setting->key),
            'type' => $setting->type,
            'group' => $setting->group,
        ];
    }

    protected function serializeMenu(Menu $menu, bool $withTree = false): array
    {
        $data = [
            'id'         => $menu->id,
            'name'       => $menu->name,
            'slug'       => $menu->slug,
            'locale'     => $menu->locale,
            'location'   => $menu->location,
            'items_count' => $menu->items_count ?? $menu->items()->count(),
            'created_at' => $menu->created_at?->toISOString(),
            'updated_at' => $menu->updated_at?->toISOString(),
        ];

        if ($withTree) {
            $data['items'] = $this->serializeMenuTree($menu->tree());
        }

        return $data;
    }

    protected function serializeMenuTree(\Illuminate\Support\Collection $items): array
    {
        return $items->map(fn (MenuItem $item) => [
            'id'           => $item->id,
            'label'        => $item->label,
            'type'         => $item->type,
            'url'          => $item->resolveUrl(),
            'raw_url'      => $item->url,
            'linkable_id'  => $item->linkable_id,
            'linkable_type' => $item->linkable_type,
            'target'       => $item->target,
            'order'        => $item->order,
            'children'     => $this->serializeMenuTree($item->children ?? collect()),
        ])->values()->all();
    }

    protected function resolveLinkableType(string $type): ?string
    {
        return match ($type) {
            'page'     => Page::class,
            'post'     => Post::class,
            'taxonomy' => Taxonomy::class,
            default    => null,
        };
    }
}
