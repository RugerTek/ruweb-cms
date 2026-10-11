# Taxonomies

> One generic, polymorphic classification system for every content type: categories, tags, regions, whatever a
> project needs. No per-collection taxonomy tables.

## Contents

- Schema
- Model and trait API
- Adding A Type
- Attaching A New Model
- Conventions
- Querying Publicly

## Schema

`taxonomies` — the terms:

| Column | Notes |
|---|---|
| `name` | Display name |
| `slug` | URL-safe, unique **per `type`** |
| `type` | Discriminator: `category`, `tag`, … |
| `parent_id` | Self-referential, nullable |
| `description` | Nullable |
| `order` | Sort weight within a type |

Unique: `(slug, type)` — the same slug may exist in two types.

`taxables` — the polymorphic pivot: `taxonomy_id` (cascade on delete), `taxable_id`, `taxable_type`, unique on
the three together.

Adding a type needs **no migration**; the `type` column carries it.

## API

```php
Taxonomy::ofType('category')->ordered()->get();
Taxonomy::ofType('tag')->roots()->get();
$taxonomy->children;   // HasMany
$taxonomy->parent;     // BelongsTo
```

`App\Traits\HasTaxonomies` on any model:

```php
$post->taxonomies();                        // MorphToMany, all types
$post->taxonomiesByType('category');
$post->categories();                        // shorthand
$post->syncTaxonomies([1, 3], 'category');  // replaces only that type
```

`syncTaxonomies()` touches only the given type, leaving others intact. **Validate that incoming ids belong to
the intended type before syncing** — nothing in the model does it for you.

## Adding A Type

1. Pick a `type` string: singular, lowercase, snake_case (`region`, `industry`), and add it to
   `config('cms.taxonomy_types')` — the panel operations and the `taxonomies_*` MCP tools only accept registered
   types.
2. Add `taxonomy_type_<type>` to `lang/es/admin.php` and `lang/en/admin.php`, key-for-key.
3. Add the sidebar link as a **subitem inside its collection's collapsible group** in
   `resources/views/admin/partials/sidebar.blade.php` — never a standalone top-level item:

   ```html
   <li><a href="{{ route('admin.taxonomies.index', 'region') }}" …>Regions</a></li>
   ```

   The group auto-expands on active routes through the `data-sidebar-toggle` JS in that file.
4. Apply `HasTaxonomies` to the model and call `syncTaxonomies($ids, 'region')` on store and update.
5. Add the checkbox list **with its inline-create input** to the admin form partial, copying
   `resources/views/admin/posts/_form.blade.php`. The inline block POSTs JSON to
   `route('admin.taxonomies.store', $type)` and appends a checked checkbox on success.

## Operations And MCP

Every write — panel screen, inline create in a post form, MCP — goes through
`App\Services\Content\TaxonomyOperations` (`create`, `update`, `translate`, `delete`). Deleting a term moves its
children up one level, removes it from the content and menus that used it and reports both. Agents use
`taxonomies_list`, `taxonomies_get`, `taxonomies_create`, `taxonomies_update` (patch, revision required),
`taxonomies_translate` and `taxonomies_delete` ([../operations/mcp.md](../operations/mcp.md)). In `essential` the
taxonomy screens are hidden, so MCP is how terms are managed there.

## Attaching A New Model

To give pages categories: `use HasTaxonomies` on `App\Models\Page`; pass `$categories` from `create`/`edit`;
call `syncTaxonomies($data['categories'] ?? [], 'category')` in `store`/`update`; copy the selector block into
`resources/views/admin/pages/_form.blade.php`; add `categories` validation to `StorePageRequest` and
`UpdatePageRequest`.

## Conventions

- Slugs match `^[a-z0-9]+(?:-[a-z0-9]+)*$` and are unique within the type.
- Hierarchy is optional; keep it at most two levels deep for UI clarity.
- Deleting a parent promotes its children one level — handled by the controller.
- Editors and admins create and update; only admins delete.
- **Every taxonomy checkbox list must offer inline creation.** A user never leaves the content form to create a
  term. Show the input even when no terms exist yet.

## Querying Publicly

```php
Post::published()
    ->whereHas('taxonomies', fn ($q) => $q->where('slug', $slug)->where('type', 'category'))
    ->latest('published_at')
    ->paginate(10);
```

`Taxonomy` has no inverse `taxables` relation, so public queries go through the content model relationship
unless a project adds one deliberately.
