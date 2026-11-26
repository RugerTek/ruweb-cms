# Time Zones

> Dates are stored in UTC and shown in the site's time zone. What this means for code that reads, prints or
> accepts a date.

## The Rule

- **Storage is UTC.** `config/app.php` keeps `'timezone' => 'UTC'`; Eloquent writes and `now()` are UTC. Never
  change it per site: existing rows would be reinterpreted.
- **People see the site zone.** Panel, public site, emails and exports print dates through
  `App\Support\SiteTime`, which converts to the `site_timezone` setting.
- **People type in the site zone.** A form value without an offset (a `datetime-local` input, `2026-12-01 09:00`)
  is read in the site zone and converted to UTC before validation and storage. A value with an explicit offset
  (`…Z`, `…-03:00`) keeps that offset, so ISO strings from MCP or stored history round-trip unchanged.

## Where The Zone Comes From

1. The `site_timezone` setting (group `general`, Settings > General «Zona horaria» / «Time zone»), a select of the
   PHP time zone identifiers. Admins and MCP (`settings_update`, `settings_update_batch`) can change it; invalid
   values are rejected.
2. Its starter value is `config('cms.display_timezone')`, read from `APP_DISPLAY_TIMEZONE` (default
   `America/Asuncion`). The install seeder, the data migration and `settings_reset` use it.
3. A missing or invalid setting falls back to that configured value, and an invalid configured value to
   `America/Asuncion`.

Changing the zone never touches stored data: the same instant is just shown at another local time.

## Using `SiteTime`

```php
SiteTime::format($post->published_at);                 // 'd/m/Y H:i' in the site zone, '' when null
SiteTime::format($key->expires_at, 'Y-m-d H:i', '—');  // custom format and fallback
SiteTime::translatedFormat($post->published_at, 'M d, Y'); // public pages, month names in the current locale
SiteTime::input($page->published_at);                  // value for <input type="datetime-local">
SiteTime::toStorage($request->input('published_at'));  // typed value -> UTC ISO string for validation/storage
SiteTime::now();                                       // "today", "this month" in the site zone
SiteTime::dayBoundsUtc('2026-11-26');                  // [start, end] of a local day as UTC instants
```

Never print a date with `->format()` or `{{ $model->created_at }}` in a view: that shows UTC. Machine output
(MCP/REST JSON, `<time datetime>`, sitemaps, snapshot manifests) keeps ISO 8601 with offset.

## Already Covered

- Pages and posts: `PageInput` / `PostInput` and the publication operations (`setPublication`, `schedule`,
  `setExpiry`) convert `published_at` / `expires_at`, so the panel form and MCP share one interpretation. A post
  scheduled for 09:00 goes live at 09:00 site time.
- MCP key expiry in the panel, the editorial calendar month, submission `from` / `to` day filters.
- Admin lists and details, the contact submission email, the blog dates and the footer `{year}`.

## Adding A Date

Print it with `SiteTime`, accept it with `SiteTime::toStorage()` in the shared operation (not only in the
controller), and cover the round trip in a test with `travelTo()` (see `tests/Feature/SiteTimezoneTest.php`).
