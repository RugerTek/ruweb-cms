# Create Collection Skill

## How To Use

This file is not meant to be copied into the prompt.

It is meant to be referenced directly, for example:

```md
[create-collection.md](docs/skills/create-collection.md)

I want a testimonials collection.

Fields:
- author_name
- author_role
- quote
- company_logo
- status
```

When this file is referenced, the agent should treat the rest of the user's message as the collection specification to implement.

## Instruction For The Agent

If this file was referenced in the prompt:

1. Use these canonical documents as the source of truth:
   - [collections-guide.md](../collections-guide.md): general collection contract
   - [taxonomies.md](../taxonomies.md): shared classification system
   - [live-editor.md](../live-editor.md): shared editor when preview is needed
   - [image-strategy.md](../image-strategy.md): image and media handling
   - [seo-guide.md](../seo-guide.md): SEO and sitemap rules for public collections
   - [architecture.md](../architecture.md): system boundaries and project structure
   - [blog-collection.md](../blog-collection.md): concrete example, only as a secondary reference
2. Treat all text after the reference to this file as the functional requirement.
3. Define the model, persistence, admin CRUD, permissions, public routes, and public views only to the extent required by the request.
4. Use shared taxonomies if the collection needs classification.
5. Use shared media if the collection needs images or files. Seed example records with the
   placeholder matching how the collection renders them: `horizontal`, `square` or `vertical`.
6. If the collection is public, respect publication, metadata, and sitemap requirements.
7. If it needs editorial preview, use the shared editor instead of creating a parallel flow.
8. Validate taxonomy IDs against the intended taxonomy type before syncing them.
9. Keep the solution aligned with the starter architecture and avoid moving project-specific business logic into the reusable core.
10. If the request leaves gaps, fill only the minimum necessary using consistent conventions.
11. For admin index tables, require an actions column that includes:
   - open link if the entry has a public detail page
   - edit button
   - delete button with confirmation
12. Keep those row actions visually aligned and consistent with the existing admin icon pattern.

## What The User Should Describe After Referencing This File

- collection name
- required fields
- whether it has a public frontend
- required taxonomies
- publication rules
- permissions
- whether it needs an editor with preview
