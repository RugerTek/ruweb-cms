<?php

use App\Http\Controllers\McpProductController;
use Illuminate\Support\Facades\Route;

// REST mirror of the products_* MCP tools (docs/operations/mcp.md#shop-catalog). Grouped from routes/mcp.php
// whatever the shop mode, so it inherits the /mcp prefix and the mcp middleware group; EnsureShopCatalog answers
// 404 while the catalog is off.
Route::get('/products', [McpProductController::class, 'index']);
Route::post('/products/drafts', [McpProductController::class, 'storeDraft']);
Route::get('/products/{product}', [McpProductController::class, 'show'])->whereNumber('product');
Route::patch('/products/{product}/draft', [McpProductController::class, 'updateDraft'])->whereNumber('product');
Route::post('/products/{product}/publish', [McpProductController::class, 'publish'])->whereNumber('product');
Route::patch('/products/{product}', [McpProductController::class, 'update'])->whereNumber('product');
Route::delete('/products/{product}', [McpProductController::class, 'destroy'])->whereNumber('product');
