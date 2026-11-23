<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Services\Shop\StrayChargeOperations;
use App\Shop\Payments\Bancard\BancardProvider;
use App\Shop\Payments\PaymentNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Bancard confirmation URL, `POST /webhooks/bancard` (PLAN.md E3.2 step 6). Outside CSRF and sessions, and
 * independent of the shop mode so payments in flight are never lost. Bancard needs a 200 within 60 seconds or it
 * marks the confirmation invalid, so nothing slow runs here: mails are queued, and the only outgoing call is the
 * rollback of a stray charge (bounded by BancardClient's timeouts, far below 60 s).
 *
 * - Empty body / `{}` (monitoring ping every 5 minutes) → 200, nothing recorded.
 * - Unknown shop_process_id → 200 and a payment_event `unknown_payment`.
 * - Token that does not verify with the private key of the attempt's environment → 403 and a payment_event
 *   `invalid_token`; the attempt is untouched.
 * - Verified → StrayChargeOperations::apply() (PaymentOperations::applyNotification()): approved → confirmed (order
 *   paid, stock taken), rejected → failed, other amount or currency → failed + warning to the administrator; a
 *   repeated notification changes nothing. The state of an attempt never turns into a 5xx: Bancard gets its 200.
 * - Approved for an attempt that had already failed or expired (charged late), or for another amount → automatic
 *   rollback, a BancardProvider::EVENT_LATE_PAYMENT_* event and a mail to the orders email.
 *
 * The unknown/invalid events drop the (unverified) md5 token from what they store; logs never carry keys or tokens.
 */
class BancardWebhookController extends Controller
{
    public function __construct(
        private readonly BancardProvider $bancard,
        private readonly StrayChargeOperations $charges,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $notification = $this->bancard->parseNotification($request);
        if ($notification === null) {
            return $this->success();
        }

        $payment = BancardProvider::findPayment($notification->reference);
        if ($payment === null) {
            $this->record(null, PaymentEvent::TYPE_UNKNOWN_PAYMENT, null, $notification);
            Log::warning('Bancard notification for an unknown attempt.', ['shop_process_id' => $notification->reference]);

            return $this->success();
        }

        if (! $this->bancard->verify($notification, $payment)) {
            $this->record($payment, PaymentEvent::TYPE_INVALID_TOKEN, false, $notification);
            Log::warning('Bancard notification with an invalid token.', ['payment' => $payment->id]);

            return response()->json(['status' => 'error'], 403);
        }

        try {
            $this->charges->apply($notification, $payment);
        } catch (ValidationException $exception) {
            // A state the operations refuse is never Bancard's problem: it gets its 200, the log keeps the reason.
            Log::warning('Bancard notification not applied: '.json_encode($exception->errors()), ['payment' => $payment->id]);

            return $this->success();
        }

        return $this->success();
    }

    private function record(?Payment $payment, string $type, ?bool $tokenValid, PaymentNotification $notification): void
    {
        $payload = $notification->payload;
        foreach (['operation', 'confirmation'] as $key) {
            if (is_array($payload[$key] ?? null)) {
                $payload[$key] = Arr::except($payload[$key], ['token']);
            }
        }

        PaymentEvent::create([
            'payment_id' => $payment?->id,
            'provider' => BancardProvider::KEY,
            'type' => $type,
            'token_valid' => $tokenValid,
            'payload_json' => $payload,
        ]);
    }

    private function success(): JsonResponse
    {
        return response()->json(['status' => 'success']);
    }
}
