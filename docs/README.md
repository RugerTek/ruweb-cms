# Docs Map

Every doc owns one decision. Open the one that matches the task; the rest stay out of context.
Repo-wide rules and the task → skill table live in [../AGENTS.md](../AGENTS.md).

## Concepts

| Doc | Owns |
|---|---|
| [concepts/admin-profiles.md](concepts/admin-profiles.md) | Profile discovery, delivery requirements, essential operational panel and transitions |
| [concepts/architecture.md](concepts/architecture.md) | What this starter is, its principles, the registry in `config/cms.php`, request flows, where new work goes |
| [concepts/content-model.md](concepts/content-model.md) | Shape of `content_json`: `meta`, `sections`, `is_visible`, media ids |
| [concepts/editorial-contract.md](concepts/editorial-contract.md) | `Publishable`, `Seoable`, `Mediable`, `Previewable` and which entity implements what |
| [concepts/multilanguage.md](concepts/multilanguage.md) | Locales, URL strategy, translation groups, editorial schema, fingerprints, localized settings, translation workflow |

## Content

| Doc | Owns |
|---|---|
| [content/pages.md](content/pages.md) | The page entity: routing, publishing, page rules |
| [content/templates.md](content/templates.md) | Registering templates and sections, template views, editor partials |
| [content/composition.md](content/composition.md) | Safe HTML/canvas, typed blocks, registered components and precompiled styles |
| [content/updating-content.md](content/updating-content.md) | Changing stored content without losing sibling keys |
| [content/collections.md](content/collections.md) | Contract for a new collection type |
| [content/blog-example.md](content/blog-example.md) | The shipped blog, as a worked example only |
| [content/taxonomies.md](content/taxonomies.md) | Taxonomy terms, the `taxables` pivot, adding a type |
| [content/menus.md](content/menus.md) | Menus, locations, rendering, localized menus |
| [content/forms.md](content/forms.md) | Public forms: contract, anti-spam, submissions, admin, checklist |
| [content/images.md](content/images.md) | Placeholders, upload pipeline, variants, rendering contract |
| [content/seo.md](content/seo.md) | Sitemap, metadata, robots, global integrations |

## Admin

| Doc | Owns |
|---|---|
| [admin/editorial-workflow.md](admin/editorial-workflow.md) | Full versions, recovery drafts, reviews, calendar, shared preview links and granular source review |
| [admin/live-editor.md](admin/live-editor.md) | Editor shell, `initEditorEngine`, DOM and backend contract |
| [admin/live-editor-ui-ux.md](admin/live-editor-ui-ux.md) | What the client may edit, how it must look and behave, acceptance checklist |
| [admin/account-security.md](admin/account-security.md) | Profile identity verification, password recovery, two-factor authentication, session and connection revocation |
| [admin/settings.md](admin/settings.md) | Settings model, types, groups, adding a setting |
| [admin/layout.md](admin/layout.md) | Header, footer, business contact data, ownership map |
| [admin/rich-text.md](admin/rich-text.md) | TinyMCE presets, sanitisation, public rendering |
| [admin/translations.md](admin/translations.md) | `lang/` conventions and the parity rule |

## Operations

| Doc | Owns |
|---|---|
| [operations/snapshots.md](operations/snapshots.md) | `.appbackup` format, risks, restore, housekeeping |
| [operations/maintenance.md](operations/maintenance.md) | Operational commands |
| [operations/redirects.md](operations/redirects.md) | 301/302 rules for old paths, the 404 monitor, CSV import |
| [operations/custom-code.md](operations/custom-code.md) | Trusted browser snippets, exact destinations, dedicated permissions, activation and recovery |
| [operations/mcp.md](operations/mcp.md) | MCP auth, protocol, tools, REST, extension points |
| [operations/deploy-cpanel.md](operations/deploy-cpanel.md) | cPanel deployment |
| [operations/deploy-droplet.md](operations/deploy-droplet.md) | Single-server deployment |
| [operations/git.md](operations/git.md) | Starting a project from the starter, git hooks |

## Skills

Workflows live in `.agents/skills/` (Codex) and are symlinked into `.claude/skills/` (Claude Code), so both
agents auto-trigger them from their description: `create-page`, `create-template`, `create-section`,
`create-collection`, `create-form`, `create-config`, `create-layout-field`, `update-content`,
`translate-content`, `check-translations`, `review-conventions`, `changelog-version`.
