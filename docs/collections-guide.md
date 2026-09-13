# Collections Guide

## Purpose

This document defines the contract for adding a new collection-type content entity.

Use this document for:

- mandatory cross-cutting rules
- shared model/controller/view expectations
- the minimum checklist for a new collection

Do not use this document as a substitute for:

- `docs/editorial-contract.md` for shared publish, SEO, media, and preview contracts
- `docs/taxonomies.md` for taxonomy internals
- `docs/live-editor.md` for editor mechanics
- a collection-specific example document when the repository ships one

## What A Collection Is

A collection is a repeatable content entity with its own admin CRUD, public routes, and optional taxonomy/media relationships.

Examples:

- posts
- products
- case studies
- events

## Mandatory Rules

### 1. Content-managed media must use the shared media system

Any collection that renders images or files should use the shared media relationship rather than storing raw file paths in columns or JSON.

Seed example records with the placeholder image whose aspect ratio matches how the collection renders it: `horizontal` for wide cards and cover images, `square` for grid or avatar cells, `vertical` for portrait cards. Resolve it by `placeholder_key` rather than by filename or id. See `docs/image-strategy.md`.

### 2. Classification should use the shared taxonomy system

If the collection needs categories, tags, regions, or similar labels, use the shared taxonomy subsystem instead of creating one-off taxonomy tables per collection.

### 3. Public visibility should follow a publish contract

Collections with public output should have a publication state such as:

- `status`
- `published_at`

Public queries should filter through a published scope or equivalent rule.

When using the standard `status` and `published_at` fields, implement the shared `Publishable` contract and use the shared publication trait.

### 4. Public SEO data should follow the shared metadata contract

If collection entries render public pages, use a metadata shape compatible with the page/entity SEO helpers used by the project.

Public detail entries should implement the shared `Seoable` contract while keeping their own storage shape.

### 5. The edit experience should use the shared editor where preview is needed

If editors benefit from live preview, the collection edit screen should use the shared editor shell and preview contract rather than inventing a separate editing model.

When a collection entry can be opened in the live editor from more than one origin, preserve that origin explicitly:

- if the editor was opened from the admin index, the back action should return to that collection index
- if the editor was opened from the public admin bar, the back action should return to the exact public URL that launched the editor

Do not hardcode the editor back link to the admin collection index in every case.

The public admin bar must expose a single contextual edit action for the current entity. Resolve its label and destination from the current content type, for example:

- `Editar post`
- `Editar servicio`
- `Editar caso de estudio`

Do not render a generic page edit action on collection detail routes unless the current entity is actually a page.

### 6. Authorization rules should be explicit

Every collection should document who can:

- view
- create
- update
- publish
- delete

### 7. Admin index tables must expose standard row actions

When a collection has an admin list table, each row should include an actions column with:

- an open/view link when the entry has a public detail page
- an edit button
- a delete button with confirmation

These actions should follow the existing admin icon treatment and remain vertically aligned within the row.

## Recommended Structure

Typical pieces of a collection:

- migration
- model
- form requests
- admin controller
- admin views
- policy
- public controller or route handlers
- tests
- optional collection-specific documentation

## Implementation Checklist

1. Create the migration and model.
2. Add publication fields and a published query scope if the collection is public.
3. Attach shared media behavior if the collection has images or files.
4. Attach shared taxonomy behavior if the collection needs classification.
5. Add form requests for create and update validation.
6. Add admin CRUD routes, controller actions, and views.
7. Add public routes before any page catch-all route.
8. Add authorization rules or a policy.
9. Ensure the admin index table exposes the standard row actions: open if detail exists, edit, and delete with confirmation.
10. Add tests for publishing, visibility, and authorization boundaries.
11. Add a collection-specific example document only if the collection introduces behavior not already covered elsewhere.

## What Should Not Live Here

This document should not contain:

- a full walkthrough for one named collection
- repeated editor JavaScript
- repeated taxonomy implementation details
- host-project marketing or business examples
