<?php

namespace App\Mail\Shop;

use App\Models\Order;
use App\Models\Payment;
use App\Shop\Payments\Bancard\BancardProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Warning to the shop's orders email that a verified payment notification reported another amount or currency than
 * the attempt (PaymentFailed REASON_AMOUNT_MISMATCH, PLAN.md E3.2 step 6): the attempt was marked failed and the
 * order is still pending. Shows what the order expected and what the provider reported, and links to the order in
 * the panel. Panel language; never the customer's token link.
 */
class PaymentAmountMismatchStore extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order, public Payment $payment)
    {
        $this->locale(OrderPlacedStore::panelLocale());
        OrderPlacedCustomer::prepareQueue($this);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: OrderPlacedCustomer::sender(),
            subject: __('checkout.mail_amount_mismatch_subject', ['number' => $this->order->number]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.shop.payment-amount-mismatch-store',
            with: [
                'order' => $this->order,
                'expected' => $this->payment->amount->format(),
                'reported' => self::reportedAmount($this->payment),
                'siteName' => OrderPlacedCustomer::siteName(),
                'panelUrl' => route('admin.orders.show', ['order' => $this->order->getKey()]),
            ],
        );
    }

    /** Amount the provider reported: formatted when it is whole guaraníes or another currency code, else as sent; "—" when absent. */
    public static function reportedAmount(Payment $payment): string
    {
        $payload = $payment->payload_json ?? [];
        $operation = $payload['operation'] ?? $payload['confirmation'] ?? $payload;
        $amount = is_array($operation) ? ($operation['amount'] ?? null) : null;
        $currency = is_array($operation) ? ($operation['currency'] ?? null) : null;
        if (! is_scalar($amount)) {
            return '—';
        }
        $money = BancardProvider::money((string) $amount, is_string($currency) ? $currency : '');

        return $money !== null ? $money->format() : trim($amount.' '.(is_scalar($currency) ? $currency : ''));
    }
}
