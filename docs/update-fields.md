# Updating Structured Content

## Purpose

This document describes the safe workflow for updating existing structured content on a page-like entity.

Use this document when:

- changing copy already rendered by a template
- loading arrays of items such as FAQs, steps, features, or cards
- updating nested structured content outside the admin UI

Do not use this document as the source of truth for the data shape itself. That lives in `docs/content-model.md`.

For multilingual content, consult `docs/multilanguage.md` before updating fields. The editorial schema defines which JSON paths are translatable, preserved, remapped, or localized media.

## Core Rule

Editable content lives in the database-backed structured payload, not in the template file.

If a template currently contains hardcoded user-facing copy, that is a defect to fix rather than a pattern to copy.

## Accepted Update Paths

- Use the admin UI when the existing form already supports the desired change.
- Use an application-level script or REPL session for bulk or deeply nested updates.

Avoid direct SQL updates against the JSON column unless there is a compelling operational reason and the team accepts the tradeoff.

## Workflow

### 1. Locate the target entity

Identify the entity by a stable key such as `slug` or `id`, then confirm its `template_key`.

Example:

```bash
php artisan tinker --execute="echo \App\Models\Page::where('slug','landing-page')->value('template_key');"
```

### 2. Inspect the rendered template contract

Open the template that corresponds to the entity's `template_key` and inspect:

- the section key being rendered
- the field names the template reads
- whether the template expects strings, arrays, HTML, booleans, or media IDs

Do not guess field names or shape.

### 3. Read the current stored payload

Inspect the current section before writing changes.

Example:

```bash
php artisan tinker --execute="\$page=\App\Models\Page::where('slug','landing-page')->first(); echo json_encode(\$page->content_json['sections']['faq'] ?? null, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);"
```

### 4. Decide the mutation type

Before writing, determine whether the change is:

- replace
- append
- merge

This matters because overwriting an entire nested section can accidentally remove sibling keys such as `heading`, `image_id`, or `is_visible`.

### 5. Apply the update through application code

Preferred pattern:

```php
$page = \App\Models\Page::where('slug', 'landing-page')->firstOrFail();
$content = $page->content_json ?? [];
$content['sections'] = $content['sections'] ?? [];
$faq = $content['sections']['faq'] ?? [];

$faq['items'] = [
    ['question' => 'Question A', 'answer' => 'Answer A'],
    ['question' => 'Question B', 'answer' => 'Answer B'],
];

$content['sections']['faq'] = $faq;
$page->content_json = $content;
$page->save();
```

Rules:

- read, mutate, then write back
- preserve sibling keys
- keep media references as IDs
- keep template files unchanged for content-only updates

### 6. Verify after saving

Re-read the saved payload and confirm the rendered output in the browser or preview.

## Media Notes

For structured content:

- store media references as identifiers such as `image_id`
- do not store file paths or external URLs for content-managed media

For featured images:

- use the shared media relationship instead of embedding them in the structured JSON payload

When a media field has no real asset yet, point it at the placeholder whose aspect ratio
matches the slot instead of leaving it empty: `horizontal` for heroes, featured and social
images, `square` for grid and avatar slots, `vertical` for portrait slots. Resolve it by
`placeholder_key`. See `docs/image-strategy.md`.

## Checklist

- [ ] Confirmed the target entity and template.
- [ ] Inspected the current template shape.
- [ ] Read the current stored payload.
- [ ] Decided whether the change is replace, append, or merge.
- [ ] Preserved sibling keys during the mutation.
- [ ] Verified both stored data and rendered output after saving.
