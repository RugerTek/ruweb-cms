# Images And Media

> Upload pipeline, responsive variants, the three placeholders, and how templates render images. This file owns
> the placeholder rule that every other doc links to.

## Contents

- Placeholders (the rule)
- Configuration
- Upload pipeline
- Rendering contract
- Localized metadata
- Operations and health

## Placeholders

A fresh install seeds three placeholder images into the media library from stubs in the repo (uploads are not
versioned). They go through the normal upload pipeline, so they end up optimized and with variants like any
real upload, and they are identified by a stable `placeholder_key` — never by filename or id.

| `placeholder_key` | Ratio | Size | Slots |
|---|---|---|---|
| `horizontal` | 16:9 | 1920×1080 | heroes, banners, featured images, social/OG images, wide cards |
| `square` | 1:1 | 1200×1200 | grid and gallery thumbs, avatars, team, product tiles |
| `vertical` | 9:16 | 1080×1920 | portrait cards, covers, mobile full-screen sections |

Rules:

1. **Every image slot in starter content, seeders and example records gets the placeholder matching the ratio
   the template renders**, so a brand-new install shows a complete page instead of empty frames.
2. Match the slot, not the content: a horizontal placeholder in a square slot gets cropped and misrepresents
   the design.
3. Placeholders are sample content meant to be replaced. Nothing may depend at runtime on one being present.
4. Never add a fourth placeholder for a one-off ratio — pick the closest and let the template crop.
5. Branding is the exception: a logo or favicon has no placeholder. Leave it empty rather than seeding a sample.

```bash
php artisan cms:placeholders           # seed missing placeholders, restore missing files
php artisan cms:placeholders --force   # rebuild from the stubs
```

The seeder is idempotent and self-repairing: when records exist but files do not — a fresh clone with an empty
`storage/app/public` — it restores files and variants keeping the same media records, so references stay valid.

## Configuration

`config/cms.php`:

- `upload` — allowed mimes and extensions, `image_max_size` (KB), `document_max_size`.
- `image_optimization` — `enabled`, `max_width` (2048), `quality` (80), `keep_original` (false).
- `responsive_images` — `enabled`, `widths` `[240, 480, 768, 1024, 1536]`.

All of them read from `env()`, so a deployment can turn optimization off without a code change.

## Upload Pipeline

1. Record original metadata.
2. Optimize to WebP (`jpg`, `jpeg`, `png`).
3. Update stored file metadata.
4. Generate responsive variants (`jpg`, `jpeg`, `png`, `webp`).
5. Keep or drop the original per `keep_original`.

SVGs and documents bypass raster optimization and variants. GIF dimensions are read, but GIFs are neither
converted nor variant-generated.

SVG uploads are parsed with networking disabled and validated against an inert geometry/attribute allowlist.
Scripts, event handlers, foreign documents, animation, CSS, external resources, entities and processing
instructions are rejected; local gradient/clip references (`url(#id)`) remain available. Accepted documents
are serialized before storage. Unsupported design features must be flattened/exported as paths or rasterized.

Per asset the pipeline stores final size, original size and format, preserved original path when applicable,
width, height, variant metadata and the optimization timestamp — so coverage stays measurable.

## Rendering Contract

Render content images with `<x-responsive-img :media="$media" sizes="…" alt="…" class="…" />`. It takes a
resolved `App\Models\Media` (`Media::find($sections['hero']['image_id'])`), emits `srcset` when the asset has
variants, falls back to a plain `src` otherwise, resolves alt text from the prop or `$media->localizedAlt()`, and emits
intrinsic `width`/`height`. It renders nothing when `:media` is null, so an empty slot is safe. Variants store a disk-relative `path`; **the URL is built at render
time and never stored**. Do not reimplement responsive logic inside a template.

## Localized Metadata

A media id identifies one file shared across languages. The base `alt` and `title` stay in `media`; overrides
live in `media_translations`, unique by `(media_id, locale)`. No duplicate file or page-specific metadata is
created. Deleting the asset cascades to its translations. These writes enter the media revision ledger and
snapshots include both tables. Restoring a snapshot from before this table existed clears destination media
translations, preventing overrides from attaching to reused ids.

In **Media → Details**, the text-language selector lists the base and active locales. Switch languages to edit
alt and title; drafts survive switching, and Save persists all changed languages atomically. An empty secondary
field inherits that field from the base, shown as its placeholder. Clearing both fields removes the override.
Unsaved edits require confirmation before closing or switching assets, and failed saves keep all drafts.

Use `localizedAlt($locale = null)` and `localizedTitle($locale = null)` when reading media text. They default
to the request locale and fall back per field to the base. `<x-responsive-img>` uses localized alt unless an
explicit nonempty content label is supplied; `fallbackAlt` remains the last fallback. The media title labels
library entries and selectors; it does not automatically become an image tooltip. Branding still uses the
localized site name as its explicit alt.

Admin JSON preserves raw `alt`/`title` and adds `localized_alt`, `localized_title` and a `metadata` map keyed
by locale. Index/show accept `?locale=<active code>` so a Live Editor selector can request the content language
independently of the admin language. Update accepts either legacy base `alt`/`title` or
`metadata: {es: {alt: "…", title: "…"}, en: {alt: "…", title: "…"}}`, never both. Only active locales (plus the
base), `alt`/`title` keys and strings up to 255 characters are accepted. Omitted locales or fields stay intact.

`localized_media` in the editorial schema allows selecting a **different asset** in a translated page. It is
independent from translating the metadata of the same asset; it does not trigger automatic image translation.

## Operations

Media deletion checks `MediaUsage` before touching files. It covers media pivots, page/post schema media
slots (including repeaters and drafts), rich-text URLs and base/localized media settings. The detail panel
lists references; replace/unlink the file there before deleting it. The orphan cleanup command uses the
same read path. Successful deletion cleans the primary file, preserved original and responsive variants.
Retained editorial versions/proposals and unexpired recovery drafts also keep their media alive, so a
later restore does not silently lose its images. The usage list identifies those historical references.

```bash
php artisan media:convert-webp        # run before variant generation
php artisan media:generate-variants
php artisan media:audit-health        # coverage, bytes saved, missing files, pending assets
php artisan cms:media:audit-orphans
```
