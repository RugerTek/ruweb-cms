<?php

namespace Tests\Support;

use App\Contracts\Shop\PaymentProvider;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Shop\Payments\CancelResult;
use App\Shop\Payments\PaymentNotification;
use App\Shop\Payments\PaymentStart;
use App\Shop\Payments\PaymentUrls;
use App\ValueObjects\Money;
use Illuminate\Http\Request;

/**
 * Online (iframe) payment provider for tests, so the cart, checkout and order page can be tested without the
 * Bancard tracks (PLAN.md E3.5). TestCase::useFakePaymentProvider() registers it under `fake` with an enabled
 * `fake` payment method. Configure the public properties; every call is recorded in $calls.
 *
 * Notifications are JSON `{"reference": "<payment id>", "approved": true, "amount": 150000, "signature": "valid"}`;
 * verify() accepts them when `signature` is `valid` and the reference is the attempt's id.
 */
final class FakePaymentProvider implements PaymentProvider
{
    public const KEY = 'fake';

    public const SCRIPT_URL = 'https://payments.fake.test/checkout.js';

    public const VALID_SIGNATURE = 'valid';

    /** What missing() reports for every method (empty = ready). @var list<string> */
    public array $missing = [];

    /** What query() returns; null = not paid yet. */
    public ?PaymentNotification $queryResult = null;

    public CancelResult $cancelResult;

    /** Make start() fail like an unreachable service. */
    public bool $failStart = false;

    /** @var list<array{method: string, payment_id: int|null}> */
    public array $calls = [];

    public function __construct()
    {
        $this->cancelResult = CancelResult::success('fake_cancelled');
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function isReady(PaymentMethod $method): bool
    {
        return $this->missing($method) === [];
    }

    public function missing(PaymentMethod $method): array
    {
        return $this->missing;
    }

    public function start(Payment $payment, PaymentUrls $urls): PaymentStart
    {
        $this->calls[] = ['method' => 'start', 'payment_id' => $payment->id];
        if ($this->failStart) {
            throw new \RuntimeException('Fake payment service unavailable.');
        }
        $payment->forceFill(['process_id' => 'fake-'.$payment->id])->save();

        return PaymentStart::iframe('fake-'.$payment->id, self::SCRIPT_URL);
    }

    public function parseNotification(Request $request): ?PaymentNotification
    {
        $this->calls[] = ['method' => 'parseNotification', 'payment_id' => null];
        $data = $request->json()->all();
        if ($data === [] || ! isset($data['reference'])) {
            return null;
        }

        return new PaymentNotification(
            provider: self::KEY,
            reference: (string) $data['reference'],
            approved: (bool) ($data['approved'] ?? false),
            amount: isset($data['amount']) ? Money::fromInt((int) $data['amount'], $data['currency'] ?? null) : null,
            responseCode: isset($data['code']) ? (string) $data['code'] : null,
            payload: $data,
        );
    }

    public function verify(PaymentNotification $notification, Payment $payment): bool
    {
        $this->calls[] = ['method' => 'verify', 'payment_id' => $payment->id];

        return ($notification->payload['signature'] ?? null) === self::VALID_SIGNATURE && $notification->reference === (string) $payment->id;
    }

    public function query(Payment $payment): ?PaymentNotification
    {
        $this->calls[] = ['method' => 'query', 'payment_id' => $payment->id];

        return $this->queryResult;
    }

    public function cancel(Payment $payment): CancelResult
    {
        $this->calls[] = ['method' => 'cancel', 'payment_id' => $payment->id];

        return $this->cancelResult;
    }

    /** A verified-looking notification for an attempt. */
    public function notificationFor(Payment $payment, bool $approved = true): PaymentNotification
    {
        $payload = ['reference' => (string) $payment->id, 'approved' => $approved, 'amount' => $payment->amount->amount, 'currency' => $payment->currency, 'signature' => self::VALID_SIGNATURE];

        return new PaymentNotification(self::KEY, (string) $payment->id, $approved, $payment->amount, $approved ? '00' : '51', payload: $payload);
    }

    /** Names of the recorded calls, in order. @return list<string> */
    public function calledMethods(): array
    {
        return array_column($this->calls, 'method');
    }
}
