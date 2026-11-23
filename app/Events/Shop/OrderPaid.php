<?php

namespace App\Events\Shop;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An order became `paid` because $payment was confirmed (App\Services\Shop\PaymentOperations::confirm(), once per
 * order, after stock was taken). If stock fell short, StockShortfall is dispatched too.
 */
final class OrderPaid
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly Payment $payment,
    ) {}
}
