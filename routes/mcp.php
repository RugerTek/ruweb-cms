<?php

use App\Http\Controllers\McpContentController;
use App\Http\Controllers\McpController;
use App\Http\Controllers\McpCustomCodeController;
use App\Http\Controllers\McpMediaController;
use App\Http\Controllers\McpServerController;
use App\Http\Controllers\McpSubmissionController;
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

Route::patch('/settings', [McpController::class, 'settingsUpdateBatch']);
Route::get('/settings', [McpController::class, 'settingsListGrouped']);
Route::get('/settings/{key}', [McpController::class, 'settingsGet']);
Route::patch('/settings/{key}', [McpController::class, 'settingsUpdate']);

Route::get('/menus', [McpController::class, 'menusList']);
Route::get('/menus/{menu}', [McpController::class, 'menusGet']);
Route::post('/menus', [McpController::class, 'menusCreate']);
Route::patch('/menus/{menu}', [McpController::class, 'menusUpdate']);
Route::put('/menus/{menu}/items', [McpController::class, 'menusSyncItems']);
Route::delete('/menus/{menu}', [McpController::class, 'menusDelete']);

Route::get('/schema', [McpContentController::class, 'schema']);
Route::get('/locales', [McpContentController::class, 'locales']);
Route::post('/locales', [McpContentController::class, 'configureLocales']);
Route::post('/content/{type}/{id}/translation', [McpContentController::class, 'translation']);
Route::get('/content/{type}/{id}/translation-changes', [McpContentController::class, 'changes']);
Route::post('/content/{type}/{id}/translation-acknowledge', [McpContentController::class, 'acknowledge']);
Route::post('/content/{type}/{id}/publication', [McpContentController::class, 'publication']);
Route::post('/content/{type}/{id}/preview', [McpContentController::class, 'preview']);
Route::post('/media/upload-authorization', [McpMediaController::class, 'authorizeUpload']);
Route::post('/media/uploads', [McpMediaController::class, 'upload']);
Route::get('/media/{media}/usages', [McpMediaController::class, 'usages']);
Route::delete('/media/{media}', [McpMediaController::class, 'destroy']);

Route::get('/content/{type}/{id}/field', [McpContentController::class, 'readField']);

foreach (['versions', 'version', 'diff', 'reviews'] as $action) {
    Route::get('/content/{type}/{id}/'.$action, [McpContentController::class, 'editorialRead'])->defaults('action', $action)->whereNumber('id')->whereIn('type', ['pages', 'posts']);
}
foreach (['propose', 'review_request', 'review_decide', 'review_comment', 'restore', 'preview_revoke'] as $action) {
    Route::post('/content/{type}/{id}/'.str_replace('_', '-', $action), [McpContentController::class, 'editorialWrite'])->defaults('action', $action)->whereNumber('id')->whereIn('type', ['pages', 'posts']);
}

// Operational inbox: account role and credential scope remain independent of profile.
Route::get('/submissions/{form}', [McpSubmissionController::class, 'index']);
Route::get('/submissions/{form}/{id}', [McpSubmissionController::class, 'show'])->whereNumber('id');
foreach (['assign', 'set_status', 'add_note', 'retry_notification'] as $action) {
    Route::post('/submissions/{form}/{id}/'.str_replace('_', '-', $action), [McpSubmissionController::class, 'write'])->defaults('action', $action)->whereNumber('id');
}

// Executable integrations require an explicit account permission and credential scope.
Route::get('/custom-code', [McpCustomCodeController::class, 'index']);
Route::get('/custom-code/destinations', [McpCustomCodeController::class, 'destinations']);
Route::post('/custom-code/evaluate', [McpCustomCodeController::class, 'evaluate']);
Route::post('/custom-code', [McpCustomCodeController::class, 'save']);
Route::get('/custom-code/{id}', [McpCustomCodeController::class, 'show'])->whereNumber('id');
Route::patch('/custom-code/{id}', [McpCustomCodeController::class, 'save'])->whereNumber('id');
Route::post('/custom-code/{id}/activate', [McpCustomCodeController::class, 'activate'])->whereNumber('id');
Route::post('/custom-code/{id}/deactivate', [McpCustomCodeController::class, 'deactivate'])->whereNumber('id');
