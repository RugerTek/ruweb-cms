<?php

use App\Http\Controllers\McpOrderController;
use Illuminate\Support\Facades\Route;

/*
 * REST mirror of the checkout MCP tools (PLAN.md E3.6, track 3F; docs/operations/mcp.md). Grouped from
 * routes/mcp.php whatever the shop mode, so it inherits the /mcp prefix and the mcp middleware group;
 * EnsureShopCatalog answers 404 while the catalog is off. One route per tool, named `mcp.<tool>` with dots:
 * orders_list, orders_get, orders_transition, orders_note, payments_confirm, payments_fail, payments_refund,
 * payment_methods_list, payment_methods_update (never credentials), shipping_zones_list, shipping_zones_update.
 */
Route::get('/orders', [McpOrderController::class, 'ordersList'])->name('mcp.orders.list');
Route::prefix('/orders/{order}')->whereNumber('order')->group(function () {
    Route::get('/', [McpOrderController::class, 'ordersGet'])->name('mcp.orders.get');
    Route::post('/transition', [McpOrderController::class, 'ordersTransition'])->name('mcp.orders.transition');
    Route::post('/note', [McpOrderController::class, 'ordersNote'])->name('mcp.orders.note');
});

Route::prefix('/payments/{payment}')->whereNumber('payment')->group(function () {
    Route::post('/confirm', [McpOrderController::class, 'paymentsConfirm'])->name('mcp.payments.confirm');
    Route::post('/fail', [McpOrderController::class, 'paymentsFail'])->name('mcp.payments.fail');
    Route::post('/refund', [McpOrderController::class, 'paymentsRefund'])->name('mcp.payments.refund');
});

Route::get('/payment-methods', [McpOrderController::class, 'paymentMethodsList'])->name('mcp.payment_methods.list');
Route::patch('/payment-methods/{method}', [McpOrderController::class, 'paymentMethodsUpdate'])
    ->where('method', '[a-z0-9_]+')->name('mcp.payment_methods.update');

Route::get('/shipping-zones', [McpOrderController::class, 'shippingZonesList'])->name('mcp.shipping_zones.list');
Route::patch('/shipping-zones', [McpOrderController::class, 'shippingZonesUpdate'])->name('mcp.shipping_zones.update');
