---
name: create-collection
description: Adds a new collection content type to ruweb-cms — migration, model with the editorial contracts, shared operations, admin CRUD, MCP tools, taxonomies, public routes and tests. Use when the user wants a repeatable entity such as products, services, case studies, events or testimonials ("necesito una colección de proyectos", "add a testimonials collection").
---

# Create A Collection

Read [docs/content/collections.md](../../../docs/content/collections.md) and
[docs/concepts/editorial-contract.md](../../../docs/concepts/editorial-contract.md).
[docs/content/blog-example.md](../../../docs/content/blog-example.md) is the worked example — copy its shape,
not its rules. Add [docs/content/taxonomies.md](../../../docs/content/taxonomies.md) when it needs
classification and [docs/content/seo.md](../../../docs/content/seo.md) when it is public.

## Profile First

Run `php artisan cms:capabilities` and read
[docs/concepts/admin-profiles.md](../../../docs/concepts/admin-profiles.md) before choosing deliverables.
`full` requires specialized editors for custom structured fields. In `essential`, omit specialized Live
Editor partials/forms by default; preserve schemas, renderers, validation/operations, permissions, media,
publication and tests. Read Live Editor UI/UX only when creating or changing a visual editor. Verify public
rendering and operation through shared operations/MCP in essential. Both profiles expose the same MCP tools;
role/scope permissions are independent. In `essential` the operational list cannot create or edit, so the
collection's MCP tools are its only editor.

## Steps

1. **Spec**: fields, public or admin-only, taxonomies, publication rules, permissions, preview.
2. **Migration + model** with explicit columns — never a generic content blob. Add `status` and `published_at`
   when it is public, then `Publishable` + `HasPublicationState`.
3. **Contracts**: `Seoable` for public detail pages, `HasMedia` + `Mediable` for files, `Previewable` only if
   the edit screen previews.
4. **Taxonomies** through `HasTaxonomies` and `syncTaxonomies($ids, $type)` — validating that the ids belong
   to that type before syncing.
5. **Full editorial CRUD; both profiles operational listing**: form requests, controller, views, and an index table whose rows expose open (only when
   there is a public detail page), edit, and delete with confirmation, aligned with the existing icon pattern.
6. **Public routes registered before the page catch-all**, querying `published()` only.
7. **Policy** covering view, create, update, publish, delete.
7a. **Shared `<Name>Operations`** (save with `draftOnly`/`patch`, publish, delete returning `ContentDeletion`
   warnings), called by the admin controller — never business logic only in the controller.
7b. **MCP surface** — required in both profiles: `mcp_operations` in `cms.collections`, the seven
   `<plural>_list/_get/_create_draft/_update_draft/_update/_publish/_delete` tools, handlers, scope
   classification, REST mirror, the type lists for history/translation/preview, `MediaUsage` and menu link types.
   Follow [docs/operations/mcp-surface.md](../../../docs/operations/mcp-surface.md#a-new-collection) step by step.
8. **Sitemap + metadata** when public.
9. **Full only: editor origin**: if entries open from both the admin index and the public admin bar, carry the return
   target; the public admin bar shows one contextual action named after the type.
10. **Seed** example records with the placeholder matching how they render.
11. **Tests** for publishing, public visibility and authorization boundaries, plus an MCP test (create, patch,
   publish, stale revision, delete, scope refusal). `McpSurfaceContractTest` must stay green.
12. **Docs**: add the tools to the table in [docs/operations/mcp.md](../../../docs/operations/mcp.md).

## Never

- A one-off taxonomy table for one collection.
- Project-specific business logic in the reusable core.
- A parallel editing flow when the shared editor fits.
