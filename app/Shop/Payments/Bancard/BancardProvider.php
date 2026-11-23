<?php

namespace App\Shop\Payments\Bancard;

use App\Contracts\Shop\PaymentProvider;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentMethod;
use App\Shop\Payments\CancelResult;
use App\Shop\Payments\PaymentNotification;
use App\Shop\Payments\PaymentStart;
use App\Shop\Payments\PaymentUrls;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use LogicException;

/**
 * Bancard vPOS 2.0 as a payment provider (PLAN.md E3.1, E3.2 steps 5–9), on top of BancardClient. It talks to
 * Bancard and translates its answers; orders, payments and stock change only through
 * App\Services\Shop\PaymentOperations (the webhook, the order page, the sweep and the panel call it).
 *
 * - Credentials are those of the environment the attempt was created in (`payments.environment`), so attempts of
 *   staging keep verifying with the staging key after the site switches to production.
 * - start() is idempotent: an attempt that already has a process_id gets the same iframe again without another
 *   single_buy (the order page calls it on every visit). Bancard refuses a second single_buy for the same
 *   shop_process_id, so a race between two visits ends with one process_id stored and the loser re-reading it.
 * - Notifications (webhook body `{"operation": {...}}`, or the get_confirmation answer `{"confirmation": {...}}`) keep
 *   what Bancard sent as payload; verify() recomputes md5(private + shop_process_id + "confirm" + amount + currency)
 *   with the received amount and currency and compares in constant time (BancardToken::matches). The official SDK
 *   compares without md5: not copied.
 * - Every API call leaves a payment_event `api_call` with a summary (operation, environment, ok, Bancard key, HTTP
 *   status): never a key, a token or the response body.
 *
 * Stray charges: an approval reported for an attempt that had already failed or expired, or for another amount, is
 * reversed (cancel()) by App\Services\Shop\StrayChargeOperations and recorded as EVENT_LATE_PAYMENT_REVERSED, or
 * EVENT_LATE_PAYMENT_REVIEW when Bancard could not reverse it (already settled, or unreachable) until an administrator
 * marks it EVENT_LATE_PAYMENT_RESOLVED: needsLateReview() / needingLateReview() are what the panel reads.
 */
final class BancardProvider implements PaymentProvider
{
    public const KEY = 'bancard';

    /** missing(): no valid active environment chosen. */
    public const MISSING_ENVIRONMENT = 'environment';

    /** missing(): the active environment has no complete key pair. */
    public const MISSING_CREDENTIALS = 'credentials';

    /** missing(): keys are stored but cannot be decrypted here (another APP_KEY). */
    public const MISSING_CREDENTIALS_UNREADABLE = 'credentials_unreadable';

    /** missing(): keys were saved under another site origin (restored copy, R20): load them again. */
    public const MISSING_CREDENTIALS_UNBOUND = 'credentials_unbound';

    /** BancardException reason when the attempt's environment has no usable keys on this site. */
    public const REASON_CREDENTIALS_UNAVAILABLE = 'credentials_unavailable';

    /**
     * start(): an earlier single_buy of this attempt got no answer (timeout or connection failure) and Bancard now
     * refuses a new one, most likely because it did create the buy. Its process_id is lost, so nobody can pay it:
     * the caller asks Bancard about the shop_process_id (query()) and, unless it was paid, closes the attempt so the
     * customer opens a new one with a new shop_process_id (OrderPageController).
     */
    public const REASON_START_UNCERTAIN = 'start_uncertain';

    /** CancelResult code: the attempt never reached Bancard (no process_id), nothing to reverse. */
    public const CODE_NOTHING_TO_CANCEL = 'nothing_to_cancel';

    /** CancelResult code: a confirmed attempt without process_id (confirmed by hand): return the money by hand. */
    public const CODE_NOT_AT_BANCARD = 'not_at_bancard';

    /** payment_event type: an approval arrived for a failed/expired attempt and Bancard reversed it. */
    public const EVENT_LATE_PAYMENT_REVERSED = 'late_payment_reversed';

    /** payment_event type: an approval arrived for a failed/expired attempt and could not be reversed: review it. */
    public const EVENT_LATE_PAYMENT_REVIEW = 'late_payment_review';

    /** payment_event type: an administrator handled a charge left to review and marked it resolved (with a note). */
    public const EVENT_LATE_PAYMENT_RESOLVED = 'late_payment_resolved';

    /** What was done about a stray charge (App\Services\Shop\StrayChargeOperations). */
    public const LATE_PAYMENT_EVENTS = [self::EVENT_LATE_PAYMENT_REVERSED, self::EVENT_LATE_PAYMENT_REVIEW, self::EVENT_LATE_PAYMENT_RESOLVED];

