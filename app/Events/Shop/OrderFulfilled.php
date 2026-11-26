<?php

namespace App\Events\Shop;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An order became `fulfilled` (App\Services\Shop\OrderOperations::fulfill(), once per order, after the commit):
 * the customer is told it is on its way or delivered (home delivery) or ready or picked up (pickup).
 */
final class OrderFulfilled
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Order $order) {}
}
