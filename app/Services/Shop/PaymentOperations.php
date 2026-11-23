<?php

namespace App\Services\Shop;

use App\Events\Shop\OrderPaid;
use App\Events\Shop\PaymentFailed;
use App\Events\Shop\StockShortfall;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Shop\Payments\CancelResult;
use App\Shop\Payments\PaymentNotification;
use App\Shop\Payments\PaymentProviderRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Payment attempt writes shared by the provider webhooks, the scheduler, the panel and MCP (PLAN.md E3.2, track
 * 3B), modelled on ProductOperations: explicit actor (null = the system: a verified provider notification or the
 * scheduler, never a person), Gate::forUser through App\Policies\OrderPolicy, one transaction per operation with
 * the order and then the attempt locked (lockForUpdate), and Payment::TRANSITIONS.
 *
 * Every operation is idempotent: repeating the transition the attempt already went through returns it unchanged,
 * without side effects or events; any other move a state does not allow is a ValidationException on `payment`.
 * Events are dispatched after the transaction commits, once: OrderPaid when the order becomes paid, StockShortfall
 * when its stock fell short, PaymentFailed when an attempt fails.
 */
final class PaymentOperations
{
    public function __construct(
        private readonly StockLedger $stock,
        private readonly PaymentProviderRegistry $providers,
    ) {}

    /**
     * pending → confirmed. A pending order becomes paid and takes its stock (P3); if the stock falls short the
     * payment is still accepted (decision 6.2). Another attempt confirmed after the order was already paid is only
     * recorded. $notification (verified by the caller) fills the authorization data.
     */
    public function confirm(?User $actor, Payment $payment, ?PaymentNotification $notification = null): Payment
    {
        $this->authorize($actor, 'confirmPayment', $payment);

        return DB::transaction(function () use ($payment, $notification) {
            [$order, $payment] = $this->lock($payment);
            if ($payment->status === Payment::STATUS_CONFIRMED) {
                return $payment;
            }
            $this->assertCan($payment, Payment::STATUS_CONFIRMED);

            $payment->forceFill(['status' => Payment::STATUS_CONFIRMED, 'confirmed_at' => now()] + $this->notificationAttributes($notification))->save();
            if ($order->status === Order::STATUS_PENDING) {
                $order->forceFill(['status' => Order::STATUS_PAID, 'paid_at' => now()])->save();
                $shortfall = $this->stock->take($order);
                DB::afterCommit(fn () => event(new OrderPaid($order, $payment)));
                if ($shortfall !== []) {
                    DB::afterCommit(fn () => event(new StockShortfall($order, $shortfall)));
                }
            }

            return $payment;
        }, 3);
    }

    /**
     * pending → failed. The order stays pending and the customer can try again. $reason defaults to
     * PaymentFailed::REASON_ADMIN with an actor and REASON_REJECTED without one.
     */
    public function fail(?User $actor, Payment $payment, ?string $reason = null, ?PaymentNotification $notification = null): Payment
    {
        $this->authorize($actor, 'failPayment', $payment);
        $reason ??= $actor !== null ? PaymentFailed::REASON_ADMIN : PaymentFailed::REASON_REJECTED;

        return DB::transaction(function () use ($payment, $reason, $notification) {
            [, $payment] = $this->lock($payment);
            if ($payment->status === Payment::STATUS_FAILED) {
                return $payment;
            }
            $this->assertCan($payment, Payment::STATUS_FAILED);

            $payment->forceFill(['status' => Payment::STATUS_FAILED, 'failed_at' => now()] + $this->notificationAttributes($notification))->save();
            DB::afterCommit(fn () => event(new PaymentFailed($payment, $reason)));

            return $payment;
        }, 3);
    }

    /**
     * pending → expired, by the system only (abandoned attempt after the provider said it was not paid). Anything
     * but a pending attempt is returned unchanged, so the sweep never fights a notification that won the race.
     */
    public function expire(Payment $payment, ?PaymentNotification $notification = null): Payment
    {
        return DB::transaction(function () use ($payment, $notification) {
            [, $payment] = $this->lock($payment);
            if ($payment->isPending()) {
                $payment->forceFill(['status' => Payment::STATUS_EXPIRED, 'expired_at' => now()] + $this->notificationAttributes($notification))->save();
            }

            return $payment;
        }, 3);
    }

