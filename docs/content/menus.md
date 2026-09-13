# Menus

> Database-driven menus with nested items, assigned to theme locations. WordPress-style, administrable at
> `/admin/menus`.

## Contents

- Schema
- Models And URL Resolution
- Theme Locations
- Rendering
- Admin Builder
- Adding A Location
- Authorization And Conventions
- Multilingual Menus

## Schema

`menus`: `name`, `slug` (unique, e.g. `header`), `location` (nullable theme location).

`menu_items`:

| Column | Notes |
|---|---|
| `menu_id` | Cascade on delete |
| `parent_id` | Self-referential, null on delete |
| `label` | Display text |
| `type` | `custom_link`, `page`, `post`, `taxonomy` |
| `linkable_type` / `linkable_id` | Polymorphic target |
| `url` | Only for `custom_link` |
| `target` | `_self` (default) or `_blank` |
| `order` | Position among siblings |

Indexed on `(menu_id, parent_id, order)`.

## Models And URL Resolution

```php
Menu::findBySlug('header');      // ?Menu
Menu::findByLocation('footer');  // ?Menu
$menu->items();                  // ordered
$menu->rootItems();
$menu->tree();                   // nested collection, one query, children pre-set

$item->linkable();               // MorphTo: Page, Post or Taxonomy
$item->resolveUrl();             // final href
```

`resolveUrl()`: `custom_link` → `url`; `page` / `post` → `$linkable->url()`; `taxonomy` →
`route('blog.index', ['category' => $linkable->slug])`; orphaned or unknown → `#`.

Deleting a linked model leaves the item orphaned — it keeps rendering with `#` and must be cleaned up in the
admin.

## Theme Locations

```php
// config/cms.php
'menu_locations' => [
    'header' => 'Header Navigation',
    'footer' => 'Footer Navigation',
],
```

New locations appear automatically in the admin dropdown. The `header` and `footer` menus are rendered by the
layout partials ([../admin/layout.md](../admin/layout.md)); the Header and Footer admin screens never edit menu
items, they link to the menu assigned to their location.

## Rendering

```blade
<x-menu-component slug="header" class="flex items-center gap-6" />
<x-menu-component location="footer" class="flex gap-4" />
```

Attributes are forwarded to the root `<ul>`; the component renders nothing when the menu is missing or empty.
Sub-menus render as nested `<ul class="sub-menu">`, styled from the parent:

```blade
<x-menu-component slug="header" class="flex gap-6 [&_.sub-menu]:hidden" />
```

Files: `app/View/Components/MenuComponent.php`, `resources/views/components/menu.blade.php`,
`resources/views/components/menu-item.blade.php`.

## Admin Builder

`/admin/menus`. The edit screen has menu settings (name, slug, location) and a two-panel structure builder:
accordions to add items on the left, a sortable nested list (SortableJS) on the right; clicking an item expands
label, URL and target, with remove inside that panel.

Saving posts the whole tree as a flat array to `PUT /admin/menus/{menu}/items`. The controller deletes and
recreates every item in a transaction, resolving parents in two passes. **There is no partial item update** —
saving is always a full replace.

`MenuSeeder` (part of `db:seed`) creates a Header menu with Home, Blog and About custom links, and an empty
Footer menu. It only seeds items when the menu has none, so re-running is safe.

## Adding A Location

1. Add the key to `menu_locations` in `config/cms.php`.
2. Create the menu in the admin or in a seeder.
3. Render it: `<x-menu-component location="sidebar" />`.

## Authorization And Conventions

| Action | Roles |
|---|---|
| View / edit structure | admin, editor |
| Create or delete a menu | admin |

Policy: `App\Policies\MenuPolicy`.

- Slugs match `^[a-z0-9]+(?:-[a-z0-9]+)*$`.
- Nesting works at any depth; keep it to two levels.
- Validate that a linked id exists and matches the selected type before saving.
- One menu per location by convention; uniqueness is not enforced and `findByLocation()` returns the first match.

## Multilingual Menus

Menus are complete per locale: a header menu in English is a separate menu from the base-locale one, even at the
same location. The localized menu is used when it exists, otherwise the base-locale menu renders.

When duplicating a menu, `page` / `post` / `taxonomy` targets must be remapped to the translation in the same
`translation_group_id`. Without a translated target the item is flagged for review or left out of public
rendering — a secondary menu **never** links silently to base-language content. Custom links are copied as-is
([../concepts/multilanguage.md](../concepts/multilanguage.md)).
