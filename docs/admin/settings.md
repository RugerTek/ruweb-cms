# Settings

> Runtime-editable key-value configuration: the types, the groups, how to read them, how to add one. Values
> that must not change at runtime belong in `config/cms.php` instead.

## Model

Rows in `settings`: `key`, `value`, `type`, `group`. The `type` decides how the admin renders the field, how
the value is cast when read, and how it is validated on save. Locale overrides live in `setting_translations`
([../concepts/multilanguage.md](../concepts/multilanguage.md)).

## Types

| Type | Admin field | Runtime shape | Notes |
|---|---|---|---|
| `string` | text input | `string` | |
| `text` | textarea | `string` | |
| `boolean` | toggle | `bool` | |
| `integer` | number input | `int` | |
| `select` | select | `string` | choices in `options` |
| `media` | media picker | `Media` or `null` | logos, images, files |
| `password` | masked input | decrypted `string` | encrypted at rest |
| `social_links` | repeater (network + URL) | `list<array{network, url}>` | JSON; catalog in `App\Support\SocialNetworks` |
| `rich_text` | TinyMCE `short-description` | sanitised HTML or `null` | render with `ContentHelper::richText()` ([rich-text.md](rich-text.md)) |
| `map_point` | Leaflet picker + lat/lng | `array{lat: float, lng: float}` or `null` | JSON; tiles in `config('cms.maps')` |

A `media` setting that ships with a default points at the `horizontal` placeholder when it is a social image.
Branding (logo, favicon) ships empty rather than with a sample ([../content/images.md](../content/images.md)).

## Groups

`general`, `admin`, `email`, `analytics`, `security` (reCAPTCHA keys, [../content/forms.md](../content/forms.md)),
`contact` (business data and social links, [layout.md](layout.md)), `header`, `footer`, `integrations`.

Groups map to Settings tabs, except those in `SettingController::DEDICATED_GROUPS`
(`analytics`, `email`, `header`, `footer`), which are skipped by the generic screen and edited by their own
controller. The Settings sidebar item always opens the General tab.

**The same setting may be edited from several screens** — `site_logo` appears in Settings > General, Header and
Footer — as long as every input writes the same row. Never create a second setting for the same fact.

## Reading

```php
Setting::get('site_name', 'Default');
Setting::getLocalized('site_name');      // locale override, falls back to the global value
Setting::get('site_logo');               // Media model for media settings
Setting::getGroup('general');
Setting::allGrouped();
```

Cross-cutting business data is read through `App\Support\BusinessContact`, never with scattered
`Setting::get('contact_*')` calls.

Caching is a static in-process cache cleared after updates — not a persistent store, so nothing to invalidate
between requests.

## Adding A Setting

1. Add the row to `database/seeders/SettingsSeeder.php` **and** a data migration, so existing installs get it
   (`Setting::set()` ignores unknown keys).
2. Give it the right `type` and `group`.
3. Add `settings_fields.<key>.label` / `.help`, the group label and any option labels to `lang/es/admin.php`
   and `lang/en/admin.php`, key-for-key ([translations.md](translations.md)).
4. Add its validation rule where the group is saved — `App\Http\Controllers\Admin\Concerns\PersistsSettings`
   for `contact`, `header` and `footer`.
5. Read it through the shared API, or expose it in a view composer when a partial needs it.
6. Add the key to `cms.mcp.settings_writable_keys` if an agent should be able to change it
   ([../operations/mcp.md](../operations/mcp.md)).

```php
['key' => 'analytics_id', 'value' => '', 'type' => 'string', 'group' => 'integrations'],
```

## Sensitive Values

Secrets use `password`: encrypted at rest, never rendered back in plaintext, and the stored value is kept when
the field is submitted blank.

## Authorization

Writing settings is admin-only. Read access is broader, but every mutation path stays explicit.
