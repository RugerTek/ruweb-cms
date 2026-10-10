---
name: create-page
description: Creates a new public page in the ruweb-cms starter — slug, template, sections, media and SEO — following the page and content-model contracts. Use when the user asks for a new page or landing ("add an About page", "necesito una página de servicios"), or to add sections to a page that does not exist yet.
---

# Create A Page

Read [docs/content/pages.md](../../../docs/content/pages.md) and
[docs/concepts/content-model.md](../../../docs/concepts/content-model.md) before writing anything. For a new
template also read [docs/content/templates.md](../../../docs/content/templates.md).

## Profile First

Run `php artisan cms:capabilities` and read
[docs/concepts/admin-profiles.md](../../../docs/concepts/admin-profiles.md) before choosing deliverables.
`full` requires specialized editors for custom structured fields. In `essential`, omit specialized Live
Editor partials/forms by default; preserve schemas, renderers, validation/operations, permissions, media,
publication and tests. Read Live Editor UI/UX only when creating or changing a visual editor. Verify public
rendering and operation through shared operations/MCP in essential. Both profiles expose the same MCP tools;
role/scope permissions are independent. Essential remains experimental until phase 4.

## Steps

1. **Settle the spec.** Page name, slug (infer from the name when not given), sections and the fields each one
   needs. Ambiguity is resolved with the smallest interpretation consistent with the existing architecture —
   state the assumption instead of asking, unless the answer changes the data model.
2. **Choose the template.** Reuse an existing `config('cms.templates')` entry when its `sections` match.
   Register a new one only when the shape is genuinely different (`create-template` covers that).
3. **Reuse section keys** already used by other templates when the meaning is the same — it makes switching
   templates safe. Otherwise pick clear, stable names.
4. **Write the content**, `meta` + `sections` only, `is_visible` on every section, media as ids.
5. **Fill every image slot** with the placeholder matching the slot's aspect ratio, resolved by
   `placeholder_key` ([docs/content/images.md](../../../docs/content/images.md)), so the page renders complete
   on a fresh install.
6. **Full only: add a section editor partial** (`resources/views/admin/pages/sections/{template}_{section}.blade.php`)
   for any section needing more than the generic `heading` + `body`, following
   [docs/admin/live-editor-ui-ux.md](../../../docs/admin/live-editor-ui-ux.md).
7. **Declare every editable path** in the template `schema` with `translatable` / `preserve` / `remap`.
8. **Translations** for any new admin label in `lang/es/admin.php` and `lang/en/admin.php`, key-for-key.
9. **Verify**: the page resolves on its public URL, preview works in the editor, and `composer test` passes.

## Never

- Hardcode user-facing copy in Blade.
- Store an image path or URL instead of a media id.
- Put the featured image inside `content_json` (use the media relation; `meta.featured_image` is the social
  override only).
- Register the page route after the catch-all.
