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
 * Warning to the shop's orders email that a paid order could not take all its stock (StockShortfall, PLAN.md E3.2
 * step 7): which lines fell short and a link to the order in the panel to restock or refund. Panel language.
 */
class StockShortfallStore extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{product_id: int|null, variant_id: int|null, title: string, requested: int, available: int}>  $shortfall
     */
    public function __construct(public Order $order, public array $shortfall)
    {
        $this->locale(OrderPlacedStore::panelLocale());
        OrderPlacedCustomer::prepareQueue($this);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: OrderPlacedCustomer::sender(),
            subject: __('checkout.mail_stock_shortfall_subject', ['number' => $this->order->number]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.shop.stock-shortfall-store',
            with: [
                'order' => $this->order,
                'lines' => $this->shortfall,
                'siteName' => OrderPlacedCustomer::siteName(),
                'panelUrl' => route('admin.orders.show', ['order' => $this->order->getKey()]),
            ],
        );
    }
}
