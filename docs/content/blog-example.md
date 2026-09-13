# Blog: A Worked Collection

> The shipped blog as a reference implementation of [collections.md](collections.md). Example only — when this
> file and the collection contract disagree, the contract wins.

## What It Demonstrates

A collection with first-class content columns instead of a JSON blob, publication state, SEO metadata, shared
media and shared taxonomies, with a public index and detail route.

`App\Models\Post` columns: `title`, `slug`, `excerpt`, `content`, `meta_json`, `status`, `published_at`,
`locale`, `translation_group_id`, plus audit fields. It implements all four editorial contracts while keeping
those columns first-class.

## Pieces

| Concern | Where |
|---|---|
| Model | `App\Models\Post` |
| Admin | `App\Http\Controllers\Admin\PostController`, views in `resources/views/admin/posts/` |
| Public | `App\Http\Controllers\BlogController`, views in `resources/views/blog/` |
| Classification | `App\Models\Taxonomy` with type `category` |
| Featured image | Shared media relation, seeded with the `horizontal` placeholder (cards and covers render wide) |
| Tests | `tests/Feature/BlogPublicTest.php` |

Routes:

```php
Route::resource('posts', PostController::class)->except(['show']);          // admin.php

Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');  // web.php, before the catch-all
Route::get('/blog/{slug}', [BlogController::class, 'show'])->name('blog.show');
```

## Conventions Worth Copying

- Admin index rows: open public post, edit, delete with confirmation.
- On `blog.show` the public admin bar exposes exactly one action, `Editar post`, carrying the current public URL
  as the return target. Never `Editar página` on a post route.
