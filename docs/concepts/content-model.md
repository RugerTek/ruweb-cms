# Content Model

> The shape of structured content stored in a JSON column (`pages.content_json`). Data shape only — template
> registration is [../content/templates.md](../content/templates.md), editing it is
> [../content/updating-content.md](../content/updating-content.md).

## Canonical Shape

```json
{
    "meta": {
        "title": "Optional SEO title override",
        "description": "Meta and social description",
        "featured_image": 42
    },
    "sections": {
        "hero": { "is_visible": 1, "heading": "Welcome", "body": "Short intro", "image_id": 42 },
        "faq":  { "is_visible": 1, "heading": "FAQ", "items": [ { "question": "A", "answer": "B" } ] }
    }
}
```

`meta` and `sections` are the only top-level keys. Never add a third.

Read it with `$entity->meta()` and `$entity->sections()`; never touch `content_json` directly in a view.

## Meta

| Field | Purpose | Fallback |
|---|---|---|
| `title` | SEO/browser title override | Entity title, then site name |
| `description` | Meta, Open Graph and Twitter description | Global site description setting |
| `featured_image` | Social image override, as a media id | Entity featured image, then `default_social_image` |

The displayed title stays in the entity column (`title`). Use `meta.title` only when the SEO title must differ.

The entity's featured image belongs to the shared media relationship, **not** inside `content_json`.
`meta.featured_image` exists only to override the social image.

## Sections

- Each key matches a section declared by the template in `config('cms.templates')`.
- Every section carries `is_visible`: `1` renders, `0` does not. **A missing `is_visible` means visible**, which
  is why templates guard with `($section['is_visible'] ?? 1)`.
- Any additional keys the template needs are allowed inside a section.
- Media is stored as a media id (`image_id`), never a path or URL. Starter and seeded content fills every image
  slot with the matching placeholder ([../content/images.md](../content/images.md)).
- **Never store an absolute URL of this site.** Use ids, or root-relative paths when a URL is unavoidable, and
  build absolute URLs at render time — content travels between domains through snapshots
  ([../operations/snapshots.md](../operations/snapshots.md)).

HTML/canvas/composed pages retain this same shape; their fragments and block lists live under `sections`.
See [composition](../content/composition.md) for the separate sanitized contract.

## Multilingual

Each localized record has its own `content_json`. Never store `{ "es": "…", "en": "…" }` inside one field; the
translation model is one record per locale ([multilanguage.md](multilanguage.md)).

## Reading It

```php
$sections = $page->sections();
$hero = $sections['hero'] ?? [];
```

```blade
@if(($hero['is_visible'] ?? 1) && ($hero['heading'] ?? null))
    <section>…</section>
@endif
```
