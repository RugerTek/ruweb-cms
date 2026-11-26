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
 * Warning to the shop's orders email that the provider approved a charge that does not belong to a paid order
 * (App\Services\Shop\StrayChargeOperations): $reason `late` (the attempt had already failed or expired) or
 * `amount_mismatch` (another amount). $reversed: Bancard rolled it back and nothing else is needed; otherwise ($code:
 * TransactionAlreadyConfirmed, timeout…) a person must review it and, if Bancard already settled it, return the money
 * by hand with Bancard and mark it resolved in the panel. Panel language, link to the order in the panel.
 */
class LatePaymentStore extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order, public Payment $payment, public bool $reversed, public ?string $code = null, public string $reason = 'late')
    {
        $this->locale(OrderPlacedStore::panelLocale());
        OrderPlacedCustomer::prepareQueue($this);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: OrderPlacedCustomer::sender(),
            subject: __('checkout.mail_late_payment_subject', ['number' => $this->order->number]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.shop.late-payment-store',
            with: [
                'order' => $this->order,
                'amount' => $this->payment->amount->format(),
                'reason' => $this->reason,
                'reported' => PaymentAmountMismatchStore::reportedAmount($this->payment),
                'status' => __('checkout.payment_status_'.$this->payment->status),
                'reversed' => $this->reversed,
                'code' => $this->code ?? '—',
                'siteName' => OrderPlacedCustomer::siteName(),
                'panelUrl' => route('admin.orders.show', ['order' => $this->order->getKey()]),
            ],
        );
    }
}
