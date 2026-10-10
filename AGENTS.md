# ruweb-cms

LLM-first CMS built on Laravel 13 / PHP 8.3, Tailwind 4 + Vite, MySQL. It is a **starter**: every site forks
this repo, so code that only makes sense for one client does not belong here.

Docs are written for agents. Read the one the routing table points at; do not read `docs/` top to bottom.

## Commands

```bash
composer run dev          # server + queue + logs + vite
composer test             # full suite (php artisan test)
php artisan test --filter=ContactFormTest
php artisan cms:install   # DB, migrations, admin user, storage:link, placeholders
php artisan cms:check-conventions   # doc rules this repo enforces; --staged for the index
```

App at `http://localhost:8000`, admin at `/admin`. The login path is the `admin_login_path` setting,
`login` by default, resolved through `App\Support\AdminLoginPath`.

## Routing Table

| Task | Skill | Doc |
|---|---|---|
| New public page | `create-page` | [docs/content/pages.md](docs/content/pages.md) |
| New template | `create-template` | [docs/content/templates.md](docs/content/templates.md) |
| New section in an existing template | `create-section` | [docs/content/templates.md](docs/content/templates.md) |
| Edit content already stored | `update-content` | [docs/content/updating-content.md](docs/content/updating-content.md) |
| New collection (products, cases…) | `create-collection` | [docs/content/collections.md](docs/content/collections.md) |
| New public form | `create-form` | [docs/content/forms.md](docs/content/forms.md) |
| Header, footer, business contact data | `create-layout-field` | [docs/admin/layout.md](docs/admin/layout.md) |
| Trusted browser HTML/CSS/JavaScript snippets | `create-config` | [docs/operations/custom-code.md](docs/operations/custom-code.md) |
| New setting or config value | `create-config` | [docs/admin/settings.md](docs/admin/settings.md) |
| Translate content to another locale | `translate-content` | [docs/concepts/multilanguage.md](docs/concepts/multilanguage.md) |
| Admin labels, `lang/` parity | `check-translations` | [docs/admin/translations.md](docs/admin/translations.md) |
| Redirect an old URL, review 404s (Developer tools) | — | [docs/operations/redirects.md](docs/operations/redirects.md) |
| Review a change against the docs | `review-conventions` | [docs/README.md](docs/README.md) |
| Changelog and `VERSION` | `changelog-version` | — |

Everything else: [docs/README.md](docs/README.md) maps every doc to the decision it owns.

## Deployment Profile

Before creating pages, templates, sections or collections, run `php artisan cms:capabilities`.
The versioned `config/cms.php` key `admin_profile` is `full` (default) or `essential`; never store it as a
setting or add an environment override. Read [docs/concepts/admin-profiles.md](docs/concepts/admin-profiles.md).
Both profiles require data/schema, renderer, operations/validation, permissions, media ids, publication and
appropriate tests. Only `full` requires specialized visual editors. In `essential`, do not build a Live
Editor partial by default. MCP has the same tools in both; roles and credential scopes remain independent.
Essential’s operational panel passed phase 4; later capabilities retain their own roadmap gates. Verify the destination profile before
deployment; switching profiles regenerates configuration/routes caches and restarts persistent workers,
without converting or mutating content.

## Hard Rules

1. **Editable copy lives in the database, never in Blade.** A user-facing string hardcoded in a template is a
   defect, not a shortcut. [docs/concepts/content-model.md](docs/concepts/content-model.md)
2. **Content-managed media is referenced by media id**, never by path or URL, and every image slot in starter
   content gets the placeholder matching its aspect ratio. [docs/content/images.md](docs/content/images.md)
3. **Never store an absolute URL of this site in content.** Ids, or root-relative paths; absolute URLs are built
   at render time. Content moves between domains. [docs/operations/snapshots.md](docs/operations/snapshots.md)
4. **Forms are code.** Fields, validation, storage and destinations live in the repo. The Live Editor edits
   labels, options and notification settings — nothing else. [docs/content/forms.md](docs/content/forms.md)
5. **Every public form carries the full anti-spam layer**: CSRF, honeypot, `throttle:forms`, reCAPTCHA v3
   component. None is optional.
6. **`lang/es/*.php` and `lang/en/*.php` stay key-for-key identical**, and no user-facing string is hardcoded in
   an admin Blade. [docs/admin/translations.md](docs/admin/translations.md)
7. **One fact, one row.** The same value edited from two screens writes the same setting; never duplicate a
   setting to serve a second screen. [docs/admin/settings.md](docs/admin/settings.md)
8. **`CHANGELOG.md` and `VERSION` change in the same commit as the work they describe** (`changelog-version`).
9. **Explicit routes are registered before the page catch-all** in `routes/web.php`.
10. **Public queries filter by publication state.** Drafts and future `published_at` never render publicly.

## Layout Worth Knowing

- `config/cms.php` is the registry: `templates`, `forms`, `content_schemas`, `locales`, `menu_locations`,
  `maps`, `mcp`, image and upload settings. Adding a feature usually starts here.
- `app/Contracts/Editorial/` + `app/Traits/` hold the shared editorial contracts (`Publishable`, `Seoable`,
  `Mediable`, `Previewable`). [docs/concepts/editorial-contract.md](docs/concepts/editorial-contract.md)
- `app/Support/` holds the single read paths: `BusinessContact`, `SocialNetworks`, `LinkDestination`, `SiteUrl`.
  Read business data through them, never from `Setting::get()` scattered in views.
- `resources/views/templates/` public page templates, `resources/views/admin/pages/sections/` their editor
  partials, `resources/js/editor-engine.js` the shared editor runtime.
- Tests are feature-first in `tests/Feature/`, named after the screen or flow they cover.

## Before You Commit

Run `composer test`, and `cms:check-conventions` if you touched forms, templates, media or `lang/`. The
`review-conventions` skill does the judgment pass the linter cannot. Hooks in `.githooks/` run the fast checks
automatically once `git config core.hooksPath .githooks` is set (done by `composer setup`).
