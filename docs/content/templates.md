# Templates And Sections

> How a page template is registered, rendered and edited. Data shape lives in
> [../concepts/content-model.md](../concepts/content-model.md); editor behaviour in
> [../admin/live-editor-ui-ux.md](../admin/live-editor-ui-ux.md).

## Profile first

Run `php artisan cms:capabilities`; see [../concepts/admin-profiles.md](../concepts/admin-profiles.md).
Schema, renderer, validation, permissions, publication and media contracts apply in both profiles.
Specialized section partials and their UI/UX contract are required only in `full`. In `essential`, omit them
by default and verify data operations plus public rendering. Existing partials may remain for returning to
full. `cms:check-conventions` enforces missing custom-field editors only in full.

## Registration

Every template is one entry in `config('cms.templates')`:

```php
'landing' => [
    'name' => 'Landing',                                  // label in the admin
    'sections' => ['hero', 'benefits', 'cta'],            // section keys this template owns
    'schema' => [                                         // every editable path, flagged
        'meta.title' => ['type' => 'text', 'translatable' => true],
        'meta.description' => ['type' => 'textarea', 'translatable' => true],
        'meta.featured_image' => ['type' => 'media', 'localized_media' => true],
        'sections.hero.is_visible' => ['type' => 'boolean', 'preserve' => true],
        'sections.hero.heading' => ['type' => 'text', 'translatable' => true],
        'sections.hero.image_id' => ['type' => 'media', 'localized_media' => true],
        'sections.benefits.items.*.title' => ['type' => 'text', 'translatable' => true],
        'sections.cta.button_url' => ['type' => 'url', 'preserve' => true],
    ],
],
```

`sections` is the contract between config, the editor form and the rendered view. `schema` is what makes the
template translation-ready — a template without a complete schema cannot create translations
([../concepts/multilanguage.md](../concepts/multilanguage.md)). Repeaters use `*`: `items.*.title`.

Shipped templates: `default`, `home`, `home-alt`, `contact`.

## The View

`resources/views/templates/{key}.blade.php`:

```blade
@extends('layouts.public')

@section('content')
@php
    $sections = $page->sections();
    $hero = $sections['hero'] ?? [];
    $heroImage = isset($hero['image_id']) ? \App\Models\Media::find((int) $hero['image_id']) : null;
@endphp

@if(($hero['is_visible'] ?? 1) && ($hero['heading'] ?? null))
    <section>
        <h1>{{ $hero['heading'] }}</h1>
        @if($heroImage)
            <x-responsive-img :media="$heroImage" sizes="100vw" alt="" class="h-full w-full object-cover" />
        @endif
    </section>
@endif
@endsection
```

Available inputs: `$page->title`, `$page->meta()`, `$page->sections()`, `$page->featuredImage()`,
`$page->url()`.

Rules:

- Read from structured content and first-class columns only — **never hardcode editable copy**.
- Never hardcode a media URL or a local asset path for a content-managed image; render with
  `<x-responsive-img>` ([images.md](images.md)).
- Guard every section with `is_visible` **and** a meaningful content check (missing `is_visible` means visible).
- Extra section-specific fields are fine; declare them in `schema`.
- Seed every media slot the template exposes with the matching placeholder, so a fresh install renders complete.

If `template_key` has no matching view, the page falls back to `templates.default`. The fallback is silent — it
does not log.

## Section Editor Partials

The page form looks for `resources/views/admin/pages/sections/{template_key}_{section_key}.blade.php`. Without
one it falls back to generic `heading` + `body` inputs, so a section only needs a partial when it has more
fields than that.

Templates sharing a section key may reuse the same partial, which makes switching templates safe. Partials are
UI, not part of the rendering contract.

## Adding A Section To An Existing Template

1. Add the key to `sections`.
2. Add its paths to `schema` with the right flags.
3. Render it in the template view, guarded by `is_visible`.
4. In `full`, add the editor partial when it needs more than heading + body, following
   [../admin/live-editor-ui-ux.md](../admin/live-editor-ui-ux.md) (one accordion per section, repeater markup,
   visibility control).
5. Seed placeholder media for any image field.
6. Add `admin.*` translations for new labels in `es` and `en` ([../admin/translations.md](../admin/translations.md)).
7. Existing pages keep working: a section missing from `content_json` renders as empty and must not fatal.
