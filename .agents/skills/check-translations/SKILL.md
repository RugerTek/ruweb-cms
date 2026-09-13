---
name: check-translations
description: Fixes missing, raw or untranslated labels in the ruweb-cms admin and keeps lang/es and lang/en key-for-key. Use when a screen shows a raw key instead of text ("dice admin.save en vez de Guardar", "this button shows admin.something"), when a label is missing or still in the other language, after adding admin labels or touching lang files, or when translations of settings or form tabs are missing.
---

# Check Translations

Rules: [docs/admin/translations.md](../../../docs/admin/translations.md).

## Loop

1. Run the checker:

   ```bash
   php artisan cms:check-conventions
   ```

   It reports every key present in one `lang/*/admin.php` and missing from another, plus registered forms with
   no `admin.forms.<key>` label.

2. Fix each reported key **in the locale that is missing it** — translate it, never copy the other language
   verbatim. Keep the nested structure identical, not just the flattened key list.

3. Re-run until it passes.

## Also Check Manually

The command cannot see these; grep for them when the change touched admin views or JS:

- Visible literals in `resources/views/admin/**` that should be `__('admin.…')`.
- Strings in `resources/js/admin.js` not coming from `t('key', 'fallback')` — the dictionary is built in
  `resources/views/admin/layouts/app.blade.php` (`$adminI18n`), so a new JS string needs an entry there **and**
  in both locale files.
- A setting added by a seeder or migration without `settings_fields.<key>.label`, its `.help`, its group label
  and its option labels.
- A public form without `public.forms.<form>.*` fallbacks, which is what renders before the client edits
  labels.
- Admin screens reading `public.*` or public templates reading `admin.*`.

## Adding A Locale

A locale is admin-complete once `lang/<code>/admin.php` exists with the same keys as the others. `lang/fr/`
currently ships `public.php` only, so French is a public-site locale, not an admin one.
