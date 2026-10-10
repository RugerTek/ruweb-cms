# Collections

> Contract for a repeatable content entity with its own admin CRUD and, usually, public routes: products, case
> studies, events. The shipped example is the blog ([blog-example.md](blog-example.md)).

Shared capabilities come from [../concepts/editorial-contract.md](../concepts/editorial-contract.md); taxonomy
internals from [taxonomies.md](taxonomies.md); editor mechanics from
[../admin/live-editor.md](../admin/live-editor.md).

## Rules

1. **Media through the shared media system.** No raw paths in columns or JSON. Seed example records with the
   placeholder matching how the collection renders ([images.md](images.md)).
2. **Classification through the shared taxonomy system.** Never a one-off taxonomy table per collection.
3. **Public visibility through `Publishable`** (`status`, `published_at`) and the `published()` scope.
4. **Public detail pages implement `Seoable`** and are added to the sitemap ([seo.md](seo.md)).
5. **Preview reuses the shared editor shell**, never a parallel editing flow.
6. **Authorization is explicit**: document who can view, create, update, publish and delete, and enforce it in a
   policy.
7. **Admin index rows expose the standard actions**: open (only when the entry has a public detail page), edit,
   delete with confirmation — same icon treatment and alignment as the existing tables.
8. **Keep project-specific business logic out of the starter core.**

## Editor Origin

When an entry can be opened in the editor from more than one place, preserve the origin: opened from the admin
index, back returns to that index; opened from the public admin bar, back returns to the exact public URL.
Never hardcode the back link to the admin index.

The public admin bar shows **one** contextual action, resolved from the current content type (`Editar post`,
`Editar servicio`). Never render a page edit action on a collection detail route, even when the layout receives
a page-compatible object for SEO.

## Checklist

1. Migration and model.
2. Publication fields and `Publishable` if public.
3. `HasMedia` + `Mediable` for images or files.
4. `HasTaxonomies` if it needs classification.
5. Form requests for create and update.
6. Admin routes, controller, views — including the standard row actions.
7. Public routes, registered **before** the page catch-all.
8. Policy and role rules.
9. Sitemap entry and metadata if public.
10. Tests for publishing, public visibility and authorization boundaries.
11. A doc of its own only if it introduces behaviour no existing doc covers.

## Public creation shortcuts

Register each collection in `config('cms.collections')` with its `model`, a singular `label` translation
key in both admin locales, and `create_route`. The public admin bar's **Create new…** dropdown always lists
Page first, then registered collections in configuration order (Post in the starter). It hides routes that
do not exist and collections the signed-in user's `create` policy denies.

Creation links carry the exact public URL in `return`. Create screens, the subsequent editor and language
switches must preserve that origin using same-site return validation. The menu creates content through the
normal admin flow; the Menus screen remains the owner of navigation items.

## Profile-aware delivery

Discover the deployment with `php artisan cms:capabilities` before implementing a collection.
[Administration profiles](../concepts/admin-profiles.md) defines the delivery contract. In essential,
keep models/schema, shared validation and operations, policies, public published queries, media/localization,
operational listing and tests; specialized editorial forms and Live Editor controls are optional and should
not be built by default. Full retains the editorial CRUD and UI/UX requirements above.
