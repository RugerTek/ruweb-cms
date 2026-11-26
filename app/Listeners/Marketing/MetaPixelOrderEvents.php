<?php

namespace App\Listeners\Marketing;

use App\Events\Shop\OrderPaid;
use App\Events\Shop\OrderPlaced;
use App\Services\Marketing\MetaPixel;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * Order events of the Meta Pixel connector, wired by App\Providers\MetaPixelServiceProvider with Event::subscribe().
 * Method names do not start with `handle` on purpose (Laravel's listener discovery would register them twice, see
 * App\Listeners\Shop\SendOrderMails).
 *
 * - OrderPlaced (inside the customer's checkout request) → MetaPixel::rememberOrderContext(): the `_fbp`/`_fbc` of
 *   that visit and whether it is measured, for the Purchase sent later.
 * - OrderPaid (payment confirmed, often from the Bancard webhook) → MetaPixel::sendPurchase(): the server Purchase,
 *   event_id `purchase-{number}`, queued after the commit. The browser Purchase is shown on the order page.
 * Both swallow and log their errors (MetaPixel): the order and the payment are already stored.
 */
final class MetaPixelOrderEvents
{
    public function __construct(private readonly MetaPixel $pixel) {}

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderPlaced::class => 'onOrderPlaced',
            OrderPaid::class => 'onOrderPaid',
        ];
    }

    public function onOrderPlaced(OrderPlaced $event): void
    {
        try {
            $this->pixel->rememberOrderContext($event->order);
        } catch (Throwable $e) {
            $this->pixel->logFailure('Purchase', $e);
        }
    }

    public function onOrderPaid(OrderPaid $event): void
    {
        $this->pixel->sendPurchase($event->order);
    }
}
