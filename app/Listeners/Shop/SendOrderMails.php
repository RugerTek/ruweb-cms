<?php

namespace App\Listeners\Shop;

use App\Events\Shop\OrderCancelled;
use App\Events\Shop\OrderFulfilled;
use App\Events\Shop\OrderPaid;
use App\Events\Shop\OrderPlaced;
use App\Events\Shop\PaymentFailed;
use App\Events\Shop\StockShortfall;
use App\Mail\Shop\LatePaymentStore;
use App\Mail\Shop\OrderCancelledCustomer;
use App\Mail\Shop\OrderFulfilledCustomer;
use App\Mail\Shop\OrderPaidCustomer;
use App\Mail\Shop\OrderPlacedCustomer;
use App\Mail\Shop\OrderPlacedStore;
use App\Mail\Shop\PaymentAmountMismatchStore;
use App\Mail\Shop\StockShortfallStore;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\Forms\SubmissionNotifier;
use App\Services\Shop\CheckoutReadiness;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Order emails (PLAN.md E3.2, track 3E), wired by App\Providers\ShopServiceProvider with Event::subscribe(). It is
 * a subscriber whose methods do not start with `handle` on purpose: Laravel's listener discovery scans
 * app/Listeners for `handle*` methods and would register them a second time.
 *
 * - OrderPlaced → OrderPlacedCustomer to the customer + OrderPlacedStore to the orders email.
 * - OrderPaid → OrderPaidCustomer. OrderFulfilled → OrderFulfilledCustomer. OrderCancelled → OrderCancelledCustomer.
 *   StockShortfall → StockShortfallStore.
 * - PaymentFailed with REASON_AMOUNT_MISMATCH → PaymentAmountMismatchStore (once per attempt).
 * - latePayment(), called by StrayChargeOperations for a charge that does not belong to a paid order (late, or
 *   another amount) → LatePaymentStore (once per attempt and outcome).
 *
 * Every mail is queued (never sent inside the checkout request or the Bancard webhook) through the same mailer as
 * the contact form notifications (SubmissionNotifier::mailer()) and only while `mail_enabled` is on. Each mail goes
 * out once per order even if its event arrives twice: a cache marker is claimed atomically before queueing and
 * released if queueing fails. A failure is logged, never thrown: the order or payment is already stored.
 */
final class SendOrderMails
{
    /** How long a "this mail was queued" marker lasts; the events of one order arrive within days. */
    private const MARKER_DAYS = 30;

    public function __construct(
        private readonly SubmissionNotifier $notifier,
        private readonly CheckoutReadiness $readiness,
    ) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderPlaced::class => 'onOrderPlaced',
            OrderPaid::class => 'onOrderPaid',
            OrderFulfilled::class => 'onOrderFulfilled',
            OrderCancelled::class => 'onOrderCancelled',
            StockShortfall::class => 'onStockShortfall',
            PaymentFailed::class => 'onPaymentFailed',
        ];
    }

    public function onOrderPlaced(OrderPlaced $event): void
    {
        $order = $event->order;
        $this->deliver('placed-customer', $order, $order->customer_email, fn () => new OrderPlacedCustomer($order));
        $this->deliver('placed-store', $order, $this->readiness->ordersEmail(), fn () => new OrderPlacedStore($order));
    }

    public function onOrderPaid(OrderPaid $event): void
    {
        $this->deliver('paid-customer', $event->order, $event->order->customer_email, fn () => new OrderPaidCustomer($event->order, $event->payment));
    }

    public function onOrderFulfilled(OrderFulfilled $event): void
    {
        $this->deliver('fulfilled-customer', $event->order, $event->order->customer_email, fn () => new OrderFulfilledCustomer($event->order));
    }

    public function onOrderCancelled(OrderCancelled $event): void
    {
        $this->deliver('cancelled-customer', $event->order, $event->order->customer_email, fn () => new OrderCancelledCustomer($event->order, $event->reason));
    }

    public function onStockShortfall(StockShortfall $event): void
    {
        $this->deliver('stock-shortfall-store', $event->order, $this->readiness->ordersEmail(), fn () => new StockShortfallStore($event->order, $event->shortfall));
    }

    /** Only a different reported amount warns the store; rejections and admin failures are routine. */
    public function onPaymentFailed(PaymentFailed $event): void
    {
        if ($event->reason !== PaymentFailed::REASON_AMOUNT_MISMATCH) {
            return;
        }
        $payment = $event->payment;
        $order = $payment->order ?? $payment->order()->first();
        if ($order === null) {
            return;
        }
        $this->deliver('amount-mismatch-store:'.$payment->getKey(), $order, $this->readiness->ordersEmail(), fn () => new PaymentAmountMismatchStore($order, $payment));
    }

    /**
     * A provider approved a charge that does not belong to a paid order ($reason: StrayChargeOperations::REASON_LATE,
     * an attempt that had already failed or expired, or REASON_AMOUNT_MISMATCH): tell the store whether it was
     * reversed ($reversed) or needs review ($code says why). Once per attempt and outcome; false when not queued.
     */
    public function latePayment(Payment $payment, bool $reversed, ?string $code = null, string $reason = 'late'): bool
    {
        $order = $payment->order()->first();
        if ($order === null) {
            return false;
        }
        $kind = 'late-payment-store:'.$payment->getKey().':'.($reversed ? 'reversed' : 'review');

        return $this->deliver($kind, $order, $this->readiness->ordersEmail(), fn () => new LatePaymentStore($order, $payment, $reversed, $code, $reason));
    }

    /**
     * "Resend email to the customer" from the panel or MCP: the order confirmation with its link, without the
     * once-per-order guard. False when it could not be queued (mail off, no valid address, queue error).
     */
    public function resend(Order $order): bool
    {
        return $this->deliver('placed-customer', $order, $order->customer_email, fn () => new OrderPlacedCustomer($order), once: false);
    }

    /**
     * @param  Closure(): Mailable  $mailable
     */
    private function deliver(string $kind, Order $order, ?string $to, Closure $mailable, bool $once = true): bool
    {
        $context = ['order' => $order->number, 'mail' => $kind];
        $to = trim((string) $to);
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            Log::warning('Order mail skipped: no valid recipient.', $context);

            return false;
        }
        if (! Setting::get('mail_enabled')) {
            Log::info('Order mail skipped: mail is disabled in settings.', $context);

            return false;
        }

        $marker = 'shop:order-mail:'.$kind.':'.$order->getKey();
        if ($once && ! Cache::add($marker, now()->timestamp, now()->addDays(self::MARKER_DAYS))) {
            return false;
        }

        try {
            Mail::mailer($this->notifier->mailer())->to($to)->queue($mailable());

            return true;
        } catch (Throwable $exception) {
            if ($once) {
                Cache::forget($marker);
            }
            Log::warning('Order mail could not be queued: '.$exception->getMessage(), $context);

            return false;
        }
    }
}
