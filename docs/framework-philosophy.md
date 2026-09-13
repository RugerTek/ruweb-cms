# Framework Philosophy

## Purpose

This document is the canonical philosophy record for the starter.

It explains the principles that should guide implementation decisions. It should not describe exact routes, framework versions, migration plans, or feature status. Those details belong in the topic-specific reference docs.

## Product Shape

The starter is a small, reusable CMS foundation for projects that need structured content, editable pages, media management, menus, settings, and agent-friendly integration points without adopting a large opinionated CMS.

The framework should stay understandable enough that a developer or agent can trace a content change from request, to model, to view, to database without discovering hidden runtime conventions.

## Guiding Principles

- Keep the reusable core small and explicit.
- Prefer ordinary Laravel conventions over custom framework machinery.
- Store editable content in the database, not in templates.
- Keep templates focused on presentation.
- Use shared systems for media, menus, settings, taxonomies, SEO, and editor behavior.
- Make extension paths clear enough for humans and agents to follow safely.
- Treat documentation as part of the framework contract.

## Content Philosophy

Content should be structured enough to be edited safely and rendered predictably, but not so abstract that each project has to fight the framework.

Pages and collections may use different first-class fields, but shared concerns should use shared contracts:

- publication state for public visibility
- media relationships for content-managed files, with starter content filling image slots
  from the three placeholders rather than rendering empty frames
- metadata shapes compatible with the SEO layer
- taxonomy relationships for classification
- menus for navigation instead of hardcoded links

## Editor Philosophy

Editorial interfaces should reuse the same shell, preview behavior, media picker, status model, and save semantics where possible.

New content types may have custom fields, but they should not invent parallel editing systems unless the shared editor is technically insufficient for the workflow.

## Integration Philosophy

Agent and API access should be explicit, discoverable, authenticated, and authorized through the same business rules used by the admin surface.

Integrations should expose durable operations over content and settings, not fragile assumptions about internal forms or one project's theme.

## Operational Philosophy

The starter should be deployable on simple infrastructure first: a conventional Laravel app, a relational database, local or configured storage, and ordinary queue/cache/session primitives.

Deployment guides should document operational requirements. They should not redefine the architecture.

## Documentation Boundaries

Use this document for principles.

Use `docs/architecture.md` for system structure and request flows.

Use the feature guides for implementation contracts:

- `docs/content-model.md`
- `docs/editorial-contract.md`
- `docs/pages-guide.md`
- `docs/templates.md`
- `docs/live-editor.md`
- `docs/collections-guide.md`
- `docs/settings.md`
- `docs/mcp.md`
