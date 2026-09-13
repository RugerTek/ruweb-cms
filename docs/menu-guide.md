# Menu System

This starter includes an administrable menu system similar to WordPress menus. Menus are database-driven, support nested items, and can be assigned to theme locations (header, footer, etc.).

---

## Multilingual Menus

Menus are complete per locale. A header menu in English is a separate menu from the header menu in the base language, even when they share the same location.

When duplicating a menu for another locale, internal page, post, and taxonomy targets must be remapped to the translated target in the same `translation_group_id`. If the translated target does not exist, the item should be marked for review or omitted from public rendering; secondary-language menus must not silently link to base-language content.

---

## Database schema

### `menus`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `name` | string | Display name, e.g. "Header Menu" |
| `slug` | string unique | Machine identifier, e.g. `header` |
| `location` | string nullable | Theme location: `header`, `footer`, … |
| `created_at` / `updated_at` | timestamps | |

### `menu_items`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `menu_id` | bigint FK → menus | Cascade on delete |
| `parent_id` | bigint FK → menu_items nullable | Self-referential, null on delete |
| `label` | string | Display text |
| `type` | string | `custom_link`, `page`, `post`, `taxonomy` |
| `linkable_type` | string nullable | Polymorphic model class |
| `linkable_id` | bigint nullable | Polymorphic model id |
| `url` | string nullable | Used only for `custom_link` type |
| `target` | string | `_self` (default) or `_blank` |
| `order` | int unsigned | Sort position within siblings |
| `created_at` / `updated_at` | timestamps | |

**Index:** `(menu_id, parent_id, order)`.

When a linked model (page, post, taxonomy) is deleted, `linkable_id` and `linkable_type` become orphaned — `resolveUrl()` falls back to `#` gracefully.

---

## Core components

### `App\Models\Menu`

```php
Menu::findBySlug('header');        // ?Menu
Menu::findByLocation('footer');    // ?Menu

$menu->items();                    // HasMany MenuItem, ordered by `order`
$menu->rootItems();                // HasMany, root items only
$menu->tree();                     // Collection — nested, children set as relation
```

`tree()` loads all items in one query, groups by `parent_id`, and builds a nested collection recursively. Each item has a `children` relation pre-set.

### `App\Models\MenuItem`

```php
$item->menu();       // BelongsTo Menu
$item->parent();     // BelongsTo self
$item->children();   // HasMany self, ordered
$item->linkable();   // MorphTo — Page, Post, or Taxonomy

$item->resolveUrl(); // Returns the final href string
```

`resolveUrl()` logic:
- `custom_link` → returns `$this->url`
- `page` / `post` → calls `$this->linkable->url()`
- `taxonomy` → builds `route('blog.index', ['category' => $this->linkable->slug])`
- orphaned or unknown → returns `#`

The default blog index honors the `category` query parameter for category taxonomy links.

---

## Theme locations

Locations are defined in `config/cms.php`:

```php
'menu_locations' => [
    'header' => 'Header Navigation',
    'footer' => 'Footer Navigation',
],
```

Add new locations here; they appear automatically in the admin location dropdown.

The `header` and `footer` menus are rendered by the layout partials described in `docs/layout-header-footer.md`. The Header and Footer admin screens do not edit menu items; they link to the menu assigned to their location.

---

## Rendering menus in Blade

Use the `<x-menu-component>` Blade component. It accepts `slug` or `location` to identify the menu, plus any HTML attributes forwarded to the root `<ul>`.

```blade
{{-- By slug --}}
<x-menu-component slug="header" class="flex items-center gap-6" />

{{-- By location --}}
<x-menu-component location="footer" class="flex gap-4" />
```

The component renders nothing if the menu does not exist or has no items.

**Component files:**
- `app/View/Components/MenuComponent.php`
- `resources/views/components/menu.blade.php` — renders the `<ul>`
- `resources/views/components/menu-item.blade.php` — renders each `<li>` recursively

Sub-menus are rendered as nested `<ul class="sub-menu">`. Style them with CSS or Tailwind arbitrary variants on the parent class:

```blade
{{-- Hide sub-menus (flat nav) --}}
<x-menu-component slug="header" class="flex gap-6 [&_.sub-menu]:hidden" />

{{-- Indent sub-menus (mobile nav) --}}
<x-menu-component slug="header" class="[&_.sub-menu]:pl-4" />
```

---

## Admin interface

Menus are managed at `/admin/menus`.

**Index** — lists all menus with name, slug, location, item count, and edit/delete actions. Only admins can create or delete menus; editors can edit item structure.

**Create** — name, slug, and optional location. Slug is auto-generated from the name.

**Edit** — two sections:

1. **Menu settings** — update name, slug, location.
2. **Menu structure** — a two-panel builder:
   - Left: accordion panels to add items (custom link, pages, posts, taxonomies).
   - Right: a sortable nested list (powered by SortableJS). Drag items to reorder or nest them. Click an item to expand its settings (label, URL, target). Remove button is inside the settings panel.

Saving the structure sends all items as a flat array to `PUT /admin/menus/{menu}/items`. The controller deletes existing items and recreates them in a transaction, using a two-pass approach to resolve parent references.

---

## Seeder

`MenuSeeder` runs as part of `php artisan db:seed` and creates:

- **Header Menu** (`slug: header`, `location: header`) — seeded with three custom links: Home (`/`), Blog (`/blog`), About (`/about`).
- **Footer Menu** (`slug: footer`, `location: footer`) — empty by default.

Items are only seeded when the menu has no existing items, so re-running the seeder is safe.

---

## Adding a new theme location

1. Add the location to `config/cms.php`:
   ```php
   'menu_locations' => [
       'header' => 'Header Navigation',
       'footer' => 'Footer Navigation',
       'sidebar' => 'Sidebar',   // new
   ],
   ```
2. Create the menu in the admin or via seeder.
3. Render it in a Blade template:
   ```blade
   <x-menu-component location="sidebar" />
   ```

---

## Authorization

| Action | Roles |
|---|---|
| View / edit menu structure | admin, editor |
| Create menu | admin only |
| Delete menu | admin only |

Policy: `App\Policies\MenuPolicy`.

---

## Conventions

- **Slugs** must be lowercase alphanumeric with hyphens: `^[a-z0-9]+(?:-[a-z0-9]+)*$`.
- **Nesting** is supported at any depth, but keep it shallow (≤ 2 levels) for usability.
- **Item save** is always a full replace — the entire item tree is submitted and recreated. There is no partial update for individual items.
- **Link validation** should ensure linked IDs exist and match the selected type before saving custom extensions to the builder.
- **Orphaned links** — if a linked page, post, or taxonomy is deleted, the menu item stays but `resolveUrl()` returns `#`. Clean them up manually in the admin.
- **One menu per location** — the convention is one active menu per location, but the system does not enforce uniqueness on `location`. `findByLocation()` returns the first match.
