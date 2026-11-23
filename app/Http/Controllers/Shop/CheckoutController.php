<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\CheckoutRequest;
use App\Services\Shop\CartService;
use App\Services\Shop\CheckoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Public checkout (PLAN.md E3.2 steps 2–3), only while the shop mode is `checkout` (EnsureShopCheckout). The form
 * carries the full anti-spam layer (CheckoutRequest); a tripped honeypot answers like a success and creates
 * nothing. A cart that is empty or has problems goes back to the cart page, where they are shown.
 */
class CheckoutController extends Controller
{
    /** Session flash set by a placed order (and by the fake success of a honeypot hit). */
    public const PLACED_FLASH = 'checkout_order_placed';

    public function __construct(
        private readonly CartService $carts,
        private readonly CheckoutService $checkout,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $summary = $this->carts->summary($this->carts->current($request));
        if ($summary['empty']) {
            return $this->toCart()->withErrors(['cart' => __('checkout.checkout_cart_empty')]);
        }
        if ($summary['blocked']) {
            return $this->toCart()->withErrors(['cart' => __('checkout.cart_blocked')]);
        }

        return response()
            ->view('shop.checkout', [
                'cart' => $summary,
                'pickupAddress' => $this->checkout->pickupAddress(),
                'zones' => $this->checkout->zones(),
                'paymentMethods' => $this->checkout->paymentMethods(),
                'termsPage' => $this->checkout->termsPage(),
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(CheckoutRequest $request): RedirectResponse
    {
        if ($request->isHoneypotTripped()) {
            return $this->toCart()->with(self::PLACED_FLASH, true)->with('status', __('checkout.order_thanks'));
        }

        $cart = $this->carts->current($request);
        if ($cart === null) {
            return $this->toCart()->withErrors(['cart' => __('checkout.checkout_cart_empty')]);
        }

        try {
            $order = $this->checkout->place($cart, $request->orderData(), $request->orderMeta());
        } catch (ValidationException $exception) {
            if (array_key_exists('cart', $exception->errors())) {
                return $this->toCart()->withErrors($exception->errors());
            }

            throw $exception;
        }

        return redirect()
            ->to(CartService::route('order.show', ['token' => $order->access_token]))
            ->with(self::PLACED_FLASH, true)
            ->withCookie($this->carts->forgetCookie());
    }

    private function toCart(): RedirectResponse
    {
        return redirect()->to(CartService::route('cart.index'));
    }
}
