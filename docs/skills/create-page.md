# Create Page Skill

## How To Use

This file is not meant to be copied into the prompt.

It is meant to be referenced directly, for example:

```md
[create-page.md](docs/skills/create-page.md)

About Us

Required sections:
- hero: with image, description, title, button_url, and button_label
- values: grid of 4 cards with icon, title, and description
```

When this file is referenced, the agent should treat the rest of the user's message as the page specification to implement.

## Instruction For The Agent

If this file was referenced in the prompt:

1. Use these canonical documents as the source of truth:
   - [pages-guide.md](../pages-guide.md): rules specific to the Page entity
   - [content-model.md](../content-model.md): canonical shape of structured content
   - [templates.md](../templates.md): template registration and rendering
   - [live-editor.md](../live-editor.md): shared editor and preview contract
   - [image-strategy.md](../image-strategy.md): image and media handling
   - [seo-guide.md](../seo-guide.md): SEO and sitemap compatibility for public pages
2. Treat all text after the reference to this file as the functional requirement.
3. If the user gives a name such as `About Us`, infer a reasonable slug if none is explicitly provided.
4. If a new template is needed, register it and create it.
5. If a section can reuse a key already compatible with another template, reuse it; otherwise define a new one with clear, stable names.
6. Add a section editor partial when a section needs fields beyond the generic `heading` and `body` fallback.
7. Do not hardcode editable content in Blade.
8. For images, use media IDs or the media strategy required by the current architecture.
   Fill every image field with the placeholder matching the slot's aspect ratio — `horizontal`
   for heroes and featured images, `square` for grid or avatar slots, `vertical` for portrait
   slots — resolved by `placeholder_key`, so the new page renders complete from the start.
9. Keep the content aligned with the `meta` + `sections` contract.
10. If the page needs to be ready for editing, respect the shared editor contract.
11. If the request is ambiguous, make the smallest reasonable interpretation consistent with the existing architecture.

## What The User Should Describe After Referencing This File

- page name
- slug, if a specific one is required
- required sections
- fields per section
- whether a new template is needed or an existing one should be reused
- any editorial, visual, or SEO requirements
