---
name: review-conventions
description: Reviews changed code against the documented contracts of ruweb-cms — forms, templates and sections, media handling, live editor UI/UX, settings and translations — and reports violations with the rule each one breaks. Use before committing or opening a PR, or when the user asks whether a change respects the docs ("revisá que esto cumpla la documentación", "does this follow the conventions?").
---

# Review Against The Docs

Reports findings; it does not rewrite code unless the user asks.

## Profile First

Run `php artisan cms:capabilities` and read
[docs/concepts/admin-profiles.md](../../../docs/concepts/admin-profiles.md) before choosing deliverables.
`full` requires specialized editors for custom structured fields. In `essential`, omit specialized Live
Editor partials/forms by default; preserve schemas, renderers, validation/operations, permissions, media,
publication and tests. Read Live Editor UI/UX only when creating or changing a visual editor. Verify public
rendering and operation through shared operations/MCP in essential. Both profiles expose the same MCP tools;
role/scope permissions are independent. Essential remains experimental until phase 4.

## 1. Deterministic Pass

```bash
php artisan cms:check-conventions --since=main   # or --staged, or no flag for the whole repo
```

Every violation it prints is a confirmed finding: route throttling, anti-spam components, form admin partials,
translation parity, registered templates without a view, hardcoded images in templates, absolute URLs in
seeders, `VERSION` drift.

## 2. Judgment Pass

Read the diff (`git diff --stat` then the files), map each changed path to its contract, and **load only those
docs**:

| Changed | Contract to load |
|---|---|
| `resources/views/templates/*`, `config('cms.templates')` | [docs/content/templates.md](../../../docs/content/templates.md) + [docs/content/images.md](../../../docs/content/images.md) |
| `resources/views/admin/pages/sections/*`, repeaters, editor partials | [docs/admin/live-editor-ui-ux.md](../../../docs/admin/live-editor-ui-ux.md) — Acceptance Checklist |
| `app/Http/Requests/Public/*`, form routes, `config('cms.forms')`, submissions views | [docs/content/forms.md](../../../docs/content/forms.md) |
| Media, placeholders, image fields | [docs/content/images.md](../../../docs/content/images.md) |
| `lang/*`, admin Blade or JS strings | [docs/admin/translations.md](../../../docs/admin/translations.md) |
| Settings, seeders, `PersistsSettings` | [docs/admin/settings.md](../../../docs/admin/settings.md) |
| Header, footer, contact data | [docs/admin/layout.md](../../../docs/admin/layout.md) |
| A new collection or model | [docs/content/collections.md](../../../docs/content/collections.md) + [docs/concepts/editorial-contract.md](../../../docs/concepts/editorial-contract.md) |
| Locales, schema paths, translations of content | [docs/concepts/multilanguage.md](../../../docs/concepts/multilanguage.md) |

Highest-value checks, in order:

1. **Editable copy hardcoded in a template**, or a media path instead of a media id.
2. **A form whose structure leaked into the Live Editor** — field lists, validation, destinations exposed as
   editable — or an editor section linking to the dashboard instead of its submissions listing.
3. **Repeaters**: fixed vs administrable, delete confirmation, delete visible in the collapsed header, add
   button at the end, single-field items as flat rows, reordering when order matters.
4. **Sections**: one accordion per section, visibility switch independent from the trigger, collapsed by
   default.
5. **Collection-owned data copied into a page section** instead of referenced.
6. **Schema paths** missing or wrongly flagged for a new editable field.
7. **Placeholders** missing on new image slots in seeded or starter content.
8. **Settings duplicated** for a fact that already has a row.

## 3. Report

One list, most severe first. Per finding: `file:line`, what breaks, and the doc rule it violates (name the
section, e.g. *live-editor-ui-ux.md — Acceptance Checklist → Sections And Repeaters*). Say explicitly when a
dimension was clean. Do not invent findings to fill the list.
