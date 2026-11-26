<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Shop\PaymentOperations;
use App\Services\Shop\StrayChargeOperations;
use App\Shop\Payments\ManualPaymentProvider;
use App\Shop\Payments\PaymentProviderRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Payment attempts from the order screen (PLAN.md E3.6, track 3D1). Administrators only (`role:admin` on the routes
 * and OrderPolicy through PaymentOperations). Thin over PaymentOperations, which MCP shares.
 *
 * Money collected by an online provider (Bancard) is never confirmed by hand: "Confirm" on such an attempt asks the
 * provider (get_confirmation) and applies its verified answer ("Verify payment"). Failing or cancelling an attempt
 * that already reached the provider first asks it, and reverses it there (rollback) when it was not paid; when it
 * was, the attempt is confirmed instead and the administrator is told (settle()).
 */
class PaymentController extends Controller
{
    /** settle() outcomes. */
    public const SETTLED_NOT_APPLICABLE = 'not_applicable';

    public const SETTLED_PAID = 'paid';

    public const SETTLED_REJECTED = 'rejected';

    public const SETTLED_UNPAID = 'unpaid';

    public function __construct(
        private readonly PaymentOperations $payments,
        private readonly PaymentProviderRegistry $providers,
    ) {}

    /** Manual method: the merchant received the money. Online provider: "Verify payment" with the provider. */
    public function confirm(Payment $payment): RedirectResponse
    {
        $this->authorize('confirmPayment', $payment->order);

        if (! $this->isOnline($payment)) {
            $this->payments->confirm(auth()->user(), $payment);

            return $this->back($payment)->with('success', __('checkout.payment_confirmed_flash'));
        }

        return match ($this->settle($payment, rollbackUnpaid: false)) {
            self::SETTLED_PAID => $this->back($payment)->with('success', __('checkout.payment_confirmed_flash')),
            self::SETTLED_REJECTED => $this->back($payment)->with('error', __('checkout.payment_failed')),
            self::SETTLED_UNPAID => $this->back($payment)->with('error', __('checkout.payment_still_pending')),
            default => throw ValidationException::withMessages(['payment' => __('checkout.transition_invalid', ['status' => __('checkout.payment_status_'.$payment->status)])]),
        };
    }

    /** pending → failed. An online attempt is asked for and reversed at the provider first (settle()). */
    public function fail(Payment $payment): RedirectResponse
    {
        $this->authorize('failPayment', $payment->order);

        $outcome = $this->settle($payment, rollbackUnpaid: true);
        if ($outcome === self::SETTLED_PAID) {
            return $this->back($payment)->with('error', __('checkout.payment_paid_at_provider'));
        }
        if ($outcome !== self::SETTLED_REJECTED) {
            $this->payments->fail(auth()->user(), $payment->fresh());
        }

        return $this->back($payment)->with('success', __('checkout.payment_failed_flash'));
    }

    /** confirmed → refunded through the provider (Bancard rollback); when it cannot, the screen offers the manual mark. */
    public function refund(Payment $payment): RedirectResponse
    {
        $this->authorize('refund', $payment->order);

        $result = $this->payments->refund(auth()->user(), $payment);
        if ($result->ok) {
            return $this->back($payment)->with('success', __('checkout.payment_refunded_flash'));
        }

        return $this->back($payment)
            ->with('error', $result->manualRequired ? __('checkout.refund_manual_required') : $this->providerError($result->code));
    }

    /** confirmed → refunded without calling the provider: the merchant already returned the money by hand. */
    public function refundManual(Payment $payment): RedirectResponse
    {
        $this->authorize('refund', $payment->order);

        $this->payments->refund(auth()->user(), $payment, manual: true);

        return $this->back($payment)->with('success', __('checkout.payment_refunded_flash'));
    }

    /**
     * "Mark charge as resolved": a charge Bancard could not reverse (charged late or for another amount) was handled
     * with Bancard by hand; the administrator's note is kept in the attempt's history (StrayChargeOperations::resolve()).
     */
    public function resolve(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorize('refund', $payment->order);

        app(StrayChargeOperations::class)->resolve($request->user(), $payment, (string) $request->input('note', ''));

        return $this->back($payment)->with('success', __('checkout.late_payment_resolved_flash'));
    }

    /**
     * Bring a pending attempt that already reached an online provider (it has a process_id) in line with the
     * provider, before anyone touches it by hand: ask for its outcome and apply it when the answer verifies; when it
     * was not paid and $rollbackUnpaid, reverse it there so the customer cannot pay it afterwards. Returns one of the
     * SETTLED_* outcomes; SETTLED_NOT_APPLICABLE for manual methods, attempts not pending or never sent. Throws a
     * ValidationException (on `payment`) when the provider cannot be reached, its answer does not verify or the
     * reversal fails: nothing was changed and the caller must stop.
     *
     * Shared with OrderController::transition() (cancel an order with an online payment in progress).
     *
     * @throws ValidationException
     */
    public function settle(Payment $payment, bool $rollbackUnpaid): string
    {
        $payment = $payment->fresh();
        if (! $this->isOnline($payment) || ! $payment->isPending() || blank($payment->process_id)) {
            return self::SETTLED_NOT_APPLICABLE;
        }
        if (! $this->providers->has($payment->provider)) {
            throw ValidationException::withMessages(['payment' => __('checkout.payment_query_failed')]);
        }
        $provider = $this->providers->get($payment->provider);

        try {
            $notification = $provider->query($payment);
        } catch (RuntimeException $exception) {
            Log::warning('Panel payment query failed.', ['payment' => $payment->id, 'provider' => $payment->provider, 'exception' => $exception::class]);

            throw ValidationException::withMessages(['payment' => __('checkout.payment_query_failed')]);
        }

        if ($notification !== null) {
            if (! $provider->verify($notification, $payment)) {
                throw ValidationException::withMessages(['payment' => __('checkout.payment_unverified')]);
            }
            $payment = app(StrayChargeOperations::class)->apply($notification, $payment);

            return match ($payment->status) {
                Payment::STATUS_CONFIRMED => self::SETTLED_PAID,
                Payment::STATUS_PENDING => self::SETTLED_UNPAID,
                default => self::SETTLED_REJECTED,
            };
        }

        if ($rollbackUnpaid) {
            $result = $provider->cancel($payment);
            if (! $result->ok) {
                throw ValidationException::withMessages(['payment' => $result->manualRequired
                    ? __('checkout.payment_paid_at_provider')
                    : $this->providerError($result->code)]);
            }
        }

        return self::SETTLED_UNPAID;
    }

    private function isOnline(Payment $payment): bool
    {
        return $payment->provider !== ManualPaymentProvider::KEY;
    }

    private function providerError(?string $code): string
    {
        $key = 'checkout.bancard_errors.'.($code ?? 'unexpected');

        return __('checkout.provider_error', ['reason' => Lang::has($key) ? __($key) : __('checkout.bancard_errors.unexpected')]);
    }

    private function back(Payment $payment): RedirectResponse
    {
        return redirect()->route('admin.orders.show', $payment->order_id);
    }
}
