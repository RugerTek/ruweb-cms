# SEO

> Sitemap, metadata, crawler policy and global browser-side integrations.

## Sitemap

`/sitemap.xml` is served by `App\Http\Controllers\SitemapController`, cached for an hour under the
`sitemap-xml` key, rendering `resources/views/sitemap/index.blade.php`.

It includes the homepage (resolved from `homepage_translation_group_id` in the base locale), published pages
and published posts, restricted to `Locale::publicCodes()`. Drafts, non-public locales and future
`published_at` never appear. Clear the cache after a bulk publish.

## Adding A Collection

1. The model implements `Publishable` and `Seoable` ([../concepts/editorial-contract.md](../concepts/editorial-contract.md)).
2. Add its published query to `SitemapController` and its entries to the sitemap view.
3. Register its public routes **before** the page catch-all in `routes/web.php`.
4. Confirm `public/robots.txt` does not block its URLs.

It needs a `slug` and a last-modified signal (`updated_at`).

Priorities in the view:

| Content | Priority | Changefreq |
|---|---|---|
| Homepage | 1.0 | daily |
| Top-level index | 0.8 | weekly |
| Section index | 0.6 | daily |
| Detail page | 0.5 | monthly |
| Archived | 0.3 | yearly |

## Metadata

`<x-seo-meta>` renders title, description, canonical, Open Graph and Twitter tags from the entity's `meta()`
with global fallbacks: entity meta → entity title → `site_name` / `site_description` /
`default_social_image`. Titles and descriptions are localized through `Setting::getLocalized()`.

`hreflang` and `x-default` follow the rules in [../concepts/multilanguage.md](../concepts/multilanguage.md).

Social images are letterboxed by every platform, so their placeholder is always `horizontal`
([images.md](images.md)).

## Global Integrations

Analytics, pixels and verification tokens are settings, rendered by the public layout:

- Store normalized ids or tokens, never a pasted `<script>` blob.
- Render only on the public site.
- Keep the surface centralized in the layout, not sprinkled through templates.

Currently supported: Google Tag / GA4, Meta Pixel (with `noscript` fallback), Google Search Console meta tag.

## `robots.txt`

`public/robots.txt` ships as:

```text
User-agent: *
Disallow: /admin/

Sitemap: /sitemap.xml
```
