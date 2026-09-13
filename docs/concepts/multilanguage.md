# Multilanguage

> One database record per locale, joined by `translation_group_id` (WPML/Polylang model). Rendered HTML is
> never translated, and a JSON field never holds two languages.

## Contents

- Locales and URL strategy
- Translation model and derived states
- Editorial schema: field types and flags
- Fingerprints
- Localized settings and menus
- Translation workflow (what an agent must do)

## Locales

Catalog: `config('cms.locales.catalog')`. The base locale is chosen during `cms:install`; a site starts
monolingual.

- `is_active` — editable in the admin. `is_public` — renders publicly. The base locale is always both.
- Installed locale codes are reserved slugs.
- A prefixed URL for an inactive or non-public locale redirects `302` to the base site.

## URLs

| | Base locale | Secondary |
|---|---|---|
| Home | `/` | `/en` |
| Page | `/servicios` | `/en/services` |
| Blog | `/blog`, `/blog/{slug}` | `/en/blog`, `/en/blog/{slug}` |

Route names for the prefixed group are the base name with a `localized.` prefix. Build URLs through
`App\Support\LocalizedRoute`, never by concatenating the locale.

A missing or draft secondary translation redirects `302` to the base translation, or to the base home.

## Translation Model

Translatable entities store `locale`, `translation_group_id`, `translation_status`, `source_fingerprint`.

`translation_status` persists only `needs_review` or `null`. Everything else is derived:

| State | Derived from |
|---|---|
| `missing` | no record for that locale |
| `draft` / `published` | the model's publication `status` |
| `outdated` | base fingerprint ≠ the translation's `source_fingerprint` |
| `needs_review` | the persisted flag |

## Editorial Schema

The schema describes fields; it never stores content. **Pages carry it inside their template**
(`config('cms.templates').<key>.schema`, resolved by `template_key`); posts and taxonomies use
`config('cms.content_schemas')`, keyed by content type.

Types: `text`, `textarea`, `richtext`, `boolean`, `media`, `url`, `select`, `number`, `group`, `repeater`,
`internal_reference`.

Flags:

| Flag | Meaning |
|---|---|
| `translatable` | Visible text; translate it |
| `preserve` | Layout/config value; copy as-is |
| `remap` | Internal reference; must point at the equivalent translation |
| `localized_media` | Media that may differ per locale; may keep the base asset or the matching placeholder |

Paths look like `sections.hero.heading`, `sections.faq.items.*.question`, `title`, `meta_json.description`.

A template or content type without a complete schema still renders and edits, but is not translation-ready and
the admin MUST disable `+ Create translation` for it.

## Fingerprints

`translatableFingerprint()` hashes schema-declared translatable content plus relevant structure such as
`template_key`. It excludes slugs, media ids, booleans, colors, layout and numeric config, publication status,
timestamps and author ids. Changing the base slug does not outdate translations; changing copy does.

## Settings

A setting has a global value in `settings.value`; a locale override lives in `setting_translations` and is read
with `Setting::getLocalized($key, $locale)`, which falls back to the global value.

Localized in the UI: `site_name`, `site_description`, `site_logo`, `default_social_image`, `header_cta_label`,
`footer_tagline`, `footer_copyright`, `footer_credit`.

Global: analytics, mail, favicon, admin locale, reCAPTCHA keys, business contact data.

The homepage is global and stored as `homepage_translation_group_id` — a translation group, not one page id per
locale.

## Menus

Menus are complete per locale: labels, order and links may differ. A localized menu is loaded first and falls
back to the base-locale menu when none exists.

When duplicating a menu, internal references (`page`, `post`, `taxonomy`) are remapped only when a translated
target exists; without one the item is flagged for review or kept out of public rendering. Custom links are
copied as-is and never remapped. A secondary menu MUST NOT link to base-language content automatically.

## Live Editor

The editor works on one localized record; switching language navigates to another record. The preview
controller MUST call `app()->setLocale($model->locale)` before rendering `previewView()` / `previewData()`,
otherwise the preview renders in the admin's locale.

## SEO

- Canonical points at the current localized URL.
- `hreflang` includes published translations in public locales only; `x-default` points at the published base.
- Sitemaps include published URLs in public locales only.
- `needs_review` is editorial only: those records still appear in `hreflang` and the sitemap.

## Translation Workflow

Translate structured fields, never rendered HTML.

1. Read the source entity and resolve its schema by template or type.
2. Create or update the target-locale entity inside the same `translation_group_id`.
3. Translate `translatable` paths.
4. Copy `preserve` paths unchanged.
5. Remap `remap` paths to the translated target when it exists.
6. Keep media ids, except `localized_media` fields that need their own asset.
7. Generate the localized slug, SEO title and SEO description.
8. Store `source_fingerprint` from the base entity.
9. Set `translation_status = needs_review` when review is still pending; clear it by syncing from the current
   base fingerprint when the translation is final.

No MCP tooling is required for this workflow.
