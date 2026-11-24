<?php

use App\Http\Controllers\Admin\ConnectorController;
use Illuminate\Support\Facades\Route;

/*
 * Admin > Connectors (PLAN.md §4.1–4.4), required from routes/admin.php inside the `role:admin` group (prefix
 * `admin`, names `admin.`). Always registered: the section is visible to administrators on every site.
 * `{connector}` is a `cms.connectors` key; the controller answers 404 for unknown keys (ConnectorRegistry::get()).
 */
Route::get('connectors', [ConnectorController::class, 'index'])->name('connectors.index');

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
});
