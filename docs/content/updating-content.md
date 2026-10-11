# Updating Stored Content

> Changing content that already exists in `content_json` without destroying sibling keys. The shape itself is
> [../concepts/content-model.md](../concepts/content-model.md).

Use this for copy changes, loading arrays of items (FAQs, steps, features, cards) and any nested update done
outside the admin UI.

## Rules

- Editable content lives in the database. If a template holds hardcoded copy, that is a defect to fix, not a
  pattern to follow.
- An agent edits through MCP: `pages_update_draft` / `pages_update` take the change as a patch (objects merge,
  lists replace whole, `null` removes a key) with the current `expected_revision`; editors stage published changes
  with `content_propose` ([../operations/mcp.md](../operations/mcp.md#partial-content-edits)). In `essential` this
  is the editor for structured sections.
- Use the admin UI when the form already supports the change; use application code (tinker or a script) for
  bulk or deeply nested updates.
- Never `UPDATE` the JSON column with raw SQL.
- For a multilingual site, check which paths are `translatable` before editing
  ([../concepts/multilanguage.md](../concepts/multilanguage.md)) — editing base copy outdates its translations.

## Workflow

**1. Identify the entity and its template.**

```bash
php artisan tinker --execute="echo \App\Models\Page::where('slug','landing-page')->value('template_key');"
```

**2. Read the template** (`resources/views/templates/{key}.blade.php`) to learn the section key, the field
names and whether each expects a string, array, HTML, boolean or media id. Do not guess field names.

**3. Read the current payload** before writing:

```bash
php artisan tinker --execute="\$p=\App\Models\Page::where('slug','landing-page')->first(); echo json_encode(\$p->content_json['sections']['faq'] ?? null, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);"
```

**4. Decide the mutation:** replace, append or merge. Overwriting a whole section silently drops siblings like
`heading`, `image_id` or `is_visible`.

**5. Read, mutate, write back:**

```php
$page = \App\Models\Page::where('slug', 'landing-page')->firstOrFail();
$content = $page->content_json ?? [];
$faq = $content['sections']['faq'] ?? [];

$faq['items'] = [
    ['question' => 'Question A', 'answer' => 'Answer A'],
    ['question' => 'Question B', 'answer' => 'Answer B'],
];

$content['sections']['faq'] = $faq;
$page->content_json = $content;
$page->save();
```

Keep media as ids, never paths or URLs, and leave template files untouched for a content-only change. Fill an
empty media slot with the matching placeholder instead of leaving it null ([images.md](images.md)).

**6. Verify** the stored payload and the rendered page.

## Checklist

- [ ] Entity and `template_key` confirmed
- [ ] Template inspected for real field names
- [ ] Current payload read
- [ ] Mutation type decided (replace / append / merge)
- [ ] Sibling keys preserved
- [ ] Stored data **and** rendered output verified
