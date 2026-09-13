# Editorial Contract

## Purpose

This document defines the shared contract for public editorial entities such as pages, posts, and future collections.

Use it when a model renders public content and needs common behavior for publishing, SEO, media, or preview.

Do not use it to force all content types into the same storage shape. Pages may keep structured JSON while collections may keep first-class fields.

## Contracts

Editorial entities should implement only the capabilities they actually support.

### Publishable

Use `App\Contracts\Editorial\Publishable` for public visibility.

Expected behavior:

- `published()` only returns records with `status = published`
- `published()` excludes future `published_at` values
- `isPublished()` uses the same rule for one model instance

Models with the standard `status` and `published_at` columns should use `App\Traits\HasPublicationState`.

### Seoable

Use `App\Contracts\Editorial\Seoable` when an entity renders its own public detail page.

Expected behavior:

- `meta()` returns metadata compatible with the shared SEO helpers
- `url()` returns the public path or route for the entity
- `seoTitleFallback()` returns a stable label, usually the model title

The storage location is model-specific. `Page` reads from `content_json.meta`; `Post` reads from `meta_json`.

### Mediable

Use `App\Contracts\Editorial\Mediable` with `App\Traits\HasMedia` for content-managed files and images.

Expected behavior:

- media is attached through the shared polymorphic media relation
- featured images use the `featured_image` collection
- content-managed image fields store media identifiers rather than raw paths when possible
- seeded and example records fill their image fields with the placeholder matching the rendered aspect ratio: `horizontal`, `square` or `vertical`, resolved by `placeholder_key` (see `docs/image-strategy.md`)

### Previewable

Use `App\Contracts\Editorial\Previewable` when an admin editing flow can render a public-like preview.

Expected behavior:

- `previewView()` returns the Blade view used for preview rendering
- `previewData()` returns the view data needed by that preview
- controllers may still prepare unsaved model state before rendering

This contract should stay small. It should not centralize editor persistence, validation, or MCP serialization.

## Current Implementations

- `App\Models\Page` implements all four contracts while keeping `content_json` as its structured content store.
- `App\Models\Post` implements all four contracts while keeping `content`, `excerpt`, and `meta_json` as first-class collection fields.

## Adding A New Public Collection

For a new collection such as products, case studies, or events:

1. Add `status` and `published_at` if the entity has public visibility, then use `Publishable`.
2. Add metadata compatible with the SEO layer if the entity has a public detail route, then use `Seoable`.
3. Use `HasMedia` and `Mediable` for content-managed media.
4. Use `Previewable` only if the admin edit workflow renders previews.
5. Keep collection-specific fields explicit instead of hiding them behind a generic content blob.

## Boundaries

This contract does not require:

- a shared database schema for all editorial entities
- migration from `content_json`, `content`, `excerpt`, or `meta_json`
- generic sitemap, MCP, or preview services
- a universal editor UI for every content type
