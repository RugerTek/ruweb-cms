# Header And Footer

## Purpose

This document explains how the public header and footer are built and edited in the starter, and how a site derived from it must extend them.

Use it for:

- the ownership model: which data the header and footer own and which they borrow
- the Header and Footer admin screens and the shortcut pattern
- the business contact data in Settings > Contacto and the social links catalog
- the read path for templates (`BusinessContact`, `<x-social-links>`)
- the checklist for adding a field, a social network or a new layout region

## Principles

1. **Code owns the markup, the client owns the data.** `resources/views/partials/header.blade.php` and `footer.blade.php` are versioned in the repository. What the client can change is a small, explicit set of settings, menus and media.
2. **One place per fact.** The logo lives in `site_logo`, navigation lives in Menus, contact data lives in Settings > Contacto. The header and footer never store a copy.
3. **One screen per region.** The client opens **Header** or **Footer** in the admin and finds everything that region shows: the fields the region owns as inputs, and everything it borrows as a shortcut card that says where the data lives and links there.
4. **Two inputs for one setting is fine; two settings for one fact is not.** `site_logo` is editable from Settings > General, Header and Footer. All three write the same row, so they can never disagree.

## Ownership Map

| What the visitor sees | Where it is stored | Where the client edits it |
|---|---|---|
| Logo (header and footer) | setting `site_logo` (group `general`) | Header, Footer, Settings > General |
| Header navigation | menu with location `header` | Menus (shortcut from Header) |
| Header call-to-action button | settings `header_cta_enabled`, `header_cta_label`, `header_cta_page`, `header_cta_url`, `header_cta_new_tab` (group `header`) | Header |
| Footer navigation | menu with location `footer` | Menus (shortcut from Footer) |
| Footer tagline (rich text) | setting `footer_tagline` (group `footer`, type `rich_text`) | Footer |
| Copyright line | setting `footer_copyright` (group `footer`), supports `{year}` and `{site}` | Footer |
| Development credit ("Desarrollado por …", rich text with link) | settings `footer_credit_enabled`, `footer_credit` (group `footer`) | Footer |
| Phone, WhatsApp, email, address, map location | settings `contact_phone`, `contact_whatsapp`, `contact_email`, `contact_address`, `contact_location` (group `contact`) | Settings > Contacto (shortcut from Header and Footer) |
| Social icons | setting `contact_social_links` (group `contact`, type `social_links`) | Settings > Contacto (shortcut from Footer) |

Groups `header` and `footer` are hidden from the generic Settings tabs and edited only through their dedicated screens (`SettingController::DEDICATED_GROUPS`). Group `contact` is a regular Settings tab.

## Admin Screens

### Header (`/admin/header`) and Footer (`/admin/footer`)

- Controllers: `App\Http\Controllers\Admin\HeaderController`, `FooterController`. Admin role only, like Settings.
- Views: `resources/views/admin/header/index.blade.php`, `resources/views/admin/footer/index.blade.php`.
- Both screens render fields with the shared partial `admin.settings._field`, so a setting looks and behaves the same in every screen where it appears.
- Both screens use the trait `App\Http\Controllers\Admin\Concerns\PersistsSettings` for validation rules and saving. The same trait is used by `SettingController`, so the rules for `contact_*`, `header_*` and `footer_*` live in exactly one place.
- **Conditional blocks.** Optional elements (the header button, the footer credit) sit behind a switch: `[data-toggle-controls="<id>"]` on the checkbox shows or hides `[data-toggle-target="<id>"]`. The switch is the stored `*_enabled` boolean; the public partial renders the element only when it is on, so a client can prepare the content and turn it on later.
- **Button destination.** The header CTA uses the shared partial `admin.partials._link-destination` (select of published pages, `Custom` last, manual URI shown only for Custom, always-visible "open in a new tab"), exactly as `docs/live-editor-ui-ux.md` prescribes. It stores `header_cta_page` (translation group id) **or** `header_cta_url`, never both (`PersistsSettings::normalizeLinkDestination`), plus `header_cta_new_tab`. `App\Support\LinkDestination::resolve()` turns the pair into the URL for the current public locale, falling back to the base locale.
- **Rich text.** `footer_tagline` and `footer_credit` are `rich_text` settings edited with the TinyMCE `short-description` preset (bold, italic, links with optional new tab). They are sanitised on save with `RichTextSanitizer` and rendered through `ContentHelper::richText()`.
- Below the form, a **"Se edita en otro lugar"** block lists one shortcut card per borrowed part, rendered with `admin.layout._shortcut-card` (title, one-line explanation, a badge with the current value when useful, and a button that opens the right screen). Shortcuts must link to the specific target, e.g. the header menu's edit page or the `contact` tab of Settings, not to a list.

### Settings > Contacto

Fields, in order: `contact_phone`, `contact_whatsapp`, `contact_email`, `contact_address`, `contact_location`, `contact_social_links`.

`contact_location` is a `map_point`: a Leaflet map with free OpenStreetMap tiles where the client clicks or drags a pin. Latitude and longitude remain visible as inputs so coordinates can be pasted; both stay in sync with the pin. Tiles URL, attribution and the default centre live in `config('cms.maps')`. The picker (`resources/js/map-picker.js`) is code-split and loads only on screens that contain `[data-map-picker]`.

Nothing is required. Empty values simply hide the corresponding element in the public layout.

`contact_social_links` is an administrable repeater backed by the generic repeater in `resources/js/admin.js`. Each row is a network selector plus the profile URL; rows with an empty URL are dropped on save and the order is preserved. The catalog of networks the selector offers is `App\Support\SocialNetworks`.

### Sidebar and admin bar

