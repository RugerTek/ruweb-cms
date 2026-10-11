# Pages

> The page entity: what it is, how it routes, and the rules a page must follow.
> Data shape: [../concepts/content-model.md](../concepts/content-model.md). Templates: [templates.md](templates.md).

## What A Page Is

A URL-addressable entity with a unique `slug`, a `template_key`, a `content_json` payload, `status` +
`published_at`, and an optional featured image through the shared media relation. Used for `/about`,
`/services`, `/contact` — anything that is not a repeatable collection entry.

`App\Models\Page` implements all four editorial contracts
([../concepts/editorial-contract.md](../concepts/editorial-contract.md)) and reads its SEO metadata from
`content_json.meta`.

## Routing

Pages resolve through the catch-all `page.show` route (and `localized.page.show` under a locale prefix). Two
consequences:

- **Every explicit public route MUST be registered before it** in `routes/web.php`.
- Public resolution queries `Page::published()` only.

The homepage is not resolved by slug: it is the page whose `translation_group_id` matches the
`homepage_translation_group_id` setting.

## Rules

1. **No editable copy in Blade.** User-facing text belongs to `content_json`; a hardcoded string in a template
   is a defect to fix, not a pattern to copy.
2. **Content-managed images are media ids.** Featured images go through the media relation; section images
   store `image_id`. Never a path or a URL. Starter pages fill every slot with the matching placeholder
   ([images.md](images.md)).
3. **The featured image is not part of `content_json`.** `meta.featured_image` exists only to override the
   social image when it must differ.
4. **Templates honor `is_visible`** and skip sections with no meaningful content.
5. **Content follows the `meta` + `sections` contract** — no third top-level key.
6. **Editing an existing page uses the shared live editor** in `full`
   ([../admin/live-editor.md](../admin/live-editor.md)); the create screen may be a plain form, since there is no
   model to preview yet. In `essential` there is no Live Editor: pages are edited through MCP (`pages_update`,
   `pages_update_draft`, `content_propose`), so every field must be declared in the template schema
   ([../operations/mcp-surface.md](../operations/mcp-surface.md)).

## Creating A Page

1. Choose the `slug` (and confirm it does not collide with a reserved prefix or a locale code).
2. Choose or register the `template_key` ([templates.md](templates.md)).
3. Match the section keys to the ones the template declares.
4. Attach media through the media system, using placeholders for empty slots.
5. Declare every editable path in the template's `schema` (translatable / preserve / remap).
6. Verify preview, publish, and the public URL.

## Updating A Page

Follow [updating-content.md](updating-content.md): identify by slug, inspect the template before writing,
read-mutate-write preserving sibling keys, verify the rendered output.
