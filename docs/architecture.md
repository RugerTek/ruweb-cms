# Architecture

## Purpose

This document describes the starter at a system level: major folders, request flow, and architectural boundaries.

It should stay intentionally high-level. Detailed behavior belongs in the topic-specific guides.

## Typical Project Structure

```text
app/
config/
database/
public/
resources/
routes/
tests/
```

Typical responsibilities:

- `app/` — domain models, controllers, requests, policies, traits, services
- `config/` — framework and starter configuration
- `database/` — migrations, seeders, factories
- `resources/` — Blade views and frontend assets
- `lang/` — translation files
- `routes/` — public, admin, and integration routes
- `tests/` — feature and unit coverage

## Request Flows

### Public Page Request

1. The request hits a public route.
2. The application resolves the requested entity, usually by slug.
3. The entity selects a template or view.
4. The server renders the public layout with shared settings, SEO helpers, and content data.

### Public Collection Request

1. The request hits an explicit collection route.
2. The controller queries published collection entries.
3. The server renders list or detail views using the shared public layout.

### Admin Request

1. The request hits an admin-prefixed route group.
2. Authentication and authorization middleware run first.
3. The controller returns an admin layout or JSON response.
4. Editing flows may use the shared live editor when preview is required.

## Core Patterns

- Structured content is stored separately from template code.
- Content-managed media goes through the shared media subsystem, including the three starter placeholder images a fresh install seeds for empty image slots (see `docs/image-strategy.md`).
- Collections reuse shared concerns such as publication state, taxonomies, and SEO metadata.
- Views stay focused on presentation; validation and persistence stay in request classes, models, and controllers.
- Operational integrations such as MCP or deployment workflows are documented separately from the core architecture.

## Scope Boundary

This document should not become:

- a deployment guide
- a per-entity rulebook
- a product roadmap
- a file-by-file inventory of one specific project instance
