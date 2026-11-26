<?php

namespace App\Mail\Shop;

use App\Models\Locale;
use App\Models\Order;
use App\Models\Setting;
use App\Services\Shop\CheckoutReadiness;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "We received your order" to the customer (PLAN.md E3.2, track 3E), in the order's language: summary, delivery,
 * how to pay (bank details, cash on delivery text, or the card button) and the link to the order page by token.
 * Queued after commit on the same connection as form notifications (App\Listeners\Shop\SendOrderMails). The plain
 * token is read when the mail is rendered, so it never travels in the queue payload. The static helpers are shared
 * by the other order mails.
 */
class OrderPlacedCustomer extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
        $this->locale($order->locale);
        self::prepareQueue($this);
    }

    public function envelope(): Envelope
    {
        return self::customerEnvelope(__('checkout.mail_order_placed_customer_subject', ['number' => $this->order->number]));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.shop.order-placed-customer',
            with: self::orderViewData($this->order),
        );
    }

    /**
     * The order page link in the order's language; null when the token cannot be read (another APP_KEY).
     */
    public static function orderUrl(Order $order): ?string
    {
        $token = $order->plainAccessToken();
        if ($token === null) {
            return null;
        }

        return $order->locale === Locale::baseCode()
            ? route('shop.order.show', ['token' => $token])
            : route('localized.shop.order.show', ['locale' => $order->locale, 'token' => $token]);
    }

    public static function siteName(): string
    {
        return (string) (Setting::get('site_name') ?: config('app.name'));
    }

    /** Sender of every order mail: the site's from address, as the contact form notification. */
    public static function sender(): Address
    {
        return new Address(
            (string) (Setting::get('mail_from_address') ?: config('mail.from.address')),
            (string) (Setting::get('mail_from_name') ?: config('mail.from.name')),
        );
    }

    /** Customer mails answer to the shop's orders email, so "reply to this email" reaches a person. */
    public static function customerEnvelope(string $subject): Envelope
    {
        $replyTo = app(CheckoutReadiness::class)->ordersEmail();

        return new Envelope(
            from: self::sender(),
            replyTo: $replyTo !== null ? [new Address($replyTo, self::siteName())] : [],
            subject: $subject,
        );
    }

    /**
     * What every order view needs. Only what the customer may see: no internal notes, IP, user agent or payment
     * provider details.
     *
     * @return array<string, mixed>
     */
    public static function orderViewData(Order $order): array
    {
        $order->loadMissing(['items', 'shippingAddress', 'paymentMethod']);

        return [
            'order' => $order,
            'siteName' => self::siteName(),
            'orderUrl' => self::orderUrl($order),
            'pickupAddress' => $order->isPickup() ? trim((string) Setting::get('shop_pickup_address')) : '',
        ];
    }

    /**
     * Queue on a real connection (`sync` would send inside the checkout request or the Bancard webhook, which must
     * answer in under 60 s) and only after the transaction that dispatched the event commits.
     */
    public static function prepareQueue(Mailable $mailable): void
    {
        $default = (string) config('queue.default');
        $mailable->onConnection($default === 'sync' ? 'database' : $default);
        $mailable->afterCommit();
    }
}
