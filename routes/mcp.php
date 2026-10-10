<?php

use App\Http\Controllers\McpController;
use App\Http\Controllers\McpServerController;
use Illuminate\Support\Facades\Route;

// JSON-RPC 2.0 MCP server endpoint
Route::post('/rpc', [McpServerController::class, 'handle']);

Route::get('/capabilities', [McpController::class, 'capabilities']);

Route::get('/pages', [McpController::class, 'pagesList']);
Route::get('/pages/{page}', [McpController::class, 'pagesGet']);
Route::post('/pages/drafts', [McpController::class, 'pagesCreateDraft']);
Route::patch('/pages/{page}/draft', [McpController::class, 'pagesUpdateDraft']);
Route::post('/pages/{page}/publish', [McpController::class, 'pagesPublish']);

Route::get('/posts', [McpController::class, 'postsList']);
Route::get('/posts/{post}', [McpController::class, 'postsGet']);
Route::post('/posts/drafts', [McpController::class, 'postsCreateDraft']);
Route::patch('/posts/{post}/draft', [McpController::class, 'postsUpdateDraft']);
Route::post('/posts/{post}/publish', [McpController::class, 'postsPublish']);

Route::get('/media', [McpController::class, 'mediaList']);
Route::get('/media/{media}', [McpController::class, 'mediaGet']);
Route::patch('/media/{media}', [McpController::class, 'mediaAttachMetadata']);
Route::patch('/media/{media}/metadata', [McpController::class, 'mediaAttachMetadata']);

Route::get('/settings', [McpController::class, 'settingsListGrouped']);
Route::get('/settings/{key}', [McpController::class, 'settingsGet']);
Route::patch('/settings/{key}', [McpController::class, 'settingsUpdate']);

Route::get('/menus', [McpController::class, 'menusList']);
Route::get('/menus/{menu}', [McpController::class, 'menusGet']);
Route::post('/menus', [McpController::class, 'menusCreate']);
Route::patch('/menus/{menu}', [McpController::class, 'menusUpdate']);
Route::put('/menus/{menu}/items', [McpController::class, 'menusSyncItems']);
Route::delete('/menus/{menu}', [McpController::class, 'menusDelete']);
