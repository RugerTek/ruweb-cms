<?php

use App\Http\Controllers\Admin\ConnectorController;
use App\Http\Controllers\Admin\MetaConnectController;
use App\Http\Controllers\Admin\MetaSyncController;
use Illuminate\Support\Facades\Route;

/*
 * Admin > Connectors (PLAN.md §4.1–4.4), required from routes/admin.php inside the `role:admin` group (prefix
 * `admin`, names `admin.`). Always registered: the section is visible to administrators on every site.
 * `{connector}` is a `cms.connectors` key; the controller answers 404 for unknown keys (ConnectorRegistry::get()).
 */
Route::get('connectors', [ConnectorController::class, 'index'])->name('connectors.index');

// Meta v2 «Conectar con Facebook» (plan-meta-v2): fixed paths, the RugerTek Connect broker redirects to `connected`.
Route::prefix('connectors/meta')->name('connectors.meta.')->group(function () {
    Route::get('connect', [MetaConnectController::class, 'connect'])->middleware('throttle:10,1')->name('connect');
    Route::get('connected', [MetaConnectController::class, 'connected'])->middleware('throttle:10,1')->name('connected');
    Route::get('catalog', [MetaConnectController::class, 'chooseCatalog'])->name('catalog');
    Route::post('catalog', [MetaConnectController::class, 'selectCatalog'])->middleware('throttle:10,1')->name('catalog.select');
    Route::post('disconnect', [MetaConnectController::class, 'disconnect'])->name('disconnect');
    // «Sincronizar ahora» of the feed created by API (track 2c, MetaCatalogSync::syncNow()).
    Route::post('sync', [MetaSyncController::class, 'sync'])->middleware('throttle:6,1')->name('sync');
});

Route::prefix('connectors/{connector}')->where(['connector' => '[a-z0-9_]+'])->name('connectors.')->group(function () {
    Route::get('/', [ConnectorController::class, 'show'])->name('show');
    Route::post('activate', [ConnectorController::class, 'activate'])->name('activate');
    Route::post('deactivate', [ConnectorController::class, 'deactivate'])->name('deactivate');
    Route::put('settings', [ConnectorController::class, 'updateSettings'])->name('settings');
    Route::post('rotate', [ConnectorController::class, 'rotateToken'])->name('rotate');
    Route::post('enable-catalog', [ConnectorController::class, 'enableCatalog'])->name('enable-catalog');
    // Meta Pixel only (other keys answer 404): settings with the encrypted access token, and the test event.
    Route::put('meta-pixel', [ConnectorController::class, 'updateMetaPixel'])->name('meta-pixel.update');
    Route::post('meta-pixel/test-event', [ConnectorController::class, 'sendMetaPixelTest'])->middleware('throttle:6,1')->name('meta-pixel.test');
    // Amiba chatbot only (other keys answer 404): the widget code and the test against Amiba's API.
    Route::put('amiba-chat', [ConnectorController::class, 'updateAmibaChat'])->name('amiba-chat.update');
    Route::post('amiba-chat/test', [ConnectorController::class, 'testAmibaChat'])->middleware('throttle:6,1')->name('amiba-chat.test');
});