    /**
     * confirmed → refunded, administrators only. Unless $manual (the merchant already returned the money by hand),
     * the provider reverses it first (Bancard rollback); when it cannot, nothing changes and the result says why
     * (`manualRequired`: offer "mark as refunded by hand"). Once refunded, a paid or fulfilled order with no other
     * confirmed attempt becomes refunded and gets its stock back.
     */
    public function refund(User $actor, Payment $payment, bool $manual = false): CancelResult
    {
        $this->authorize($actor, 'refund', $payment);
        $current = Payment::query()->findOrFail($payment->id);
        if ($current->status === Payment::STATUS_REFUNDED) {
            return CancelResult::success();
        }
        $this->assertCan($current, Payment::STATUS_REFUNDED);

        $result = $manual ? CancelResult::success() : $this->providers->get($current->provider)->cancel($current);
        if (! $result->ok) {
            return $result;
        }

        DB::transaction(function () use ($payment) {
            [$order, $payment] = $this->lock($payment);
            if ($payment->status === Payment::STATUS_REFUNDED) {
                return;
            }
            $this->assertCan($payment, Payment::STATUS_REFUNDED);

            $payment->forceFill(['status' => Payment::STATUS_REFUNDED, 'refunded_at' => now()])->save();
            $othersConfirmed = $order->payments()->whereKeyNot($payment->id)->where('status', Payment::STATUS_CONFIRMED)->exists();
            if (! $othersConfirmed && $order->canTransitionTo(Order::STATUS_REFUNDED)) {
                $order->forceFill(['status' => Order::STATUS_REFUNDED, 'refunded_at' => now()])->save();
                $this->stock->restore($order);
            }
        }, 3);

        return $result;
    }

    /**
     * Apply what a provider reported about an attempt, from a webhook or a query. The caller has already verified it
     * (PaymentProvider::verify()); unknown attempts and bad signatures are the caller's to record. Records one
     * payment_event (`notification`, or `amount_mismatch`) and then: another amount or currency → the pending attempt
     * fails (REASON_AMOUNT_MISMATCH, which warns the administrator); approved → confirm(); rejected → fail(). A
     * notification for an attempt that already left `pending` (a repeated webhook, or an approval after it failed or
     * expired) changes nothing and is only recorded. Never throws for the attempt's state, so a webhook can always
     * answer 200.
     */
    public function applyNotification(PaymentNotification $notification, Payment $payment): Payment
    {
        return DB::transaction(function () use ($notification, $payment) {
            [, $locked] = $this->lock($payment);
            $mismatch = ! $notification->matchesAmount($locked->amount);
            PaymentEvent::create([
                'payment_id' => $locked->id,
                'provider' => $locked->provider,
                'type' => $mismatch ? PaymentEvent::TYPE_AMOUNT_MISMATCH : PaymentEvent::TYPE_NOTIFICATION,
                'token_valid' => true,
                'payload_json' => $notification->payload + ['status_before' => $locked->status],
            ]);
            if (! $locked->isPending()) {
                return $locked;
            }
            if ($mismatch) {
                return $this->fail(null, $locked, PaymentFailed::REASON_AMOUNT_MISMATCH, $notification);
            }

            return $notification->approved
                ? $this->confirm(null, $locked, $notification)
                : $this->fail(null, $locked, PaymentFailed::REASON_REJECTED, $notification);
        }, 3);
    }

    /** @return array{0: Order, 1: Payment} the order and the attempt, locked in that order */
    private function lock(Payment $payment): array
    {
        $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);
        $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
        $payment->setRelation('order', $order);

        return [$order, $payment];
    }

    private function authorize(?User $actor, string $ability, Payment $payment): void
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize($ability, $payment->order()->firstOrFail());
        }
    }

    private function assertCan(Payment $payment, string $status): void
    {
        if (! $payment->canTransitionTo($status)) {
            throw ValidationException::withMessages([
                'payment' => __('checkout.transition_invalid', ['status' => __('checkout.payment_status_'.$payment->status)]),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function notificationAttributes(?PaymentNotification $notification): array
    {
        if ($notification === null) {
            return [];
        }

        return array_filter([
            'authorization_number' => $notification->authorizationNumber,
            'ticket_number' => $notification->ticketNumber,
            'response_code' => $notification->responseCode,
            'response_description' => $notification->responseDescription !== null ? mb_substr($notification->responseDescription, 0, 255) : null,
            'payload_json' => $notification->payload !== [] ? $notification->payload : null,
        ], fn ($value) => $value !== null);
    }
}
