# Redirects

> 301/302 rules for paths of this site, and the 404 monitor that feeds them.

A site that replaces an older one inherits its links: bookmarks, backlinks, indexed URLs and printed
material all keep pointing at paths that no longer exist. Those requests must land on the new URL, not on a
404, or the ranking they carry is lost. The rules live in the database because they are data the client
edits after launch, not code.

## Entities

| Table | Model | Owns |
|---|---|---|
| `redirects` | `App\Models\Redirect` | One rule: `source_path`, `target_url`, `status_code`, `is_active`, `hit_count`, `last_used_at` |
| `not_found_hits` | `App\Models\NotFoundHit` | One row per path that returned 404, with `hit_count`, `referer`, `last_seen_at` |

`source_path` is unique and always stored normalized: leading slash, no trailing slash, no query string or
fragment, produced by `Redirect::normalizePath()`. A pasted absolute URL is reduced to its path, so
`https://legacy.example.com/vieja/?ref=1` and `/vieja` are the same rule.

`target_url` is either a root-relative path of this site or a full URL on another domain. **Never store an
absolute URL of this site**: content moves between domains ([snapshots.md](snapshots.md)).

## Resolution

`App\Support\RedirectResolver` is the single read path. Both entry points call it, so a rule behaves the same
either way:

1. **`ApplyRedirects` middleware**, on the public route group in `routes/web.php`, runs before the page
   catch-all. This is what lets a rule win over a URL that still resolves — a slug kept in place, a page
   moved under another section.
2. **The `NotFoundHttpException` renderer** in `bootstrap/app.php`, for paths that match no route. If no rule
   applies, the 404 is recorded instead.

Rules of resolution:

- Only `GET` and `HEAD` are redirected or monitored.
- Paths matching `cms.redirects.ignore_paths` are skipped entirely — the panel, `mcp`, the OAuth endpoints,
  `storage`, `build`, `/up`. Add a path there rather than special-casing it in code.
- Only `is_active` rules apply. Deactivating is the reversible way to switch one off.
- The incoming query string is carried over when the rule's destination has none, so campaign parameters
  survive the redirect.
- A rule that would resolve to the URL being requested is skipped, so a stale row cannot loop.
- Each hit increments `hit_count` and stamps `last_used_at`, which is what the admin screen sorts by.

## Admin

This is a migration tool, not day-to-day content, so it lives in **Developer tools** (admins only) as two
tabs rather than a top-level screen:

| Tab | Renders | Owns |
|---|---|---|
| `?tab=redirects` | `developer-tools/_redirects.blade.php` | New rule, CSV import, the rule table |
| `?tab=not-found` | `developer-tools/_not-found.blade.php` | The 404 monitor |

`DeveloperToolsController::index` resolves the tab and loads only that tab's data; the tab list is its
`TABS` constant. `App\Http\Controllers\Admin\RedirectController` owns the write actions only — it has no
`index`, and every action returns to the tab it was fired from.

Rules are edited in place: each row's inputs post to its own `PUT` via the `form` attribute, so the table is
a list of forms, not a modal flow. Deletions and the monitor reset go through `data-confirm`
([../admin/live-editor-ui-ux.md](../admin/live-editor-ui-ux.md)). The CSV import sits in a collapsed
`<details>` — it runs once at migration time and should not compete with the new-rule form, which takes the
full width. It springs open on its own when the upload comes back with an error.

A rule is rejected, with the reason on `source_path`, when:

- the source is `/` — the homepage is chosen in `Ajustes > General`, not redirected;
- the destination is empty, or equal to the source after normalization;
- the destination path already has an active rule pointing back at the source (a two-step loop).

Chains longer than two steps are not detected; keep rules pointing at the final URL.

### 404 monitor → rule

Each 404 row has a **Redirect** action that opens the redirects tab with `?source=<path>`, prefilling the
new-rule form.
That is the intended workflow after a migration: launch, wait for traffic, redirect the paths that actually
receive it. Set `CMS_MONITOR_NOT_FOUND=false` to stop recording; the screen says so when it is off.

### CSV import

A migration arrives as a spreadsheet, so the screen accepts one. Columns: `source_path`, `target_url`
(required), `status_code`, `is_active` (optional, defaulting to `301` and active). The delimiter is detected
between `,`, `;` and tab. Existing sources are updated, invalid rows are skipped and counted in the result
message rather than aborting the import. `Descargar CSV de ejemplo` serves the template.

## Configuration

`config/cms.php`:

```php
'redirects' => [
    'status_codes' => [301, 302],
    'monitor_not_found' => env('CMS_MONITOR_NOT_FOUND', true),
    'ignore_paths' => ['admin', 'admin/*', 'mcp', 'mcp/*', ...],
    'per_page' => 25,
],
```

Adding a status code here is enough: validation, the import and the select all read it.

## Checklist

- [ ] `source_path` stored through `Redirect::normalizePath()`, never raw input.
- [ ] `target_url` is a root-relative path or an external URL — never an absolute URL of this site.
- [ ] New public route prefixes that must not be monitored are added to `cms.redirects.ignore_paths`.
- [ ] 301 for a permanent move, 302 only while the old URL is coming back.
- [ ] Labels in `lang/es` and `lang/en` ([../admin/translations.md](../admin/translations.md)).
