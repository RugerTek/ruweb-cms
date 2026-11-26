<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Mail\MailSettings;
use App\Services\Shop\CartService;
use App\Services\Shop\CheckoutService;
use App\Services\Shop\PaymentOperations;
use App\Services\Shop\StrayChargeOperations;
use App\Shop\Payments\Bancard\BancardException;
use App\Shop\Payments\Bancard\BancardProvider;
use App\Shop\Payments\PaymentProviderRegistry;
use App\Shop\Payments\PaymentStart;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;

/**
 * Order page by its random token (PLAN.md E3.2 step 4): summary, status and how to pay. Answers while the catalog
 * is on, whatever the mode, so orders placed before leaving `checkout` keep their page. An unknown token, or an
 * order number, is a 404 (R3). Never indexed, never sends the token in a Referer, never cached.
 *
 * Payment block, by the state of the latest attempt of a pending order:
 * - pending + manual method: the method's public instructions (bank details, cash on delivery text);
 * - pending + online method: the provider's embedded form (iframe), returning to this same page. Coming back with
 *   `?status=` from the provider is never trusted: if the notification has not arrived yet, the page asks the
 *   provider itself (PaymentProvider::query(), Bancard get_confirmation; at most once per attempt every
 *   QUERY_INTERVAL_SECONDS, since the page reloads itself) and applies a verified answer with
 *   StrayChargeOperations::apply() (a stray charge is reversed there); otherwise it says the payment is being confirmed and reloads;
 * - failed or expired: the outcome and a "try again" button (pay()), which opens a new attempt;
 * - confirmed: date, order number, amount, result description and authorization code (and nothing Bancard forbids
 *   showing: response_code, extended_response_description, security_information).
 */
class OrderPageController extends Controller
{
    /** Query parameter the payment form appends when it sends the customer back (Bancard `?status=`). */
    public const RETURN_QUERY = 'status';

    /** Seconds before the "confirming your payment" page reloads itself. */
    public const CONFIRMING_REFRESH_SECONDS = 5;