- Sidebar: **Header** and **Footer** appear right after **Menús**, for admins only.
- Public admin bar: an **Editar layout** control opens a hover / focus dropdown with **Editar header** and **Editar footer**. It is CSS only (`group-hover`, `group-focus-within`), because the public bundle does not load `admin.js`.

## Public Read Path

Templates never call `Setting::get('contact_*')` directly. They read through:

```php
use App\Support\BusinessContact;

BusinessContact::phone();          // "+595 21 123 456" or null
BusinessContact::phoneHref();      // "tel:+59521123456"
BusinessContact::whatsappHref();   // "https://wa.me/595981123456"
BusinessContact::email();          // + emailHref()
BusinessContact::address();        // multi-line string
BusinessContact::coordinates();    // ['lat' => float, 'lng' => float] or null, from contact_location
BusinessContact::mapsHref();       // coordinates first, otherwise a search for the address
BusinessContact::socialLinks();    // [['network','label','url','icon'], ...] in the client's order
BusinessContact::hasAny();
```

Social icons render with the anonymous component:

```blade
<x-social-links class="flex gap-3 text-gray-500" icon-class="w-5 h-5" link-class="hover:text-gray-900" />
```

It renders nothing when no network is configured. Icons are 24x24 Simple Icons paths with `fill="currentColor"`, so they take the surrounding text color.

The header and footer partials receive `siteName`, `siteLogoMedia`, `headerCtaLabel`, `headerCtaUrl`, `headerCtaNewTab`, `footerTagline`, `footerCopyright` and `footerCredit` from the `layouts.public` view composer (the CTA and credit variables are already `null` when their switch is off) in `AppServiceProvider`. Navigation renders with `<x-menu-component slug="header">` and `<x-menu-component location="footer">` (see `docs/menu-guide.md`).

Public strings (section titles such as "Contacto", "Seguinos", "Ver en el mapa") live under `layout.*` in `lang/{locale}/public.php`.

## Localization

Visitor-facing text settings (`header_cta_label`, `footer_tagline`, `footer_copyright`, `footer_credit`) are read with `Setting::getLocalized()`, so a translation can be stored in `setting_translations` following `docs/settings.md`. Contact data and URLs are global by nature and are read with `Setting::get()`. The Header and Footer screens edit the global value.

## Extending

### Add a field the header or footer owns

1. Add the row to `database/seeders/SettingsSeeder.php` **and** a data migration (pattern: `2026_09_15_000001_add_contact_header_and_footer_settings.php`), with `group` `header` or `footer`.
2. Add its validation rule to `headerSettingRules()` or `footerSettingRules()` in `PersistsSettings`.
3. Add `settings_fields.<key>.label` and `.help` to `lang/es/admin.php` and `lang/en/admin.php`.
4. Add the key to the ordering array in `HeaderController::index()` or `FooterController::index()`.
5. Expose it from the `layouts.public` composer if the partial needs it, then render it in the partial. Empty value must hide the element.
6. If an LLM should be able to change it through MCP, add the key to `cms.mcp.settings_writable_keys`.
7. Cover it in `tests/Feature/AdminLayoutScreensTest.php`.

Before adding a field, ask whether the fact already exists somewhere else. If it does, add a shortcut card instead.

### Add a second logo (e.g. a light version for dark backgrounds)

Add a `media` setting such as `header_logo_light` in group `header`, following the steps above. Keep `site_logo` as the canonical logo and treat the second one as an optional override that falls back to `site_logo` in the partial. Do not add a separate "footer logo" unless the design truly uses a different asset.

### Add another button with a destination

Reuse `admin.partials._link-destination` with three settings (`<x>_page`, `<x>_url`, `<x>_new_tab`), call `normalizeLinkDestination()` in the controller and `LinkDestination::resolve()` in the composer. Never add a bare URL input for a client-facing button.

### Add a social network

Add one entry to `App\Support\SocialNetworks::NETWORKS` with `label`, `placeholder` and the 24x24 SVG `icon` path. The selector, the validation, the storage and `<x-social-links>` pick it up automatically. Nothing else changes.

### Add a shortcut card

Include `admin.layout._shortcut-card` with `title`, `description`, `href`, `cta` and optionally `badge`. Use it whenever the region displays data owned by another module (a collection, a form, another settings tab).

### Add a new layout region

If a future site needs a third region with client-editable data (a top bar, an announcement banner, a sidebar):

1. Create a settings group named after the region and add it to `SettingController::DEDICATED_GROUPS`.
2. Create `<Region>Controller` with `index`/`update` using `PersistsSettings`, admin-only routes `admin.<region>.index` / `admin.<region>.update`, and a view following `admin/header/index.blade.php`.
3. Add a sidebar entry next to Header and Footer and, if it makes sense, an item in the admin bar's **Editar layout** dropdown.
4. Render the region as a partial included from `layouts.public`, fed by the view composer.
5. Document the region in the ownership map above.

## Anti-patterns

- Storing a copy of the phone number, the logo or a menu inside a page's `content_json` or a `header_*` setting.
- Fixed per-network settings such as `contact_facebook_url`. Use the `social_links` repeater and the catalog.
- Resolving settings inline in the partials for data that other templates also need. Put the read path in `BusinessContact` or the composer.
- Header or footer forms that reproduce the menu builder or the contact form. Link to them.
- Making contact fields required. A business without a landline must be able to leave the phone empty.

## Related Docs

- `docs/settings.md` — settings model, types (including `social_links`), groups and dedicated screens
- `docs/menu-guide.md` — menu locations `header` and `footer`
- `docs/live-editor-ui-ux.md` — repeater rules, including typed single-value items
- `docs/image-strategy.md` — logo rendering with `<x-responsive-img>`
- `docs/mcp.md` — settings writable through MCP
