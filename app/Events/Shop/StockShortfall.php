<?php

namespace App\Events\Shop;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A paid order could not take all its stock (another customer paid first, PLAN.md E3.2 step 7 and R18): the payment
 * was accepted, the stock left at 0, and the administrator must restock or refund. $shortfall is the same list
 * stored in orders.stock_shortfall.
 */
final class StockShortfall
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<array{product_id: int|null, variant_id: int|null, title: string, requested: int, available: int}>  $shortfall
     */
    public function __construct(
        public readonly Order $order,
        public readonly array $shortfall,
    ) {}
}
