<?php

namespace App\Services\Shop;

use App\Http\Controllers\Admin\OrderController;
use App\Listeners\Shop\SendOrderMails;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Shop\Payments\Bancard\BancardProvider;
use App\Shop\Payments\PaymentNotification;
use App\Shop\Payments\PaymentProviderRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Money a provider collected that does not belong to a paid order (decisions of Hermes, stage 3): an approval that
 * arrives for an attempt that had already failed or expired (charged late), or an approval for another amount or
 * currency (the attempt fails, PaymentOperations::applyNotification()). Such a charge is never confirmed in silence:
 *
 * 1. apply() is how every caller applies a verified provider answer (the Bancard webhook, the order page on return,
 *    the abandoned-payment sweep, "Verify payment" in the panel and MCP): PaymentOperations::applyNotification()
 *    and then, for a stray charge, one automatic reversal at the provider (Bancard rollback), outside any
 *    transaction.
 * 2. The outcome is a payment_event: BancardProvider::EVENT_LATE_PAYMENT_REVERSED (reversed) or
 *    EVENT_LATE_PAYMENT_REVIEW (could not: already settled, or unreachable), payload {status, code, manual_required,
 *    reason}; `reason` is REASON_LATE or REASON_AMOUNT_MISMATCH.
 * 3. The store hears about it by mail (SendOrderMails::latePayment()), reversed or to review; when another amount
 *    failed a pending attempt the store already got PaymentAmountMismatchStore, so only a failed reversal mails again.
 * 4. A charge left to review shows in Admin > Orders until an administrator marks it resolved (resolve(), event
 *    EVENT_LATE_PAYMENT_RESOLVED with their note), after handling it with Bancard by hand.
 *
 * A reversal is tried once: a repeated notification does nothing once reversed, resolved or reported as settled
 * (manual_required); after a network failure the next notification tries again.
 */
final class StrayChargeOperations
{
    public const REASON_LATE = 'late';

    public const REASON_AMOUNT_MISMATCH = 'amount_mismatch';

    /** Longest note of resolve(). */
    public const NOTE_MAX = 1000;

    public function __construct(
        private readonly PaymentOperations $payments,
        private readonly PaymentProviderRegistry $providers,
        private readonly SendOrderMails $mails,
    ) {}

    /**
     * Apply a provider answer the caller already verified (PaymentProvider::verify()) and reverse it at the
     * provider when it is a stray charge. Returns the attempt as applyNotification() left it.
     */
    public function apply(PaymentNotification $notification, Payment $payment): Payment
    {
        $before = (string) Payment::query()->whereKey($payment->id)->value('status');
        $after = $this->payments->applyNotification($notification, $payment);

        $reason = $this->strayReason($notification, $before, $after);
        if ($reason !== null) {
            // Another amount on a pending attempt failed it, and PaymentFailed already warned the store.
            $this->reverse($after, $reason, warned: $reason === self::REASON_AMOUNT_MISMATCH && $before === Payment::STATUS_PENDING);
        }

        return $after;
    }

    /**
     * "Mark charge as resolved" (Admin > Orders): an administrator handled with Bancard a charge left to review and
     * says what they did. Records EVENT_LATE_PAYMENT_RESOLVED; the attempt and the order do not change.
     *
     * @throws ValidationException (`payment`) when the attempt has no charge pending review, (`note`) without a note
     */
    public function resolve(User $actor, Payment $payment, string $note): PaymentEvent
    {
        Gate::forUser($actor)->authorize('refund', $payment->order()->firstOrFail());
        $note = trim($note);
        $attribute = __('checkout.late_payment_resolve_note');
        if ($note === '') {
            throw ValidationException::withMessages(['note' => __('validation.required', ['attribute' => $attribute])]);
        }
        if (mb_strlen($note) > self::NOTE_MAX) {
            throw ValidationException::withMessages(['note' => __('validation.max.string', ['attribute' => $attribute, 'max' => self::NOTE_MAX])]);
        }
        if (! $this->needsReview($payment)) {
            throw ValidationException::withMessages(['payment' => __('checkout.late_payment_not_in_review')]);
        }

        return PaymentEvent::create([
            'payment_id' => $payment->id,
            'provider' => $payment->provider,
            'type' => BancardProvider::EVENT_LATE_PAYMENT_RESOLVED,
            'token_valid' => null,
            'payload_json' => ['note' => $note, 'user_id' => $actor->id, 'user' => $actor->name],
        ]);
    }

    /**
     * What Admin > Orders flags (OrderController::latePayments()): the latest stray-charge event is "review", or no
     * such event exists but a verified approval was recorded over a failed or expired attempt (a provider that does
     * not reverse charges).
     */
    public function needsReview(Payment $payment): bool
    {
        if (! in_array($payment->status, [Payment::STATUS_FAILED, Payment::STATUS_EXPIRED], true)) {
            return false;
        }
        $events = PaymentEvent::query()->where('payment_id', $payment->id)
            ->whereIn('type', [PaymentEvent::TYPE_NOTIFICATION, ...BancardProvider::LATE_PAYMENT_EVENTS])
            ->orderBy('id')->get();
        $handled = $events->whereIn('type', BancardProvider::LATE_PAYMENT_EVENTS)->last();
        if ($handled !== null) {
            return $handled->type === BancardProvider::EVENT_LATE_PAYMENT_REVIEW;
        }

        return $events->contains(fn (PaymentEvent $event): bool => OrderController::isLateApproval($event));
    }

    /** REASON_* when an approved charge does not belong to a paid order; null otherwise. */
    private function strayReason(PaymentNotification $notification, string $before, Payment $after): ?string
    {
        $closed = [Payment::STATUS_FAILED, Payment::STATUS_EXPIRED];
        if (! $notification->approved || ! in_array($after->status, $closed, true)) {
            return null;
        }
        if (! $notification->matchesAmount($after->amount)) {
            return self::REASON_AMOUNT_MISMATCH;
        }

        return in_array($before, $closed, true) ? self::REASON_LATE : null;
    }

    private function reverse(Payment $payment, string $reason, bool $warned): void
    {
        $previous = PaymentEvent::query()
            ->where('payment_id', $payment->id)
            ->whereIn('type', BancardProvider::LATE_PAYMENT_EVENTS)
            ->orderByDesc('id')
            ->first();
        if ($previous !== null && ($previous->type !== BancardProvider::EVENT_LATE_PAYMENT_REVIEW || ($previous->payload_json['manual_required'] ?? false) === true)) {
            return;
        }
        if (! $this->providers->has($payment->provider)) {
            Log::warning('Stray charge on a provider this site no longer has: review it by hand.', ['payment' => $payment->id, 'provider' => $payment->provider]);

            return;
        }

        $result = $this->providers->get($payment->provider)->cancel($payment);
        PaymentEvent::create([
            'payment_id' => $payment->id,
            'provider' => $payment->provider,
            'type' => $result->ok ? BancardProvider::EVENT_LATE_PAYMENT_REVERSED : BancardProvider::EVENT_LATE_PAYMENT_REVIEW,
            'token_valid' => true,
            'payload_json' => [
                'status' => $payment->status,
                'code' => $result->code,
                'manual_required' => $result->manualRequired,
                'reason' => $reason,
            ],
        ]);
        Log::warning('Provider approved a charge that does not belong to a paid order.', ['payment' => $payment->id, 'reason' => $reason, 'reversed' => $result->ok, 'code' => $result->code]);

        if (! $warned || ! $result->ok) {
            $this->mails->latePayment($payment, $result->ok, $result->code, $reason);
        }
    }
}
