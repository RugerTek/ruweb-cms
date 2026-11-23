<?php

/*
 * Checkout panel routes (PLAN.md E3.4, E3.5). Grouped from routes/admin.php whatever the shop mode (route:cache
 * compiles them once), inside the authenticated admin group (`auth`, `role:admin,editor`, EnsureAccountSession,
 * `admin.locale`, prefix `admin`, names `admin.*`), behind EnsureShopCatalog: 404 while the catalog is off. They do
 * not require the `checkout` mode, so orders placed before leaving it stay manageable and payments, shipping and the
 * orders email can be prepared before turning selling on. Full and Essential alike (no RequireLiveEditor).
 *
 * Orders: editors see them and mark them delivered; what each action allows is decided by App\Policies\OrderPolicy.
 * Payments, payment methods, Bancard keys and shipping zones: administrators only.
 */

use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\ShippingZoneController;
use App\Http\Controllers\Admin\ShopPaymentsController;
use Illuminate\Support\Facades\Route;

Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
Route::prefix('orders/{order}')->whereNumber('order')->name('orders.')->group(function () {
    Route::get('/', [OrderController::class, 'show'])->name('show');
    Route::post('transition', [OrderController::class, 'transition'])->name('transition');
    Route::post('note', [OrderController::class, 'note'])->name('note');
    Route::post('resend', [OrderController::class, 'resend'])->name('resend');
});

Route::middleware('role:admin')->group(function () {
    Route::prefix('payments/{payment}')->whereNumber('payment')->name('payments.')->group(function () {
        Route::post('confirm', [PaymentController::class, 'confirm'])->name('confirm');
        Route::post('fail', [PaymentController::class, 'fail'])->name('fail');
        Route::post('refund', [PaymentController::class, 'refund'])->name('refund');
        Route::post('refund-manual', [PaymentController::class, 'refundManual'])->name('refund-manual');
        Route::post('resolve', [PaymentController::class, 'resolve'])->name('resolve');
    });

    // Settings of selling online: payment methods, Bancard keys, pickup, delivery zones and the orders email.
    Route::get('shop/payments', [ShopPaymentsController::class, 'edit'])->name('shop-payments.edit');
    Route::put('shop/payments', [ShopPaymentsController::class, 'update'])->name('shop-payments.update');
    Route::put('shop/payments/bancard', [ShopPaymentsController::class, 'bancard'])->name('shop-payments.bancard');
    Route::post('shop/payments/bancard/test', [ShopPaymentsController::class, 'bancardTest'])->name('shop-payments.bancard-test');
    Route::put('shop/payments/bancard/environment', [ShopPaymentsController::class, 'environment'])->name('shop-payments.environment');

    Route::post('shop/shipping-zones', [ShippingZoneController::class, 'store'])->name('shipping-zones.store');
    Route::put('shop/shipping-zones/{zone}', [ShippingZoneController::class, 'update'])->whereNumber('zone')->name('shipping-zones.update');
    Route::delete('shop/shipping-zones/{zone}', [ShippingZoneController::class, 'destroy'])->whereNumber('zone')->name('shipping-zones.destroy');
});
