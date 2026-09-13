---
name: translate-content
description: Translates a page, post or taxonomy of ruweb-cms into another locale following the editorial schema — translatable, preserve, remap and localized_media paths, fingerprint and localized slug and SEO. Use when the user asks to translate content or create a translation ("traducí la home al inglés", "create the English version of this page").
---

# Translate Content

Read the "Translation Workflow", "Editorial Schema" and "Fingerprints" sections of
[docs/concepts/multilanguage.md](../../../docs/concepts/multilanguage.md).

Translate **structured fields, never rendered HTML**, and never store two languages in one JSON field: each
locale is its own record in the same `translation_group_id`.

## Workflow

1. Read the source entity and resolve its schema — pages from `config('cms.templates').<key>.schema`, posts
   and taxonomies from `config('cms.content_schemas')`.
2. Create or update the target-locale record in the same `translation_group_id`.
3. Translate every `translatable` path.
4. Copy `preserve` paths unchanged (layout, config, booleans, button destinations).
5. Remap `remap` paths to the translated target when it exists; leave them and flag for review when it does not.
6. Keep media ids, except `localized_media` fields that genuinely need their own asset.
7. Generate the localized slug, SEO title and SEO description — do not reuse the base slug.
8. Store `source_fingerprint` from the base entity.
9. Set `translation_status = needs_review` when review is still pending, or clear it by syncing from the
   current base fingerprint when the translation is final.

## Rules

- The target locale must be active; publishing it publicly also requires `is_public`.
- A template without a complete schema is not translation-ready — complete the schema first.
- Never translate a secondary locale from another secondary locale: the base locale is the source of truth.
- Menus are per locale and their internal links must be remapped, never left pointing at base-language content.
