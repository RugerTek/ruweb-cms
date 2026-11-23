<?php

/*
 * Shop admin routes. Grouped from routes/admin.php whatever the shop mode (route:cache compiles them once), inside
 * the authenticated admin group (`auth`, `role:admin,editor`, EnsureAccountSession, `admin.locale`, prefix `admin`,
 * names `admin.*`), behind EnsureShopCatalog: 404 while the catalog is off. The sidebar links to
 * `admin.products.index` only while the catalog is on.
 */

use App\Http\Controllers\Admin\ProductController;
use App\Http\Middleware\RequireLiveEditor;
use Illuminate\Support\Facades\Route;

// Products. In Essential the index is the operational list and the CRUD answers 404 (docs/concepts/admin-profiles.md).
Route::resource('products', ProductController::class)
    ->except(['show'])
    ->middlewareFor(['create', 'store', 'edit', 'update', 'destroy'], RequireLiveEditor::class);
// The edit form posts here (it carries _method=PUT) to render unsaved changes with the public view.
Route::match(['post', 'put'], 'products/{product}/preview', [ProductController::class, 'preview'])
    ->name('products.preview')->middleware(RequireLiveEditor::class);
// Creates the missing translation of a product (TranslationOperations, shared with MCP content_translate).
Route::post('products/{product}/translations/{locale}', [ProductController::class, 'translate'])
    ->name('products.translate')->middleware(RequireLiveEditor::class);