    /** Bancard `response` and `response_code` of an approved payment. */
    public const APPROVED_RESPONSE = 'S';

    public const APPROVED_CODE = '00';

    public function __construct(private readonly BancardClient $client) {}

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
        if (! in_array($method->environment, PaymentMethod::ENVIRONMENTS, true)) {
            return [self::MISSING_ENVIRONMENT];
        }
        if ($method->secretsUnreadable()) {
            return [self::MISSING_CREDENTIALS_UNREADABLE];
        }
        if ($method->credentials($method->environment) === null) {
            return [self::MISSING_CREDENTIALS];
        }
        if (! $method->isBoundToThisSite()) {
            return [self::MISSING_CREDENTIALS_UNBOUND];
        }

        return [];
    }

    /**
     * The iframe of a pending attempt: the stored process_id, or a new single_buy whose return (and cancel) URL is
     * the order page. Description: "Pedido <number>" in the order's language.
     *
     * @throws BancardException when the keys are unavailable, Bancard cannot be reached or rejects the buy
     * @throws LogicException when the attempt is not a pending Bancard attempt
     */
    public function start(Payment $payment, PaymentUrls $urls): PaymentStart
    {
        $payment = Payment::query()->with(['order', 'method'])->findOrFail($payment->id);
        if ($payment->provider !== self::KEY || ! $payment->isPending()) {
            throw new LogicException('Only a pending Bancard attempt can be started.');
        }
        $environment = (string) $payment->environment;
        if ($this->processId($payment) !== null) {
            return PaymentStart::iframe((string) $this->processId($payment), BancardClient::checkoutScriptUrl($environment));
        }

        $credentials = $this->startCredentials($payment);
        $order = $payment->order;
        $description = (string) __('checkout.order_title', ['number' => $order->number], $order->locale);
        $cancelUrl = $urls->cancelUrl !== $urls->returnUrl ? $urls->cancelUrl : null;

        try {
            $response = $this->client->singleBuy($credentials, BancardClient::shopProcessId($payment), $payment->amount, $description, $urls->returnUrl, $cancelUrl);
        } catch (BancardException $exception) {
            $this->recordCall($payment, BancardClient::OPERATION_SINGLE_BUY, null, $exception);

            throw $exception;
        }
        $this->recordCall($payment, BancardClient::OPERATION_SINGLE_BUY, $response);

        if (! $response->successful()) {
            // Another visit may have won the race: Bancard refuses the second single_buy of a shop_process_id.
            $stored = $this->processId($payment->fresh());
            if ($stored !== null) {
                return PaymentStart::iframe($stored, BancardClient::checkoutScriptUrl($environment));
            }
            if ($this->startTimedOutBefore($payment)) {
                throw new BancardException(self::REASON_START_UNCERTAIN, BancardClient::OPERATION_SINGLE_BUY, 'An earlier single_buy of this attempt got no answer and Bancard refuses a new one.', $response);
            }

            throw BancardException::fromResponse($response);
        }

        $processId = (string) $response->processId();
        $saved = Payment::query()->whereKey($payment->id)->whereNull('process_id')->update(['process_id' => $processId, 'updated_at' => now()]);
        if ($saved === 0) {
            $processId = $this->processId($payment->fresh()) ?? $processId;
        }

        return PaymentStart::iframe($processId, BancardClient::checkoutScriptUrl($environment));
    }

    /**
     * Null for the monitoring ping (empty body or `{}`); otherwise the notification as Bancard sent it, unverified.
     * A body without a usable shop_process_id gives an empty reference (the webhook records it as unknown).
     */
    public function parseNotification(Request $request): ?PaymentNotification
    {
        $body = json_decode($request->getContent(), true);
        if (! is_array($body) || $body === []) {
            return null;
        }
        $operation = is_array($body['operation'] ?? null) ? $body['operation'] : [];

        return self::notificationFrom($operation, $body);
    }

    /** Token of the attempt's environment over the received amount and currency, compared in constant time. */
    public function verify(PaymentNotification $notification, Payment $payment): bool
    {
        if ($notification->provider !== self::KEY || $payment->provider !== self::KEY
            || $notification->reference === '' || $notification->reference !== (string) $payment->shop_process_id) {
            return false;
        }
        $operation = self::operationOf($notification->payload);
        $amount = $operation['amount'] ?? null;
        $currency = $operation['currency'] ?? null;
        if (! is_string($amount) || ! is_string($currency)) {
            return false;
        }
        $method = $payment->method;
        $credentials = $method !== null && is_string($payment->environment)
            ? BancardCredentials::fromMethod($method, $payment->environment)
            : null;
        if ($credentials === null) {
            return false;
        }

        return BancardToken::matches(
            BancardToken::confirm($credentials->privateKey(), $notification->reference, $amount, $currency),
            $operation['token'] ?? null,
        );
    }

    /**
     * get_confirmation: the outcome (approved or rejected) as a notification to verify(), or null when nothing was
     * paid yet (PaymentNotFoundError) or Bancard does not know the buy (BuyNotFoundError).
     *
     * @throws BancardException when the keys are unavailable, Bancard cannot be reached or answers another error
     */
    public function query(Payment $payment): ?PaymentNotification
    {
        $credentials = $this->credentials($payment);
        if ($credentials === null) {
            throw new BancardException(self::REASON_CREDENTIALS_UNAVAILABLE, BancardClient::OPERATION_GET_CONFIRMATION, 'Bancard keys of the attempt environment are not available on this site.');
        }

        try {
            $response = $this->client->getConfirmation($credentials, BancardClient::shopProcessId($payment));
        } catch (BancardException $exception) {
            $this->recordCall($payment, BancardClient::OPERATION_GET_CONFIRMATION, null, $exception);

            throw $exception;
        }
        $this->recordCall($payment, BancardClient::OPERATION_GET_CONFIRMATION, $response);

        $confirmation = $response->confirmation();
        if ($response->successful() && $confirmation !== null) {
            return self::notificationFrom($confirmation, ['confirmation' => $confirmation]);
        }
        if ($response->successful() || $response->hasKey(BancardResponse::KEY_PAYMENT_NOT_FOUND, BancardResponse::KEY_BUY_NOT_FOUND)) {
            return null;
        }

        throw BancardException::fromResponse($response);
    }

    /**
     * rollback. ok: reversed (RollbackSuccessful, AlreadyRollbackedError) or nothing was charged (an attempt that
     * never reached Bancard, or an unconfirmed one Bancard has no payment for). manualRequired: Bancard already
     * settled it (TransactionAlreadyConfirmed), or a confirmed attempt never went through Bancard. Otherwise failed,
     * with the Bancard key or `timeout`/`connection`/`credentials_unavailable` as code. Never throws.
     */
    public function cancel(Payment $payment): CancelResult
    {
        $confirmed = in_array($payment->status, [Payment::STATUS_CONFIRMED, Payment::STATUS_REFUNDED], true);
        if ($this->processId($payment) === null) {
            return $confirmed ? CancelResult::manual(self::CODE_NOT_AT_BANCARD) : CancelResult::success(self::CODE_NOTHING_TO_CANCEL);
        }
        $credentials = $this->credentials($payment);
        if ($credentials === null) {
            return CancelResult::failed(self::REASON_CREDENTIALS_UNAVAILABLE);
        }

        try {
            $response = $this->client->rollback($credentials, BancardClient::shopProcessId($payment));
        } catch (BancardException $exception) {
            $this->recordCall($payment, BancardClient::OPERATION_ROLLBACK, null, $exception);

            return CancelResult::failed($exception->reason);
        }
        $this->recordCall($payment, BancardClient::OPERATION_ROLLBACK, $response);

        $key = $response->key();
        if ($response->successful() || $response->hasKey(BancardResponse::KEY_ALREADY_ROLLBACKED)) {
            return CancelResult::success($key ?? BancardResponse::KEY_ROLLBACK_SUCCESSFUL);
        }
        if ($response->hasKey(BancardResponse::KEY_TRANSACTION_ALREADY_CONFIRMED)) {
            return CancelResult::manual($key);
        }
        if (! $confirmed && $response->hasKey(BancardResponse::KEY_PAYMENT_NOT_FOUND, BancardResponse::KEY_BUY_NOT_FOUND)) {
            return CancelResult::success($key);
        }

        return CancelResult::failed($key ?? BancardException::UNEXPECTED);
    }

    /** The pending-or-not Bancard attempt a notification names, or null (unknown, or not a Bancard attempt). */
    public static function findPayment(string $reference): ?Payment
    {
        if ($reference === '' || ! ctype_digit($reference) || strlen($reference) > 15) {
            return null;
        }

        return Payment::query()->where('provider', self::KEY)->where('shop_process_id', (int) $reference)->first();
    }

    /**
     * Whether an attempt has a stray charge still to be reviewed by a person: its latest late-payment event is
     * EVENT_LATE_PAYMENT_REVIEW (Bancard could not reverse it and nobody marked it resolved yet).
     */
    public static function needsLateReview(Payment $payment): bool
    {
        $latest = PaymentEvent::query()
            ->where('payment_id', $payment->id)
            ->whereIn('type', self::LATE_PAYMENT_EVENTS)
            ->orderByDesc('id')
            ->first();

        return $latest?->type === self::EVENT_LATE_PAYMENT_REVIEW;
    }

    /**
     * Attempts with a stray charge that still needs review (a review event, neither reversed nor resolved), for the
     * panel.
     *
     * @return Builder<Payment>
     */
    public static function needingLateReview(): Builder
    {
        return Payment::query()
            ->whereHas('events', fn (Builder $query) => $query->where('type', self::EVENT_LATE_PAYMENT_REVIEW))
            ->whereDoesntHave('events', fn (Builder $query) => $query->whereIn('type', [self::EVENT_LATE_PAYMENT_REVERSED, self::EVENT_LATE_PAYMENT_RESOLVED]));
    }

    /**
     * Bancard amount string ("150000.00") as Money, or null when it is not a whole number of guaraníes.
     */
    public static function money(mixed $amount, mixed $currency): ?Money
    {
        if (! is_string($amount) || ! is_string($currency) || $currency === ''
            || preg_match('/^(\d{1,15})(?:\.(\d{1,2}))?$/', $amount, $parts) !== 1 || (int) ($parts[2] ?? 0) !== 0) {
            return null;
        }

        return new Money((int) $parts[1], $currency);
    }

    /**
     * @param  array<string, mixed>  $operation  webhook `operation` or get_confirmation `confirmation`
     * @param  array<string, mixed>  $payload  what Bancard sent, kept as is
     */
    private static function notificationFrom(array $operation, array $payload): PaymentNotification
    {
        $reference = $operation['shop_process_id'] ?? null;
        $reference = is_int($reference) || (is_string($reference) && ctype_digit($reference)) ? (string) $reference : '';
        $text = fn (string $key): ?string => is_scalar($operation[$key] ?? null) && (string) $operation[$key] !== '' ? (string) $operation[$key] : null;

        return new PaymentNotification(
            provider: self::KEY,
            reference: $reference,
            approved: $text('response') === self::APPROVED_RESPONSE && $text('response_code') === self::APPROVED_CODE,
            amount: self::money($operation['amount'] ?? null, $operation['currency'] ?? null),
            responseCode: $text('response_code'),
            responseDescription: $text('response_description'),
            authorizationNumber: $text('authorization_number'),
            ticketNumber: $text('ticket_number'),
            payload: $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function operationOf(array $payload): array
    {
        $operation = $payload['operation'] ?? $payload['confirmation'] ?? null;

        return is_array($operation) ? $operation : [];
    }

    private function processId(Payment $payment): ?string
    {
        return is_string($payment->process_id) && $payment->process_id !== '' ? $payment->process_id : null;
    }

    /** Keys of the attempt's environment, readable on this site. */
    private function credentials(Payment $payment): ?BancardCredentials
    {
        $method = $payment->method;
        if ($method === null || ! is_string($payment->environment)) {
            return null;
        }

        return BancardCredentials::fromMethod($method, $payment->environment);
    }

    /**
     * Keys to open a new buy: also bound to this site, so a copy restored elsewhere never charges (R20).
     *
     * @throws BancardException
     */
    /** Whether an earlier single_buy of the attempt ended without an answer (timeout or connection failure). */
    private function startTimedOutBefore(Payment $payment): bool
    {
        return PaymentEvent::query()
            ->where('payment_id', $payment->id)
            ->where('type', PaymentEvent::TYPE_API_CALL)
            ->get()
            ->contains(fn (PaymentEvent $event): bool => ($event->payload_json['operation'] ?? null) === BancardClient::OPERATION_SINGLE_BUY
                && in_array($event->payload_json['key'] ?? null, [BancardException::TIMEOUT, BancardException::CONNECTION], true));
    }

    private function startCredentials(Payment $payment): BancardCredentials
    {
        $credentials = $this->credentials($payment);
        if ($credentials === null || ! $payment->method->isBoundToThisSite()) {
            throw new BancardException(self::REASON_CREDENTIALS_UNAVAILABLE, BancardClient::OPERATION_SINGLE_BUY, 'Bancard keys of the attempt environment are not available on this site.');
        }

        return $credentials;
    }

    private function recordCall(Payment $payment, string $operation, ?BancardResponse $response, ?BancardException $exception = null): void
    {
        PaymentEvent::create([
            'payment_id' => $payment->id,
            'provider' => self::KEY,
            'type' => PaymentEvent::TYPE_API_CALL,
            'token_valid' => null,
            'payload_json' => array_filter([
                'operation' => $operation,
                'environment' => $payment->environment,
                'ok' => $response?->successful() ?? false,
                'key' => $response?->key() ?? $exception?->reason,
                'http_status' => $response?->httpStatus,
            ], fn ($value) => $value !== null),
        ]);
    }
}
