<?php

namespace App\Mail\Shop;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "New order" to the shop's orders email (CheckoutReadiness::ordersEmail()), in the panel language: customer,
 * delivery, items, payment method and a link to the order in the panel. Never the customer's token link: the shop
 * manages the order from the panel. Replies go to the customer.
 */
class OrderPlacedStore extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
        $this->locale(self::panelLocale());
        OrderPlacedCustomer::prepareQueue($this);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: OrderPlacedCustomer::sender(),
            replyTo: [new Address($this->order->customer_email, $this->order->customer_name)],
            subject: __('checkout.mail_order_placed_store_subject', ['number' => $this->order->number]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.shop.order-placed-store',
            with: ['panelUrl' => route('admin.orders.show', ['order' => $this->order->getKey()])] + OrderPlacedCustomer::orderViewData($this->order),
        );
    }

    /** Store mails speak the panel's language (Settings › admin_locale), like the panel itself. */
    public static function panelLocale(): string
    {
        $locale = Setting::get('admin_locale', 'es');

        return is_string($locale) && $locale !== '' ? $locale : 'es';
    }
}
