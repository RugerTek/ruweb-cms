<?php

namespace App\Events\Shop;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A customer placed an order (App\Services\Shop\CheckoutService::place(), after the transaction commits). Stock is
 * untouched (P3). Listeners send the confirmation to the customer and the notice to shop_orders_email.
 */
final class OrderPlaced
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Order $order) {}
}
