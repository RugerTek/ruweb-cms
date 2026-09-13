# Images And Media

> Upload pipeline, responsive variants, the three placeholders, and how templates render images. This file owns
> the placeholder rule that every other doc links to.

## Contents

- Placeholders (the rule)
- Configuration
- Upload pipeline
- Rendering contract
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

Per asset the pipeline stores final size, original size and format, preserved original path when applicable,
width, height, variant metadata and the optimization timestamp — so coverage stays measurable.

## Rendering Contract

Render content images with `<x-responsive-img :media="$media" sizes="…" alt="…" class="…" />`. It takes a
resolved `App\Models\Media` (`Media::find($sections['hero']['image_id'])`), emits `srcset` when the asset has
variants, falls back to a plain `src` otherwise, resolves alt text from the prop or `$media->alt`, and emits
intrinsic `width`/`height`. It renders nothing when `:media` is null, so an empty slot is safe. Variants store a disk-relative `path`; **the URL is built at render
time and never stored**. Do not reimplement responsive logic inside a template.

## Operations

```bash
php artisan media:convert-webp        # run before variant generation
php artisan media:generate-variants
php artisan media:audit-health        # coverage, bytes saved, missing files, pending assets
php artisan cms:media:audit-orphans
```
