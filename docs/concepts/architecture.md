# Architecture

> What this starter is, the principles behind it, and how a request becomes a rendered page.

## What This Is

A small reusable CMS foundation: structured content, editable pages, media, menus, settings, taxonomies, public
forms and agent-facing integration points, on ordinary Laravel. Every client site forks this repo, so the core
stays generic and project-specific business logic stays in the fork.

## Principles

1. Keep the reusable core small and explicit. Prefer plain Laravel over custom framework machinery.
2. Editable content lives in the database. Templates only present it.
3. Shared systems — media, menus, settings, taxonomies, SEO, editor shell — instead of per-entity reinventions.
4. A change must be traceable from route to model to view without hidden runtime conventions.
5. One fact, one home. When two screens edit the same value, they write the same row.
6. Documentation is part of the contract: a rule that is not in `docs/` is not a rule.

## Registry

`config/cms.php` is where features are declared, and usually where a change starts:

| Key | Declares |
|---|---|
| `templates` | Page templates and the section keys each one owns ([../content/templates.md](../content/templates.md)) |
| `collections` | Public admin bar creation shortcuts: model, translated singular label and named create route ([../content/collections.md](../content/collections.md)) |
| `content_schemas` | Editorial schema for posts and taxonomies; pages carry theirs inside `templates.<key>.schema` ([multilanguage.md](multilanguage.md)) |
| `forms` | Public forms, their model, searchable columns and reCAPTCHA action ([../content/forms.md](../content/forms.md)) |
| `locales` | Installable locale catalog ([multilanguage.md](multilanguage.md)) |
| `menu_locations` | Theme locations menus can be assigned to ([../content/menus.md](../content/menus.md)) |
| `upload`, `image_optimization`, `responsive_images` | Media pipeline ([../content/images.md](../content/images.md)) |
| `rich_text` | TinyMCE presets ([../admin/rich-text.md](../admin/rich-text.md)) |
| `maps`, `mcp`, `snapshots`, `roles`, `statuses` | Map tiles, MCP surface, backups, role and status vocabularies |

## Shared Code Paths

- `app/Contracts/Editorial/` + `app/Traits/` — `Publishable`, `Seoable`, `Mediable`, `Previewable` and the traits
  that implement them ([editorial-contract.md](editorial-contract.md)).
- `app/Support/` — single read paths for cross-cutting data: `BusinessContact` (business phone, email, address,
  socials), `SocialNetworks` (catalog + icons), `LinkDestination` (semantic link targets), `LocalizedRoute`
  (locale-aware route names), `SiteUrl` (origin rewriting), `RichTextSanitizer`, `AdminLoginPath`.
- `app/Services/` — `Media`, `Content`, `Editorial`, `Forms`, `Revisions`, `Snapshots`.
- `app/Helpers/ContentHelper.php` — rendering helpers used by public views, including `richText()`.
- `resources/js/editor-engine.js` — the shared live editor runtime ([../admin/live-editor.md](../admin/live-editor.md)).

## Request Flows

**Public page.** `routes/web.php` → `public.locale` middleware resolves the locale → `PageController@show`
resolves a published page by slug → the page's `template_key` selects `resources/views/templates/{key}.blade.php`
→ the template reads `$page->sections()` and renders inside `layouts/public`.

The page route is a catch-all with a slug pattern that excludes the whole first segments `admin` and `mcp` and
installed locale codes. Page slugs are validated on save: lowercase hyphenated segments whose first segment is not
one the site uses (`AdminLoginPath::reservedRootSegments()` — `admin`, `mcp`, `blog`, `login`, `forms`,
`draft-preview`, `storage`… — nor the configured login path). **Every explicit public route must be registered
before the catch-all, and its first segment added to that list** — that is why blog and form routes appear earlier
in the file.

**Localized request.** The same flows under a `{locale}` prefix, whose names are the base name prefixed with
`localized.` (`localized.page.show`). Build URLs through `LocalizedRoute`, never by concatenating the locale.

**Admin.** `routes/admin.php` under auth + role middleware. Editing screens that need preview use the shared
editor shell and a `previewRender` POST route; everything else is a plain controller + Blade form.

**Integrations.** `routes/mcp.php` exposes the API-key-authenticated MCP surface
([../operations/mcp.md](../operations/mcp.md)).

## Where New Work Goes

| Adding | Goes in |
|---|---|
| A new page shape | A template in `config('cms.templates')` + a view + section partials |
| A repeatable entity | A collection: migration, model, admin CRUD, public routes ([../content/collections.md](../content/collections.md)) |
| A public form | Its own table, request, controller and registry entry ([../content/forms.md](../content/forms.md)) |
| A runtime-editable value | A setting in a group ([../admin/settings.md](../admin/settings.md)) |
| A build-time value | `config/cms.php` |
| Business data reused across the site | A `contact` setting read through `App\Support\BusinessContact` |

## Admin sign-in identities

Accounts may set an optional, unique `username` from Profile or the administrator-only Users screens.
Usernames are normalized to lowercase and contain 3–32 ASCII letters, digits, periods, hyphens or
underscores; `@` is excluded so email and username identities cannot overlap. Existing accounts keep
working with their email and no username is guessed during migration.

The login form accepts username or email in the same `login` field; legacy `email` submissions remain
compatible. Both aliases share the same per-account/IP attempt limit. Passwords, remember-me and
email-based password recovery keep their existing behaviour.
