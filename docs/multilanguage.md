# Multilanguage

## Purpose

The public website uses a WPML/Polylang-style model: each translation is its own database record connected by `translation_group_id`. The base locale keeps unprefixed URLs, secondary locales use a locale prefix, and `content_json` remains the canonical structured-content blob for each record.

This system does not translate rendered HTML and does not store multilingual values inside one JSON field.

## Locales

The installable locale catalog lives in `config/cms.php` under `cms.locales.catalog`.

- The base locale is selected during `cms:install`.
- A site starts monolingual: only the base locale is active and public.
- `is_active` means the locale can be edited in admin.
- `is_public` means the locale can render publicly.
- The base locale is always active and public.
- If a secondary locale is inactive or not public, prefixed URLs redirect with `302` to the base site.

## URL Strategy

- Base locale: `/servicios`, `/blog`, `/blog/post-slug`.
- Secondary locale: `/en/services`, `/en/blog`, `/en/blog/post-slug`.
- Secondary home: `/en` or `/es`, depending on the secondary locale.
- Installed locale codes are reserved slugs.
- Missing or draft secondary translations redirect with `302` to the base translation when available, otherwise to the base home.

## Translation Model

Translatable entities store:

- `locale`
- `translation_group_id`
- `translation_status`
- `source_fingerprint`

`translation_status` persists only `needs_review` or `null`. Public/editorial states are derived:

- `missing`: no record exists for that locale.
- `draft` / `published`: from the model publication `status`.
- `outdated`: current base fingerprint differs from the translation `source_fingerprint`.
- `needs_review`: persisted in `translation_status`.

## Editorial Schema

The schema describes fields; it does not store content.

Pages resolve schema by `template_key`. Posts, taxonomies, and future collections resolve schema by content type.

Field types:

- `text`
- `textarea`
- `richtext`
- `boolean`
- `media`
- `url`
- `select`
- `number`
- `group`
- `repeater`
- `internal_reference`

Field flags:

- `translatable`: visible text that translators or agents should translate.
- `preserve`: layout/config values copied as-is.
- `remap`: internal references that must point to the equivalent translation.
- `localized_media`: media references that may differ by locale. A translation that has no
  asset of its own can keep the base locale's media, or fall back to the placeholder matching
  the slot's aspect ratio (see `docs/image-strategy.md`).

Examples:

- Page: `sections.hero.heading`
- Page repeater: `sections.faq.items.*.question`
- Post: `title`, `excerpt`, `content`, `meta_json.description`
- Taxonomy: `name`, `description`, `parent_id`

Templates or content types without a complete schema can still render and edit normally, but they are not translation-ready. The admin must disable `+ Create translation` for them.

## Fingerprints

`translatableFingerprint()` is a stable hash of schema-declared translatable content.

It includes visible text and relevant structure such as a page `template_key`. It excludes slugs, media IDs, booleans, colors, layout config, numeric config, publication status, timestamps, and author IDs.

Changing the base slug does not make translations outdated. Changing translatable copy does.

## Settings

Settings have a global/default value. Localized overrides live in `setting_translations`.

Localized settings include:

- `site_name`
- `site_description`
- `default_social_image` when present
- `site_logo` when a localized brand asset is needed
- footer text when present

Global settings include:

- favicon
- analytics
- mail
- admin locale
- `homepage_translation_group_id`

The homepage is a translation group, not one page ID per locale.

## Menus

Menus are complete per locale. Menu item labels/order/links may differ by locale.

When duplicating a menu, internal references must be remapped to the translated target. If a translated target does not exist, the item is marked for review or kept out of public rendering. A secondary menu must not automatically link to base-language content.

Current code detail:

- locale-specific menus are loaded first and fall back to the base-locale menu when no localized menu exists
- custom links are copied as-is and are not remapped automatically
- internal references (`page`, `post`, `taxonomy`) are remapped only when a translated target exists

## Live Editor

The live editor operates on one localized record. Switching language navigates to another record. Preview controllers must call `app()->setLocale($model->locale)` before rendering and continue to use `previewView()` / `previewData()`.

## SEO And Sitemap

- Canonical points to the current localized URL.
- `hreflang` alternates include only published translations in public locales.
- `x-default` points to the published base translation.
- Sitemaps include only published URLs in public locales.
- Draft and missing translations are not linked publicly.
- `needs_review` is an editorial flag only; current code still includes published records marked `needs_review` in `hreflang` and sitemap output.

## Agent CLI Translation Contract

Agents such as Codex or Claude Code must translate structured fields, not rendered HTML.

Workflow:

1. Read the source entity and resolve its schema by template or type.
2. Create or update the target-locale entity in the same `translation_group_id`.
3. Translate only `translatable` fields.
4. Preserve `preserve` fields.
5. Remap `remap` fields to equivalent translated records when they exist.
6. Keep media IDs by default, but allow replacement for `localized_media`.
7. Generate localized slug, `SEO Title`, and `SEO Description`.
8. Save `source_fingerprint` from the base entity.
9. Set `translation_status = needs_review` when the translation still requires editorial review. If the translation is being finalized in the same workflow, the codebase also supports clearing the flag by syncing the translation from the current base fingerprint.

No MCP-specific tooling is required by this contract.
