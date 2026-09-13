# Settings

## Purpose

This document explains how runtime settings work in the starter architecture.

Use it for:

- the settings data model
- supported field types
- grouping and admin organization
- runtime access patterns
- rules for adding new settings

## Overview

Global settings are stored as key-value records in the `settings` table. Each record has a `type` that determines:

- how it is rendered in the admin
- how it is cast when read in application code
- how it should be validated when updated

This allows the starter to keep environment-like values, site-wide content, and integration identifiers in a runtime-editable layer instead of scattering them through templates.

## Common Types

| Type | Admin field | Runtime shape | Notes |
|------|-------------|---------------|-------|
| `string` | text input | `string` | single-line value |
| `text` | textarea | `string` | multi-line value |
| `boolean` | toggle | `bool` | feature flags and switches |
| `integer` | number input | `int` | numeric values |
| `select` | select input | `string` | constrained choices stored in `options` |
| `media` | media selector | media model or `null` | for logos, images, files |
| `password` | masked input | decrypted `string` | should be stored encrypted |

A `media` setting that ships with a default should point at the placeholder matching how it
is rendered: the global social image is `horizontal`, since every platform renders Open Graph
images letterboxed. A favicon or logo has no placeholder; leave it empty rather than seeding a
sample image into branding. See `docs/image-strategy.md`.

## Groups

Settings are typically organized by `group`. Groups usually map to admin tabs or sections.

Common examples:

- `general`
- `admin`
- `email`
- `analytics`
- `security` (reCAPTCHA keys for public forms, see `docs/forms.md`)
- `integrations`

The exact groups are project-defined, but the grouping model should remain stable and predictable.

Some groups may be managed by dedicated admin screens instead of the general settings screen. For example, email or analytics settings can still be stored as settings while being edited through specialized controllers.

## Runtime Usage

Typical access patterns:

```php
use App\Models\Setting;

$siteName = Setting::get('site_name', 'Default Name');
$logo = Setting::get('site_logo');
$general = Setting::getGroup('general');
$allGrouped = Setting::allGrouped();
```

Guidelines:

- read settings through a single model or service abstraction
- keep type-aware casting centralized
- avoid duplicating settings lookup logic in controllers and views

## Adding A New Setting

Typical workflow:

1. add the setting definition in the bootstrap source, such as a seeder
2. assign the correct `type` and `group`
3. add admin translations for its label and group
4. read the value through the shared settings API

Example definition:

```php
['key' => 'analytics_id', 'value' => '', 'type' => 'string', 'group' => 'integrations'],
```

## Sensitive Values

Sensitive settings such as API secrets should:

- use a protected type such as `password`
- be encrypted at rest
- avoid rendering plaintext back into admin forms
- preserve the existing value when the field is submitted blank

## Caching

Settings should use a request-local or application-level cache strategy to avoid repeated queries during a single request.

If a cache exists, provide an explicit invalidation path.

The starter model uses a static in-process cache and clears it after updates. It is not a persistent Laravel cache store.

## Authorization

Settings mutation should be restricted to privileged roles. Read access may be broader depending on the setting category, but write access should remain explicit and limited.

## Scope Boundary

This document should not become:

- a list of one project's current keys
- a guide for one specific email provider
- a duplicate of admin translation rules
## Multilingual Settings

Global/default values stay in `settings.value`. Localized overrides live in `setting_translations` and are resolved with `Setting::getLocalized($key, $locale)`.

Only user-facing public values should be localized, such as site name, site description, social image, optional logo, and footer text. Operational values such as analytics, mail, favicon, and admin locale remain global. Homepage is global by translation group (`homepage_translation_group_id`), not one setting per locale.
