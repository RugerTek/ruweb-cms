---
name: update-content
description: Edits content already stored in a page's content_json — copy changes, FAQ or card lists, nested section payloads — without losing sibling keys, through MCP or code. Use when the user asks to change text or items on an existing page from an agent, code or tinker ("cambiá el texto del hero", "load these 6 FAQs into the services page").
---

# Update Stored Content

Read [docs/content/updating-content.md](../../../docs/content/updating-content.md). Shape reference:
[docs/concepts/content-model.md](../../../docs/concepts/content-model.md).

## Through MCP (preferred, and the only editor in `essential`)

1. `pages_list` / `pages_get` → note `template_key` and `revision`; `cms_schema` lists the writable paths.
2. Send only the change as a patch: objects merge, lists replace whole, `null` removes a key
   ([docs/operations/mcp.md](../../../docs/operations/mcp.md#partial-content-edits)).
   - Draft → `pages_update_draft` (or `pages_update`).
   - Published, as administrator → `pages_update` changes it live and keeps it published.
   - Published, as editor → `content_propose`, then `content_review_request`.
3. Always pass `expected_revision`; on `-32009` read again and merge. Read `warnings` (discarded markup).
4. Read the record back; `content_preview` for a visual check of a draft or proposal.

## From Code

1. **Find the entity and its template**:
   `php artisan tinker --execute="echo \App\Models\Page::where('slug','…')->value('template_key');"`
2. **Read the template view** to learn the real section key and field names. Do not guess them.
3. **Read the current payload** for that section before writing.
4. **Decide replace / append / merge.** Overwriting a whole section drops siblings such as `heading`,
   `image_id` or `is_visible`.
5. **Read, mutate, write back** in PHP — never raw SQL against the JSON column.
6. **Verify** the stored payload and the rendered page.

## Rules

- Media stays as ids; an empty slot gets the matching placeholder rather than `null`.
- Never write an absolute URL of this site into content.
- If the copy you were asked to change turns out to be hardcoded in the template, that is the defect: move it
  into the content payload instead of editing the Blade.
- On a multilingual site, check which paths are `translatable` first — editing base copy marks translations
  outdated ([docs/concepts/multilanguage.md](../../../docs/concepts/multilanguage.md)).
