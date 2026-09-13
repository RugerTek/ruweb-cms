# Documentation Index

This index is the entry point for developers and LLM agents working on the starter.

## Canonical Docs

- `docs/framework-philosophy.md` — principles and boundaries that guide framework decisions
- `docs/architecture.md` — high-level structure, request flow, and system boundaries
- `docs/content-model.md` — canonical shape of structured content blocks
- `docs/multilanguage.md` — public multilingual content, locale routing, editorial schema, and translation contract
- `docs/editorial-contract.md` — shared contracts for publishable, SEO, media, and preview behavior
- `docs/templates.md` — how template registration and rendering work
- `docs/live-editor.md` — shared editor shell and preview contract
- `docs/rich-text.md` — TinyMCE integration, presets, sanitisation, and public rendering
- `docs/image-strategy.md` — upload, optimization, placeholder images, and frontend delivery rules
- `docs/settings.md` — global settings model, grouping, and runtime access
- `docs/admin-translations-guide.md` — admin-only translation conventions and checks

## Content Types

- `docs/pages-guide.md` — rules specific to the page entity
- `docs/update-fields.md` — safe workflow for updating existing structured content
- `docs/collections-guide.md` — contract for adding a new collection type
- `docs/blog-collection.md` — optional example collection implementation
- `docs/taxonomies.md` — shared taxonomy system used by collections
- `docs/menu-guide.md` — navigational menus, locations, and rendering
- `docs/forms.md` — code-owned public forms, anti-spam layer, submissions admin, and the checklist for new forms

## Integrations And Operations

- `docs/mcp.md` — MCP authentication, endpoints, and extension points
- `docs/snapshots.md` — `.appbackup` format, inspection risks, restore flow, and housekeeping
- `docs/maintenance.md` — operational commands and maintenance workflows
- `docs/deploy-cpanel.md` — generic cPanel deployment guide
- `docs/deploy-droplet.md` — generic single-server droplet deployment guide
- `docs/git-guide.md` — creating an independent repository from the starter

## Packaged Skills

- `docs/skills/create-page.md` — reusable prompt for creating or extending pages using the canonical docs
- `docs/skills/create-collection.md` — reusable prompt for creating or extending collections using the canonical docs
- `docs/skills/create-config.md` — reusable prompt for configuration and settings changes using the canonical docs
- `docs/skills/create-form.md` — reusable prompt for adding a public form with submissions using the canonical docs

## Suggested Reading Order

1. Read `docs/framework-philosophy.md`.
2. Read `docs/architecture.md`.
3. Read `docs/content-model.md`, `docs/editorial-contract.md`, `docs/templates.md`, and `docs/live-editor.md`.
4. If working with pages, read `docs/pages-guide.md`.
5. If updating existing structured content, read `docs/update-fields.md`.
6. If adding a collection, read `docs/collections-guide.md` and `docs/taxonomies.md`.
7. Use `docs/blog-collection.md` only as an implementation example, not as the source of truth for collection rules.
8. If adding a public form, read `docs/forms.md` and the "Forms Are Code-Owned" section of `docs/live-editor-ui-ux.md`.
