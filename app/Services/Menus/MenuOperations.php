<?php

namespace App\Services\Menus;

use App\Exceptions\RevisionConflict;
use App\Models\Locale;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Models\Taxonomy;
use App\Models\User;
use App\Services\Content\OperationReceipt;
use App\Services\Editorial\EditorialActor;
use App\Services\Revisions\ContentRevisionLedger;
use App\Support\LinkDestination;
use App\Support\ShopModule;
use App\Support\SiteUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class MenuOperations
{
    /** Stored columns only: list counts (items_count) and timestamps must not change the revision of the same menu. */
    public function fingerprint(Menu $menu): string
    {
        $attributes = array_diff_key($menu->attributesToArray(), array_flip(['items_count', 'created_at', 'updated_at']));
        ksort($attributes);

        return hash('sha256', json_encode([$attributes, $menu->items()->orderBy('id')->get()->toArray()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function check(Menu $menu, mixed $revision): void
    {
        Validator::make(['expected_revision' => $revision], ['expected_revision' => ['nullable', 'string', 'size:64', 'regex:/^[a-f0-9]+$/']])->validate();
        if ($revision !== null) {
            $current = $this->fingerprint($menu);
            if (! hash_equals($current, $revision)) {
                throw new RevisionConflict($revision, $current);
            }
        }
    }

    /**
     * Menu item types that link to a stored model, `type => model class`. `product` exists only while the shop
     * catalog is on (docs/shop/catalog.md); switched off, the product items already in a menu are kept on save
     * (hidden in public menus) but new ones cannot be added (syncItems()).
     *
     * @return array<string, class-string<Model>>
     */
    public static function linkableTypes(): array
    {
        $types = ['page' => Page::class, 'post' => Post::class, 'taxonomy' => Taxonomy::class];
        if (app(ShopModule::class)->catalog()) {
            $types['product'] = Product::class;
        }

        return $types;
    }

    /**
     * Item types a sync request may carry: custom links, the linkable types and, always, `product`, so that saving a
     * menu with the catalog off keeps its existing product items instead of failing (syncItems() refuses new ones).
     *
     * @return list<string>
     */
    public static function syncableTypes(): array
    {
        return array_values(array_unique(['custom_link', ...array_keys(self::linkableTypes()), 'product']));
    }

    private function resolveLinkableType(string $type): ?string
    {
        return self::linkableTypes()[$type] ?? ($type === 'product' ? Product::class : null);
    }

    public function create(User $actor, array $input): Menu
    {
        Gate::forUser($actor)->authorize('create', Menu::class);

        return EditorialActor::run($actor, fn () => app(OperationReceipt::class)->run($actor, $input['operation_key'] ?? null, 'menus_create', $input, function () use ($input) {

            $validated = Validator::make($input, [
                'name' => ['required', 'string', 'max:255'],
                'locale' => ['nullable', 'string', Rule::in(Locale::installedCodes())],
                'slug' => ['required', 'string', 'max:255', Rule::unique('menus', 'slug')->where('locale', ($input['locale'] ?? Locale::baseCode()))],
                'location' => ['nullable', 'string', 'max:255', Rule::unique('menus', 'location')->where('locale', ($input['locale'] ?? Locale::baseCode()))],
            ])->validate();

            $validated['locale'] = $validated['locale'] ?? Locale::baseCode();

            $menu = Menu::create($validated);

            return $menu->fresh();
        }));
    }

    public function update(User $actor, Menu $menu, array $input): Menu
    {
        Gate::forUser($actor)->authorize('update', $menu);

        return EditorialActor::run($actor, fn () => app(ContentRevisionLedger::class)->transaction(function () use ($menu, $input) {
            $menu = Menu::query()->lockForUpdate()->findOrFail($menu->id);
            $this->check($menu, $input['expected_revision'] ?? null);
            abort_if(isset($input['locale']) && $input['locale'] !== $menu->locale && $menu->items()->exists(), 422, 'A menu with items cannot change language; create a localized menu and remap its targets.');

            $validated = Validator::make($input, [
                'name' => ['sometimes', 'string', 'max:255'],
                'locale' => ['sometimes', 'string', Rule::in(Locale::installedCodes())],
                'slug' => ['sometimes', 'string', 'max:255', Rule::unique('menus', 'slug')->where('locale', ($input['locale'] ?? $menu->locale))->ignore($menu->id)],
                'location' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('menus', 'location')->where('locale', ($input['locale'] ?? $menu->locale))->ignore($menu->id)],
            ])->validate();

            $menu->update($validated);

            return $menu->fresh();
        }, 3));
    }

    public function syncItems(User $actor, Menu $menu, array $input): Menu
    {
        Gate::forUser($actor)->authorize('update', $menu);

        return EditorialActor::run($actor, fn () => app(ContentRevisionLedger::class)->transaction(function () use ($menu, $input) {
            $menu = Menu::query()->lockForUpdate()->findOrFail($menu->id);
            $this->check($menu, $input['expected_revision'] ?? null);

            $validated = Validator::make($input, [
                'items' => ['present', 'array', 'max:100'],
                'items.*.id' => ['nullable', 'integer'],
                'items.*.parent_id' => ['nullable', 'integer'],
                'items.*.label' => ['required', 'string', 'max:255'],
                'items.*.type' => ['required', 'string', Rule::in(self::syncableTypes())],
                'items.*.linkable_id' => ['nullable', 'integer'],
                // Same safe URI forms as every other custom link (no javascript:, data: or bare relative paths).
                'items.*.url' => ['nullable', 'required_if:items.*.type,custom_link', 'string', 'max:2048', 'regex:'.LinkDestination::CUSTOM_URL_PATTERN],
                'items.*.target' => ['required', 'string', 'in:_self,_blank'],
                'items.*.order' => ['required', 'integer', 'min:0'],
            ])->validate();

            $this->keepStoredProductItems($menu, $validated['items']);

            $ids = array_column($validated['items'], 'id');
            $parents = [];
            foreach ($validated['items'] as $row) {
                if (isset($row['id'])) {
                    abort_if(array_key_exists($row['id'], $parents), 422, 'Menu temporary IDs must be unique.');
                    $parents[$row['id']] = $row['parent_id'] ?? null;
                }
            }
            foreach ($validated['items'] as $row) {
                if (! empty($row['parent_id'])) {
                    abort_unless(isset($row['id']) && in_array($row['parent_id'], $ids, true), 422, 'Invalid menu parent reference.');
                    $seen = [$row['id']];
                    $parent = $row['parent_id'];
                    while ($parent !== null) {
                        abort_if(in_array($parent, $seen, true), 422, 'Menu hierarchy cannot contain a cycle.');
                        $seen[] = $parent;
                        $parent = $parents[$parent] ?? null;
                    }
                }
                if ($row['type'] !== 'custom_link' && ! empty($row['linkable_id']) && array_key_exists($row['type'], self::linkableTypes())) {
                    $class = $this->resolveLinkableType($row['type']);
                    abort_unless($class::whereKey($row['linkable_id'])->where('locale', $menu->locale)->exists(), 422, 'Menu target must exist in the menu language.');
                }
            }
            DB::transaction(function () use ($validated, $menu) {
                $menu->items()->delete();

                $idMap = [];

                foreach ($validated['items'] as $itemData) {
                    $menuItem = MenuItem::create([
                        'menu_id' => $menu->id,
                        'parent_id' => null,
                        'label' => $itemData['label'],
                        'type' => $itemData['type'],
                        'linkable_type' => $this->resolveLinkableType($itemData['type']),
                        'linkable_id' => $itemData['type'] !== 'custom_link' ? ($itemData['linkable_id'] ?? null) : null,
                        'url' => $itemData['type'] === 'custom_link' ? SiteUrl::toRelative($itemData['url'] ?? null) : null,
                        'target' => $itemData['target'],
                        'translation_status' => ($itemData['type'] !== 'custom_link' && empty($itemData['linkable_id'])) ? 'needs_review' : null,
                        'order' => $itemData['order'],
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

            return $menu->fresh();
        }, 3));
    }

    /**
     * With the shop catalog off, product items are not checked against the catalog: each one sent must be one the
     * menu already stores (same target, at most as many times), so saving never drops them and nothing new links to
     * a catalog that is not there. Any other product item is a validation error on its `type`.
     */
    private function keepStoredProductItems(Menu $menu, array $items): void
    {
        if (array_key_exists('product', self::linkableTypes())) {
            return;
        }
        $stored = $menu->items()->where('type', 'product')->pluck('linkable_id')
            ->map(fn ($id) => (string) $id)->countBy()->all();
        foreach ($items as $index => $row) {
            if ($row['type'] !== 'product') {
                continue;
            }
            $target = (string) ($row['linkable_id'] ?? '');
            if (($stored[$target] ?? 0) < 1) {
                throw ValidationException::withMessages([
                    "items.{$index}.type" => 'The shop catalog is off: product items already in the menu are kept, new ones cannot be added.',
                ]);
            }
            $stored[$target]--;
        }
    }

    public function delete(User $actor, Menu $menu, array $input = []): void
    {
        Gate::forUser($actor)->authorize('delete', $menu);

        EditorialActor::run($actor, fn () => app(ContentRevisionLedger::class)->transaction(function () use ($menu, $input) {
            $menu = Menu::query()->lockForUpdate()->findOrFail($menu->id);
            $this->check($menu, $input['expected_revision'] ?? null);

            $menu->delete();

        }, 3));
    }
}
