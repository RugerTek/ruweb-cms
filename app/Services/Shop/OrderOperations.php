<?php

namespace App\Services\Shop;

use App\Events\Shop\OrderCancelled;
use App\Events\Shop\OrderFulfilled;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Shop\Payments\ManualPaymentProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Order writes shared by the panel, MCP and the scheduler (PLAN.md E3.2, track 3B), modelled on ProductOperations:
 * explicit actor (null = the system), Gate::forUser through App\Policies\OrderPolicy (editors see orders, keep
 * notes and mark them delivered; only administrators cancel, confirm or refund), one transaction per operation with
 * the order locked (lockForUpdate), and Order::TRANSITIONS. Money moves (paid, refunded) belong to the payment
 * attempts: transition() hands them to PaymentOperations.
 *
 * Repeating the transition the order already went through returns it unchanged, without side effects or events;
 * any other move its state does not allow is a ValidationException on `status`.
 */
final class OrderOperations
{
    /**
     * OrderPolicy ability each target status of transition() needs (`pending` is never a target).
     *
     * @var array<string, string>
     */
    public const TRANSITION_ABILITIES = [
        Order::STATUS_PAID => 'confirmPayment',
        Order::STATUS_FULFILLED => 'fulfill',
        Order::STATUS_CANCELLED => 'cancel',
        Order::STATUS_REFUNDED => 'refund',
    ];

    public function __construct(
        private readonly StockLedger $stock,
        private readonly PaymentOperations $payments,
    ) {}

    /**
     * One entry point for the panel's and MCP's status changes. `fulfilled` → fulfill(); `cancelled` → cancel();
     * `paid` → confirms the order's pending attempt; `refunded` → refunds its confirmed attempt through the provider
     * (when the provider cannot, a ValidationException explains it; the panel then offers
     * PaymentOperations::refund(..., manual: true)).
     */
    public function transition(User $actor, Order $order, string $status): Order
    {
        $ability = self::TRANSITION_ABILITIES[$status] ?? null;
        Gate::forUser($actor)->authorize($ability ?? 'view', $order);
        $current = Order::query()->findOrFail($order->id);
        if ($ability === null) {
            throw $this->invalid($current);
        }
        if ($current->status === $status) {
            return $current;
        }

        return match ($status) {
            Order::STATUS_FULFILLED => $this->fulfill($actor, $current),
            Order::STATUS_CANCELLED => $this->cancel($actor, $current),
            Order::STATUS_PAID => $this->confirmPending($actor, $current),
            Order::STATUS_REFUNDED => $this->refundConfirmed($actor, $current),
        };
    }

    /** paid → fulfilled (delivered or picked up). Any CMS role. OrderFulfilled (customer email) once, after the commit. */
    public function fulfill(User $actor, Order $order): Order
    {
        Gate::forUser($actor)->authorize('fulfill', $order);

        return DB::transaction(function () use ($order) {
            $order = $this->lock($order);
            if ($order->status === Order::STATUS_FULFILLED) {
                return $order;
            }
            $this->assertCan($order, Order::STATUS_FULFILLED);
            $order->forceFill(['status' => Order::STATUS_FULFILLED, 'fulfilled_at' => now()])->save();
            DB::afterCommit(fn () => event(new OrderFulfilled($order)));

            return $order;
        }, 3);
    }

