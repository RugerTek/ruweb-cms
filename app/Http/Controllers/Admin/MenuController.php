<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMenuRequest;
use App\Http\Requests\Admin\SyncMenuItemsRequest;
use App\Http\Requests\Admin\UpdateMenuRequest;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Post;
use App\Models\Taxonomy;
use App\Models\Locale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MenuController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Menu::class);

        $locales = Locale::where('is_active', true)->orderBy('sort_order')->get();
        $baseLocale = Locale::baseCode();
        $menus = Menu::with('items')->withCount('items')->latest()->get();
        $menuGroups = $menus
            ->groupBy('slug')
            ->map(function ($group) use ($baseLocale) {
                $primary = $group->firstWhere('locale', $baseLocale) ?: $group->first();

                return [
                    'primary' => $primary,
                    'translations' => $group->keyBy('locale'),
                    'items_count' => $primary?->items_count ?? 0,
                ];
            })
            ->sortBy(fn (array $group): string => $group['primary']->name)
            ->values();

        return view('admin.menus.index', [
            'menuGroups' => $menuGroups,
            'locales' => $locales,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Menu::class);

        return view('admin.menus.create', [
            'locales' => Locale::where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }

    public function store(StoreMenuRequest $request): RedirectResponse
    {
        $this->authorize('create', Menu::class);

        $data = $request->validated();
        $data['locale'] = $data['locale'] ?? Locale::baseCode();
        $menu = Menu::create($data);

        return redirect()->route('admin.menus.edit', $menu)
            ->with('success', __('admin.menu_created'));
    }

    public function edit(Menu $menu): View
    {
        $this->authorize('update', $menu);

        return view('admin.menus.edit', [
            'menu'       => $menu,
            'menuItems'  => $menu->tree(),
            'pages'      => Page::published()->where('locale', $menu->locale)->orderBy('title')->get(['id', 'title', 'slug']),
            'posts'      => Post::published()->where('locale', $menu->locale)->orderBy('title')->get(['id', 'title', 'slug']),
            'taxonomies' => Taxonomy::where('locale', $menu->locale)->ordered()->get(['id', 'name', 'slug', 'type']),
            'locales'    => Locale::where('is_active', true)->orderBy('sort_order')->get(),
            'menuTranslations' => Menu::where('slug', $menu->slug)->get()->keyBy('locale'),
        ]);
    }

    public function update(UpdateMenuRequest $request, Menu $menu): RedirectResponse
    {
        $this->authorize('update', $menu);

        $data = $request->validated();
        $data['locale'] = $data['locale'] ?? $menu->locale;
        $menu->update($data);

        return redirect()->route('admin.menus.edit', $menu)
            ->with('success', __('admin.menu_updated'));
    }

    public function syncItems(SyncMenuItemsRequest $request, Menu $menu): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $menu);

        DB::transaction(function () use ($request, $menu) {
            $menu->items()->delete();

            $items = $request->validated()['items'];
            $idMap = []; // maps temp client IDs to real DB IDs

            foreach ($items as $itemData) {
                $menuItem = MenuItem::create([
                    'menu_id'       => $menu->id,
                    'parent_id'     => null, // resolved in second pass
                    'label'         => $itemData['label'],
                    'type'          => $itemData['type'],
                    'linkable_type' => $this->resolveLinkableType($itemData['type']),
                    'linkable_id'   => $itemData['type'] !== 'custom_link' ? ($itemData['linkable_id'] ?? null) : null,
                    'url'           => $itemData['type'] === 'custom_link' ? \App\Support\SiteUrl::toRelative($itemData['url'] ?? null) : null,
                    'target'        => $itemData['target'],
                    'translation_status' => ($itemData['type'] !== 'custom_link' && empty($itemData['linkable_id'])) ? 'needs_review' : null,
                    'order'         => $itemData['order'],
                ]);

                if (isset($itemData['id'])) {
                    $idMap[$itemData['id']] = $menuItem->id;
                }
            }

            // Second pass: resolve parent_id references
            foreach ($items as $itemData) {
                if (!empty($itemData['parent_id']) && isset($idMap[$itemData['parent_id']]) && isset($itemData['id']) && isset($idMap[$itemData['id']])) {
                    MenuItem::where('id', $idMap[$itemData['id']])
                        ->update(['parent_id' => $idMap[$itemData['parent_id']]]);
                }
            }
        });

        if ($request->wantsJson()) {
            return response()->json(['saved' => true]);
        }

        return redirect()->route('admin.menus.edit', $menu)
            ->with('success', __('admin.menu_items_saved'));
    }

    public function translate(Menu $menu, string $locale): RedirectResponse
    {
        $this->authorize('create', Menu::class);
        abort_unless(Locale::where('code', $locale)->where('is_active', true)->exists(), 404);

        $existing = Menu::where('slug', $menu->slug)->where('locale', $locale)->first();
        if ($existing) {
            return redirect()->route('admin.menus.edit', $existing);
        }

        $base = Menu::where('slug', $menu->slug)
            ->where('locale', Locale::baseCode())
            ->first() ?: $menu;

        $translation = DB::transaction(function () use ($base, $locale): Menu {
            $translation = Menu::create([
                'locale' => $locale,
                'name' => $base->name,
                'slug' => $base->slug,
                'location' => $base->location,
            ]);

            $idMap = [];
            $baseItems = $base->items()->with('linkable')->orderBy('order')->get();

            foreach ($baseItems as $item) {
                $translatedLinkableId = $this->translatedLinkableId($item, $locale);

                $copy = MenuItem::create([
                    'menu_id' => $translation->id,
                    'parent_id' => null,
                    'label' => $item->label,
                    'type' => $item->type,
                    'linkable_type' => $item->linkable_type,
                    'linkable_id' => $item->type === 'custom_link' ? null : $translatedLinkableId,
                    'url' => $item->type === 'custom_link' ? $item->url : null,
                    'target' => $item->target,
                    'translation_status' => 'needs_review',
                    'order' => $item->order,
                ]);

                $idMap[$item->id] = $copy->id;
            }

            foreach ($baseItems as $item) {
                if ($item->parent_id && isset($idMap[$item->id], $idMap[$item->parent_id])) {
                    MenuItem::where('id', $idMap[$item->id])
                        ->update(['parent_id' => $idMap[$item->parent_id]]);
                }
            }

            return $translation;
        });

        return redirect()
            ->route('admin.menus.edit', $translation)
            ->with('success', __('admin.menu_created'));
    }

    public function destroy(Menu $menu): RedirectResponse
    {
        $this->authorize('delete', $menu);

        $menu->delete();

        return redirect()->route('admin.menus.index')
            ->with('success', __('admin.menu_deleted'));
    }

    private function resolveLinkableType(string $type): ?string
    {
        return match ($type) {
            'page'     => Page::class,
            'post'     => Post::class,
            'taxonomy' => Taxonomy::class,
            default    => null,
        };
    }

    private function translatedLinkableId(MenuItem $item, string $locale): ?int
    {
        if ($item->type === 'custom_link' || ! $item->linkable instanceof Model) {
            return null;
        }

        if (! method_exists($item->linkable, 'translations')) {
            return $item->linkable_id;
        }

        return $item->linkable
            ->translations()
            ->where('locale', $locale)
            ->value('id');
    }
}
