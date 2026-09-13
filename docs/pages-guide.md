# Pages Guide

## Purpose

This document defines the behavior and rules of the page entity.

Use this document for:

- what a page is
- routing and publishing behavior
- page-specific media rules
- page-specific editor expectations

Do not use this document as the canonical source for content JSON shape, template registration, or low-level editor internals. Those topics live in `docs/content-model.md`, `docs/templates.md`, and `docs/live-editor.md`.

For shared editorial capabilities such as publishing, SEO, media, and preview contracts, use `docs/editorial-contract.md`.

## What A Page Is

A page is a URL-addressable content entity with:

- a unique `slug`
- a `template_key`
- a structured content JSON column
- publication state such as `status` and `published_at`
- an optional featured image stored through the shared media system

Pages are typically used for top-level or static-like URLs such as:

- `/about`
- `/services`
- `/contact`

## Routing Contract

Pages are usually resolved through a catch-all public route. Because of that:

- all explicit collection routes should be registered before the catch-all page route
- public page resolution should query published pages only

## Mandatory Rules

### 1. Editable content must not be hardcoded in the template

Templates are presentation only. User-facing copy belongs in the database-backed content structure, not in Blade literals.

### 2. Content-managed images must use the media library

For page content:

- featured images belong to the shared media relationship
- section-level images should store a media identifier such as `image_id`
- do not store content-managed images as hardcoded paths or raw URLs
- starter and example pages should point every media field at the placeholder whose
  aspect ratio matches the slot: `horizontal` for featured and hero images, `square` for
  grid or avatar slots, `vertical` for portrait slots. See `docs/image-strategy.md`.

### 3. Featured image is separate from structured content

The featured image should be attached through the media system using the page's designated collection key.

It should not be embedded inside the structured content JSON payload.

If a page needs a social sharing image that differs from the featured image, use the structured metadata social image override documented in `docs/content-model.md`.

### 4. Templates must honor section visibility

When a page template renders structured sections, it must respect `is_visible` and avoid rendering empty sections.

### 5. Page content should follow the shared structured content contract

The page content payload should use the same canonical `meta` and `sections` top-level keys defined in `docs/content-model.md`.

### 6. The page edit view should use the shared live editor

The edit experience for an existing page should use the shared editor shell, preview route, and async save contract documented in `docs/live-editor.md`.

Creation screens may use a simpler admin form when no persisted model exists yet.

## Page Creation Checklist

1. Define or choose the `slug`.
2. Select or register the `template_key`.
3. Ensure the template's section keys match the expected structured content shape.
4. Add or attach any required media fields through the media system.
5. Verify preview and publish behavior in the editor.
6. Confirm the page resolves correctly on the public route.

## Page Update Checklist

1. Identify the page by slug.
2. Inspect the selected template before changing structured content.
3. Update content through the admin editor or a controlled data script.
4. Preserve existing sibling keys when mutating nested section payloads.
5. Verify the rendered page after saving.

## SEO Notes

Page SEO values should come from the shared `meta` block plus global fallbacks. Keep the page entity focused on declaring content; leave the exact fallback chain to the SEO helper/component implementation.

Pages implement the shared `Seoable` contract by reading metadata from `content_json.meta` and using the page title as the title fallback.