    /** Minimum seconds between two provider queries for the same attempt from this page. */
    public const QUERY_INTERVAL_SECONDS = 30;

    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly PaymentProviderRegistry $providers,
        private readonly StrayChargeOperations $charges,
        private readonly PaymentOperations $payments,
    ) {}

    public function show(Request $request): Response
    {
        [$order, $token] = $this->order($request);
        $order->load(['items', 'shippingAddress', 'paymentMethod']);
        $payment = $order->latestPayment()->first();
        $state = $this->paymentState($order, $payment, $request);
        if ($state === 'confirming' && $this->settle($payment)) {
            $order->refresh()->load(['items', 'shippingAddress', 'paymentMethod']);
            $payment = $payment->fresh();
            $state = $this->paymentState($order, $payment, $request);
        }

        $start = null;
        $startFailed = false;
        if ($state === 'collect') {
            try {
                $start = $this->checkout->startPayment($payment, $this->orderUrl($token));
            } catch (RuntimeException $exception) {
                report($exception);
                $startFailed = true;
                if ($exception instanceof BancardException && $exception->reason === BancardProvider::REASON_START_UNCERTAIN) {
                    $payment = $this->closeUncertainStart($payment);
                    $order->refresh()->load(['items', 'shippingAddress', 'paymentMethod']);
                    $state = $this->paymentState($order, $payment, $request);
                    $startFailed = $state === 'collect';
                }
            }
        }

        return response()
            ->view('shop.order', [
                'order' => $order,
                'payment' => $payment,
                'paymentState' => $state,
                'paymentStart' => $start instanceof PaymentStart ? $start : null,
                'paymentStartFailed' => $startFailed,
                'justPlaced' => (bool) $request->session()->get(CheckoutController::PLACED_FLASH, false),
                // Without email the page must not promise one: the link and the number are all the buyer gets.
                'mailActive' => app(MailSettings::class)->active(),
                'orderUrl' => $this->orderUrl($token),
                'payUrl' => CartService::route('order.pay', ['token' => $token]),
                'refreshSeconds' => self::CONFIRMING_REFRESH_SECONDS,
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'no-store, private');
    }

    /** "Try again": a new attempt for a pending order whose last online attempt failed or expired. */
    public function pay(Request $request): RedirectResponse
    {
        [$order, $token] = $this->order($request);
        $payment = $order->latestPayment()->first();
        $redirect = redirect()->to($this->orderUrl($token));

        if ($order->status === Order::STATUS_PENDING && $payment !== null && $this->checkout->isOnline($payment)
            && in_array($payment->status, [Payment::STATUS_FAILED, Payment::STATUS_EXPIRED], true)) {
            try {
                $this->checkout->retryPayment($order);
            } catch (ValidationException $exception) {
                return $redirect->withErrors($exception->errors());
            }
        }

        return $redirect;
    }

    /**
     * Back from the payment form and still pending: ask the provider once and apply a verified outcome. True when
     * the attempt changed. A provider that cannot be reached, says nothing yet or answers something that does not
     * verify leaves the page in "confirming" (the webhook or the sweep settles it later).
     */
    private function settle(Payment $payment): bool
    {
        if (! $this->providers->has($payment->provider)
            || ! Cache::add('shop:payment-query:'.$payment->id, now()->timestamp, now()->addSeconds(self::QUERY_INTERVAL_SECONDS))) {
            return false;
        }
        $provider = $this->providers->get($payment->provider);

        try {
            $notification = $provider->query($payment);
        } catch (RuntimeException|LogicException $exception) {
            report($exception);

            return false;
        }
        if ($notification === null) {
            return false;
        }
        if (! $provider->verify($notification, $payment)) {
            Log::warning('Payment query answer did not verify.', ['payment' => $payment->id, 'provider' => $payment->provider]);

            return false;
        }

        return $this->charges->apply($notification, $payment)->status !== Payment::STATUS_PENDING;
    }

    /**
     * A Bancard buy whose single_buy timed out and cannot be sent again (BancardProvider::REASON_START_UNCERTAIN):
     * its process_id never reached this site, so the customer never saw its form. Ask Bancard about it: a verified
     * approval is applied like any other; otherwise the attempt expires and the page offers "try again", which opens
     * a new attempt with a new shop_process_id. When Bancard cannot be reached the attempt stays as it is.
     */
    private function closeUncertainStart(Payment $payment): Payment
    {
        $provider = $this->providers->get($payment->provider);
        try {
            $notification = $provider->query($payment);
        } catch (RuntimeException $exception) {
            report($exception);

            return $payment;
        }
        if ($notification !== null && $provider->verify($notification, $payment)) {
            $applied = $this->charges->apply($notification, $payment);
            if (! $applied->isPending()) {
                return $applied;
            }
        }

        return $this->payments->expire($payment);
    }

    /**
     * What the payment block shows: `collect` (instructions or iframe), `confirming` (back from the payment form,
     * outcome not known yet), `failed`, `expired`, `confirmed`, `refunded`, or `none` (the order no longer waits
     * for a payment, e.g. cancelled).
     */
    private function paymentState(Order $order, ?Payment $payment, Request $request): string
    {
        if ($payment === null) {
            return 'none';
        }
        if ($payment->status === Payment::STATUS_PENDING) {
            if ($order->status !== Order::STATUS_PENDING) {
                return 'none';
            }

            return $this->checkout->isOnline($payment) && $request->query->has(self::RETURN_QUERY) ? 'confirming' : 'collect';
        }

        return $payment->status;
    }

    /** @return array{0: Order, 1: string} the order of the URL token, and the token for this response's links */
    private function order(Request $request): array
    {
        $token = (string) $request->route('token');
        $order = Order::findByAccessToken($token);
        abort_if($order === null || ! $order->matchesAccessToken($token), 404);

        return [$order, $token];
    }

    private function orderUrl(string $token): string
    {
        return CartService::route('order.show', ['token' => $token]);
    }
}
