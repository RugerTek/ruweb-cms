<?php

namespace App\Events\Shop;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An order became `cancelled` (App\Services\Shop\OrderOperations, once per order). $reason is one of the REASON_*
 * constants, so the customer email can explain it.
 */
final class OrderCancelled
{
    use Dispatchable, SerializesModels;

    /** Cancelled from the panel or MCP by a person. */
    public const REASON_ADMIN = 'admin';

    /** An order with an automatic payment method stayed unpaid for cms.shop.checkout.unpaid_order_hours. */
    public const REASON_UNPAID = 'unpaid';

    public function __construct(
        public readonly Order $order,
        public readonly string $reason = self::REASON_ADMIN,
    ) {}
}
