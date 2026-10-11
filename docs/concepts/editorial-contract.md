# Editorial Contract

> The four shared capabilities a public content entity can implement, and what each one guarantees. Storage
> shape stays entity-specific: `Page` keeps `content_json`, `Post` keeps first-class columns.

Implement only the capabilities the entity actually has.

## Publishable

`App\Contracts\Editorial\Publishable`, implemented by `App\Traits\HasPublicationState` for the standard
`status` + `published_at` columns.

- `published()` returns only `status = published`, excluding future `published_at` and expired `expires_at`.
- `isPublished()` applies the same rule to one instance.
- Public queries MUST go through `published()`.

## Seoable

`App\Contracts\Editorial\Seoable`, for entities with their own public detail page.

- `meta()` returns metadata the shared SEO helpers understand.
- `url()` returns the public path.
- `seoTitleFallback()` returns a stable label, usually the title.

Storage is per entity: `Page` reads `content_json.meta`, `Post` reads `meta_json`.

## Mediable

`App\Contracts\Editorial\Mediable` with `App\Traits\HasMedia`.

- Media attaches through the shared polymorphic relation; featured images use the `featured_image` collection.
- Content-managed image fields store media ids.
- Seeded records fill image fields with the matching placeholder ([../content/images.md](../content/images.md)).

## Previewable

`App\Contracts\Editorial\Previewable`, for entities whose admin flow renders a public-like preview.

- `previewView()` returns the Blade view.
- `previewData()` returns its data.
- Controllers may hydrate unsaved state before rendering ([../admin/live-editor.md](../admin/live-editor.md)).

Keep this contract small: it does not own persistence, validation or MCP serialization.

## Implementations

`App\Models\Page` and `App\Models\Post` implement all four.

## New Public Collection

1. `status` + `published_at` → `Publishable`.
2. Metadata + public route → `Seoable`.
3. Images or files → `HasMedia` + `Mediable`.
4. Preview in the admin → `Previewable`.
5. Keep collection fields explicit instead of hiding them in a generic blob.

The contract does **not** require a shared schema, a migration away from `content_json` or `meta_json`, generic
sitemap/preview services, or one editor UI for every type. It does require each type to be operable through
explicit MCP tools of its own ([MCP surface](../operations/mcp-surface.md)); there is no generic model dispatch.
