---
name: update-content
description: Edits content already stored in a page's content_json — copy changes, FAQ or card lists, nested section payloads — without losing sibling keys. Use when the user asks to change text or items on an existing page from code or tinker ("cambiá el texto del hero", "load these 6 FAQs into the services page").
---

# Update Stored Content

Read [docs/content/updating-content.md](../../../docs/content/updating-content.md). Shape reference:
[docs/concepts/content-model.md](../../../docs/concepts/content-model.md).

## Workflow

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