    /**
     * pending|paid → cancelled. Administrators, or the system with OrderCancelled::REASON_UNPAID. A paid order gets
     * its stock back; its confirmed attempt stays confirmed until an administrator refunds it. Pending attempts
     * expire here without calling the provider: a caller cancelling an order whose automatic attempt already reached
     * the provider must query or cancel it there first (the Bancard sweep does; cancelUnpaid() skips those orders).
     */
    public function cancel(?User $actor, Order $order, string $reason = OrderCancelled::REASON_ADMIN): Order
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('cancel', $order);
        }

        return DB::transaction(function () use ($order, $reason) {
            $order = $this->lock($order);
            if ($order->status === Order::STATUS_CANCELLED) {
                return $order;
            }
            $this->assertCan($order, Order::STATUS_CANCELLED);

            $wasPaid = $order->status === Order::STATUS_PAID;
            $order->forceFill(['status' => Order::STATUS_CANCELLED, 'cancelled_at' => now()])->save();
            if ($wasPaid) {
                $this->stock->restore($order);
            }
            $order->payments()->where('status', Payment::STATUS_PENDING)->lockForUpdate()->get()
                ->each(fn (Payment $payment) => $payment->forceFill(['status' => Payment::STATUS_EXPIRED, 'expired_at' => now()])->save());
            DB::afterCommit(fn () => event(new OrderCancelled($order, $reason)));

            return $order;
        }, 3);
    }

    /** Replace the internal notes (only the site's team sees them). Any CMS role; null or blank clears them. */
    public function note(User $actor, Order $order, ?string $notes): Order
    {
        Gate::forUser($actor)->authorize('note', $order);
        Validator::make(['internal_notes' => $notes], ['internal_notes' => ['nullable', 'string', 'max:10000']])->validate();

        return DB::transaction(function () use ($order, $notes) {
            $order = $this->lock($order);
            $order->forceFill(['internal_notes' => filled($notes) ? trim($notes) : null])->save();

            return $order;
        }, 3);
    }

    /**
     * Cancel (REASON_UNPAID) the pending orders of an automatic payment method (any provider but `manual`) placed
     * more than cms.shop.checkout.unpaid_order_hours ago. Orders with a pending attempt that already reached the
     * provider (it has a process_id) are left for the provider's sweep to settle first. Manual methods are never
     * cancelled here: the administrator does it. Returns how many were cancelled.
     */
    public function cancelUnpaid(): int
    {
        $hours = (int) config('cms.shop.checkout.unpaid_order_hours', 48);
        $automatic = PaymentMethod::query()->where('provider', '!=', ManualPaymentProvider::KEY)->pluck('key');
        if ($hours <= 0 || $automatic->isEmpty()) {
            return 0;
        }

        $cancelled = 0;
        Order::query()
            ->where('status', Order::STATUS_PENDING)
            ->where('placed_at', '<=', now()->subHours($hours))
            ->whereIn('payment_method_key', $automatic)
            ->whereDoesntHave('payments', fn ($query) => $query->where('status', Payment::STATUS_PENDING)->whereNotNull('process_id'))
            ->orderBy('id')
            ->each(function (Order $order) use (&$cancelled) {
                try {
                    $before = $order->status;
                    $after = $this->cancel(null, $order, OrderCancelled::REASON_UNPAID);
                    $cancelled += $before !== $after->status ? 1 : 0;
                } catch (ValidationException) {
                    // Paid in the meantime: nothing to cancel.
                }
            });

        return $cancelled;
    }

    private function confirmPending(User $actor, Order $order): Order
    {
        $payment = $order->payments()->where('status', Payment::STATUS_PENDING)->latest('id')->first();
        if ($payment === null) {
            throw $this->invalid($order);
        }
        $this->payments->confirm($actor, $payment);

        return $order->fresh();
    }

    private function refundConfirmed(User $actor, Order $order): Order
    {
        $payment = $order->payments()->where('status', Payment::STATUS_CONFIRMED)->latest('id')->first();
        if ($payment === null) {
            throw $this->invalid($order);
        }
        $result = $this->payments->refund($actor, $payment);
        if (! $result->ok) {
            $message = match (true) {
                $result->manualRequired => __('checkout.refund_manual_required'),
                $result->code !== null && Lang::has('checkout.bancard_errors.'.$result->code) => __('checkout.bancard_errors.'.$result->code),
                default => __('checkout.transition_invalid', ['status' => __('checkout.status_'.$order->status)]),
            };

            throw ValidationException::withMessages(['status' => $message]);
        }

        return $order->fresh();
    }

    private function lock(Order $order): Order
    {
        return Order::query()->lockForUpdate()->findOrFail($order->id);
    }

    private function assertCan(Order $order, string $status): void
    {
        if (! $order->canTransitionTo($status)) {
            throw $this->invalid($order);
        }
    }

    private function invalid(Order $order): ValidationException
    {
        return ValidationException::withMessages([
            'status' => __('checkout.transition_invalid', ['status' => __('checkout.status_'.$order->status)]),
        ]);
    }
}
