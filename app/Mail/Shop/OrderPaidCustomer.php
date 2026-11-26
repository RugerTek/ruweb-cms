<?php

namespace App\Mail\Shop;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Payment confirmed" to the customer when an order becomes `paid` (OrderPaid). Shows the amount, the date and, for
 * card payments, the authorization code; never the provider's response code, extended description or security data
 * (PLAN.md E3.1, response page rules).
 */
class OrderPaidCustomer extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order, public Payment $payment)
    {
        $this->locale($order->locale);
        OrderPlacedCustomer::prepareQueue($this);
    }

    public function envelope(): Envelope
    {
        return OrderPlacedCustomer::customerEnvelope(__('checkout.mail_order_paid_customer_subject', ['number' => $this->order->number]));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.shop.order-paid-customer',
            with: [
                'paidAt' => $this->payment->confirmed_at ?? $this->order->paid_at ?? now(),
                'paidAmount' => $this->payment->amount,
                'authorizationNumber' => $this->payment->authorization_number,
            ] + OrderPlacedCustomer::orderViewData($this->order),
        );
    }
}
