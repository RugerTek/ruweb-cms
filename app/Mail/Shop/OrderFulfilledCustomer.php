<?php

namespace App\Mail\Shop;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your order is on its way" (home delivery) or "Your order is ready" (pickup, with the pickup address) to the
 * customer when the order is marked delivered (OrderFulfilled). The administrator's internal notes are never included.
 */
class OrderFulfilledCustomer extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
        $this->locale($order->locale);
        OrderPlacedCustomer::prepareQueue($this);
    }

    public function envelope(): Envelope
    {
        $key = $this->order->isPickup() ? 'checkout.mail_order_fulfilled_pickup_subject' : 'checkout.mail_order_fulfilled_delivery_subject';

        return OrderPlacedCustomer::customerEnvelope(__($key, ['number' => $this->order->number]));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.shop.order-fulfilled-customer',
            with: OrderPlacedCustomer::orderViewData($this->order),
        );
    }
}
