---
name: create-section
description: Adds a new section to an existing page template in ruweb-cms — config sections and schema, template markup, editor partial, translations and placeholders. Use when the user wants an extra block on a page that already exists ("add an FAQ to the home", "sumale una sección de testimonios a la landing").
---

# Add A Section To An Existing Template

Read the "Adding A Section To An Existing Template" steps in
[docs/content/templates.md](../../../docs/content/templates.md) ; read repeater UI rules in
[docs/admin/live-editor-ui-ux.md](../../../docs/admin/live-editor-ui-ux.md) only for full.

## Profile First

Run `php artisan cms:capabilities` and read
[docs/concepts/admin-profiles.md](../../../docs/concepts/admin-profiles.md) before choosing deliverables.
`full` requires specialized editors for custom structured fields. In `essential`, omit specialized Live
Editor partials/forms by default; preserve schemas, renderers, validation/operations, permissions, media,
publication and tests. Read Live Editor UI/UX only when creating or changing a visual editor. Verify public
rendering and operation through shared operations/MCP in essential. Both profiles expose the same MCP tools;
role/scope permissions are independent. Essential’s operational panel passed phase 4; later capabilities retain their own roadmap gates.

## Steps

1. **Locate the template** in `config('cms.templates')` and confirm which pages use it — every one of them
   gets the section, so it must render harmlessly when its content is empty.
2. **Add the key** to that template's `sections`, in the position it renders.
3. **Add its paths to `schema`** with the right flags. A repeated item list uses `items.*.field`.
4. **Render it** in `resources/views/templates/{key}.blade.php`, guarded by `is_visible` and a content check.
5. **Full only: editor partial** `resources/views/admin/pages/sections/{template}_{section}.blade.php` when it needs more
   than `heading` + `body`: one accordion, visibility switch in the header, and for repeaters the markup
   contract (`data-repeater`, item accordions or flat rows for single-field items, confirm-before-delete,
   green add button, reorder controls).
6. **Placeholders** for every image field ([docs/content/images.md](../../../docs/content/images.md)).
7. **Translations** in `lang/es/admin.php` and `lang/en/admin.php`, key-for-key.
8. **Verify**: existing pages of that template still render and save; the new section appears collapsed in the
   editor with a working visibility switch; `composer test`.

## Decide First

- Is this content really page-specific? Data owned by a collection is referenced, never copied into the
  section ([docs/admin/live-editor-ui-ux.md](../../../docs/admin/live-editor-ui-ux.md), "Sections Backed By
  Admin Collections").
- Is it a repeater? Fixed item count → no add/delete controls. Client-decided count → administrable repeater.
