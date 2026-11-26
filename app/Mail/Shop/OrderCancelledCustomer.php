<?php

namespace App\Mail\Shop;

use App\Events\Shop\OrderCancelled;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your order was cancelled" to the customer (OrderCancelled). When it was cancelled for not being paid in time
 * (OrderCancelled::REASON_UNPAID) it says so; the administrator's internal notes are never included.
 */
class OrderCancelledCustomer extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order, public string $reason = OrderCancelled::REASON_ADMIN)
    {
        $this->locale($order->locale);
        OrderPlacedCustomer::prepareQueue($this);
    }

    public function envelope(): Envelope
    {
        return OrderPlacedCustomer::customerEnvelope(__('checkout.mail_order_cancelled_customer_subject', ['number' => $this->order->number]));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.shop.order-cancelled-customer',
            with: ['unpaid' => $this->reason === OrderCancelled::REASON_UNPAID] + OrderPlacedCustomer::orderViewData($this->order),
        );
    }
}
