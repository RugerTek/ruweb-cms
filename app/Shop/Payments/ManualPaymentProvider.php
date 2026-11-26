<?php

namespace App\Shop\Payments;

use App\Contracts\Shop\PaymentProvider;
use App\Models\Payment;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use LogicException;

/**
 * Bank transfer, cash on delivery and any other method the merchant settles by hand (provider `manual`). There is
 * no service behind it: start() returns the public instructions of the method, nothing ever notifies, and the
 * administrator confirms or fails the payment from the panel (App\Services\Shop\PaymentOperations).
 *
 * Settings (`payment_methods.settings_json`): `bank_transfer` takes the FIELDS below and needs REQUIRED; `cod` and
 * any other manual key take `instructions`, which they need.
 */
final class ManualPaymentProvider implements PaymentProvider
{
    public const KEY = 'manual';

    /**
     * Public fields each manual method shows to the customer, in display order.
     *
     * @var array<string, list<string>>
     */
    public const FIELDS = [
        'bank_transfer' => ['bank_name', 'account_holder', 'account_number', 'account_type', 'document_number', 'alias', 'instructions'],
        'cod' => ['instructions'],
    ];

    /**
     * Fields a manual method needs before it can be offered.
     *
     * @var array<string, list<string>>
     */
    public const REQUIRED = [
        'bank_transfer' => ['bank_name', 'account_holder', 'account_number'],
        'cod' => ['instructions'],
    ];

    /** Fields of a manual method that is neither bank_transfer nor cod. */
    public const DEFAULT_FIELDS = ['instructions'];

    /** CancelResult code: the payment was confirmed, the merchant returns the money by hand. */
    public const CODE_MANUAL_REFUND = 'manual_refund';

    /** CancelResult code: nothing was collected, so there is nothing to reverse. */
    public const CODE_NOTHING_TO_CANCEL = 'nothing_to_cancel';

    public function key(): string
    {
        return self::KEY;
    }

    /** @return list<string> */
    public static function fieldsFor(string $methodKey): array
    {
        return self::FIELDS[$methodKey] ?? self::DEFAULT_FIELDS;
    }

    /** @return list<string> */
    public static function requiredFor(string $methodKey): array
    {
        return self::REQUIRED[$methodKey] ?? self::DEFAULT_FIELDS;
    }

    public function isReady(PaymentMethod $method): bool
    {
        return $this->missing($method) === [];
    }

    public function missing(PaymentMethod $method): array
    {
        return array_values(array_filter(
            self::requiredFor($method->key),
            fn (string $field): bool => ! is_string($method->setting($field)),
        ));
    }

    public function start(Payment $payment, PaymentUrls $urls): PaymentStart
    {
        $method = $payment->method;
        if ($method === null) {
            throw new LogicException('Payment '.$payment->id.' names a payment method that does not exist.');
        }

        $instructions = [];
        foreach (self::fieldsFor($method->key) as $field) {
            $value = $method->setting($field);
            if (is_string($value)) {
                $instructions[$field] = trim($value);
            }
        }

        return PaymentStart::instructions($instructions);
    }

    public function parseNotification(Request $request): ?PaymentNotification
    {
        return null;
    }

    /** Manual methods have no notifications: nothing a request says is trusted. */
    public function verify(PaymentNotification $notification, Payment $payment): bool
    {
        return false;
    }

    public function query(Payment $payment): ?PaymentNotification
    {
        return null;
    }

    public function cancel(Payment $payment): CancelResult
    {
        return in_array($payment->status, [Payment::STATUS_CONFIRMED, Payment::STATUS_REFUNDED], true)
            ? CancelResult::manual(self::CODE_MANUAL_REFUND)
            : CancelResult::success(self::CODE_NOTHING_TO_CANCEL);
    }
}
