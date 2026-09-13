# Templates

## Purpose

This document explains how templates are registered, resolved, and rendered.

Use this document for:

- template registration
- section declarations per template
- template view conventions
- fallback behavior

Do not use this document as the source of truth for JSON data shape or editor behavior. Those topics live in `docs/content-model.md` and `docs/live-editor.md`.

## How Template Resolution Works

Each page-like entity stores a `template_key`. That key maps to a server-rendered view such as:

```text
resources/views/templates/{key}.blade.php
```

The template is responsible for presentation only. It reads structured content and decides how to render it.

## Registering A Template

Register templates in configuration:

```php
'templates' => [
    'default' => [
        'name' => 'Default',
        'sections' => ['hero', 'body'],
    ],
    'landing' => [
        'name' => 'Landing',
        'sections' => ['hero', 'benefits', 'testimonials', 'cta'],
    ],
],
```

The `sections` array declares which section keys belong to that template. It is a contract between:

- configuration
- the editor form
- the rendered template

## Creating The View

Create the corresponding template file:

```blade
{{-- resources/views/templates/landing.blade.php --}}
@extends('layouts.public')

@section('content')
@php
    $sections = $page->sections();
    $hero = $sections['hero'] ?? [];
    $benefits = $sections['benefits'] ?? [];
@endphp

@if(($hero['is_visible'] ?? 1) && ($hero['heading'] ?? null))
    <section>
        <h1>{{ $hero['heading'] }}</h1>
        @if($hero['body'] ?? null)
            <p>{{ $hero['body'] }}</p>
        @endif
    </section>
@endif
@endsection
```

## Template Conventions

- Templates should read data from structured content and first-class entity fields only.
- Templates should not hardcode editable copy.
- Templates should not hardcode media URLs or local asset paths for content-managed images.
- Every media field a starter template exposes should be seeded with the placeholder matching its rendered aspect ratio, so a fresh install renders a complete page. See `docs/image-strategy.md`.
- Templates should guard section output with `is_visible` and meaningful content checks.
- Templates may define additional section-specific fields beyond `heading` and `body`.

## Editor Relationship

The editor uses the registered `sections` array to know which section blocks to expose for a given template.

If multiple templates share the same section keys, the editor may reuse the same input block for those keys. This allows safer template switching without duplicating data entry.

Template-specific editor partials, when used, should be treated as UI concerns rather than part of the template rendering contract.

The page form looks for section editor partials using this convention:

```text
resources/views/admin/pages/sections/{template_key}_{section_key}.blade.php
```

If no matching partial exists, the form falls back to generic `heading` and `body` fields for that section.

## Data Available In The View

Typical template inputs:

- `$page->title`
- `$page->meta()`
- `$page->sections()`
- `$page->featuredImage()`
- `$page->url()`

Projects may expose equivalent helpers on other entities.

## Fallback Behavior

If an entity references a `template_key` with no matching view, the system should fall back to a safe default template and log or surface the mismatch during development.

The current page model falls back to `templates.default`. It does not log that mismatch by itself.
