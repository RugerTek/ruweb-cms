<?php

namespace App\Contracts\Shop;

use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Shop\Payments\CancelResult;
use App\Shop\Payments\PaymentNotification;
use App\Shop\Payments\PaymentStart;
use App\Shop\Payments\PaymentUrls;
use Illuminate\Http\Request;

/**
 * A way to collect the payment of an order (PLAN.md E3.5): registered in cms.shop.payment_providers under key(),
 * resolved through App\Shop\Payments\PaymentProviderRegistry, and named by the `provider` column of
 * payment_methods and payments. Providers talk to their service and translate its answers; they never change
 * orders, payments or stock themselves: App\Services\Shop\PaymentOperations applies what they report.
 *
 * Providers must not put credentials in exceptions, logs, events or the DTOs they return.
 */
interface PaymentProvider
{
    /** Registry key, equal to the `provider` column (`manual`, `bancard`). */
    public function key(): string;

    /** Whether the method has everything it needs to take payments on this site (missing() is empty). */
    public function isReady(PaymentMethod $method): bool;

    /**
     * What the method still lacks, as stable snake_case identifiers (a field such as `bank_name`, or a condition
     * such as `credentials_unreadable`); the panel turns each into a lang key. Empty when ready.
     *
     * @return list<string>
     */
    public function missing(PaymentMethod $method): array;

    /**
     * Begin collecting a pending attempt: instructions for manual methods, or an embedded form (iframe) for an
     * online gateway. May fill provider fields of the attempt (process_id) and save it.
     *
     * @throws \RuntimeException when the service cannot be reached or rejects the request (without credentials in the message)
     */
    public function start(Payment $payment, PaymentUrls $urls): PaymentStart;

    /**
     * Read a provider notification (webhook). Null when the request carries none: a monitoring ping (Bancard posts an
     * empty JSON every 5 minutes) or a provider without notifications. Parsing does not authenticate: call verify().
     */
    public function parseNotification(Request $request): ?PaymentNotification;

    /**
     * Whether the notification is authentic for this attempt, checked with the credentials of the environment the
     * attempt was created in. Amount and currency are compared by the caller, not here.
     */
    public function verify(PaymentNotification $notification, Payment $payment): bool;

    /**
     * Ask the service for the outcome of an attempt (Bancard get_confirmation). Null when there is none yet (not
     * paid, or a provider without a remote state).
     *
     * @throws \RuntimeException when the service cannot be reached
     */
    public function query(Payment $payment): ?PaymentNotification;

    /**
     * Reverse an attempt at the service (Bancard rollback): a confirmed payment to refund it, or an abandoned one.
     * When the service can no longer do it, the result says the merchant must do it by hand.
     */
    public function cancel(Payment $payment): CancelResult;
}
