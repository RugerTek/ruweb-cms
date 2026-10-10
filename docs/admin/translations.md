# Admin Translations

> Keeping the admin interface translatable. Applies to `resources/views/admin/**`, admin controllers and
> requests, `resources/js/admin.js`, and `lang/*/admin.php`. Public-site strings live in `lang/*/public.php`.

## The Parity Rule

`lang/es/admin.php` and `lang/en/admin.php` MUST stay **key-for-key identical** — same nested structure, same
count (779 keys today). Adding a key to one and not the other is a bug, not a pending task.

```bash
php artisan cms:check-conventions   # includes the parity check
```

`lang/fr/` currently ships `public.php` only; a locale is complete for the admin once it has `admin.php` with
the same keys.

## Rules

1. **No visible literal in an admin Blade.** Labels, placeholders, help text, buttons, confirmations and empty
   states go through `__('admin.<key>')` or a dedicated admin feature namespace (`security`, `editorial`,
   `submissions`, `media_safety`). Dedicated namespaces keep the same key-for-key parity in Spanish and English.
2. **No visible literal in admin JS.** `resources/js/admin.js` reads from `window.adminI18n`, built in
   `resources/views/admin/layouts/app.blade.php` and accessed with `t('key', 'fallback')`. Add the string to
   the `$adminI18n` array there, and to both locale files. Feature modules may receive their own translated
   configuration. Inline Blade scripts encode translated strings with `@json` or `Js::from`.
3. **Controllers, requests and JSON responses** use translation keys for any message a user can see.
4. **Dynamic labels have a deterministic fallback** when the key is missing — never render a raw key.
5. **Public templates never read `admin.*`**, and admin screens never read `public.*`.

## Key Naming

Flat prefixes by concern: `settings_*`, `menu_*`, `taxonomy_*`, `field_*`, `btn_*`, `confirm_*`, `role_*`.

Nested arrays for structured sets:

```php
'settings_fields' => ['site_name' => ['label' => '…', 'help' => '…']],
'settings_groups' => ['general' => '…'],
'settings_options' => ['locale' => ['en' => '…']],
'forms' => ['contact' => '…'],
```

## Settings And Forms

A setting created in a seeder or migration MUST arrive with `settings_fields.<key>.label` (plus `.help` when it
has one) and its group label in **every** locale. A setting with selectable options also needs
`settings_options.<key>.*`. A new public form needs `admin.forms.<key>` for its submissions tab, and its public
fallbacks under `public.forms.<form>.*` ([../content/forms.md](../content/forms.md)).

## Checklist

- [ ] No hardcoded visible text in the touched admin views
- [ ] Every new key exists in `es` and `en`
- [ ] New JS strings added to `$adminI18n` and read with `t()`
- [ ] Seeded settings have label, help, group and option translations
- [ ] `php artisan cms:check-conventions` passes
