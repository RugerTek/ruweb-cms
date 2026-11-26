<?php

namespace App\Services\Shop;

use App\Events\Shop\OrderPlaced;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\Page;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\ShippingZone;
use App\Shop\Payments\ManualPaymentProvider;
use App\Shop\Payments\PaymentProviderRegistry;
use App\Shop\Payments\PaymentStart;
use App\Shop\Payments\PaymentUrls;
use App\ValueObjects\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;

/**
 * Turns a guest cart into an order (PLAN.md E3.2 step 3) and starts collecting its payment (steps 4–5). Placing
 * an order revalidates the cart, freezes lines, delivery and totals, numbers it (OrderNumberGenerator), creates
 * one pending payment attempt, empties the cart and dispatches OrderPlaced once the transaction commits. It never
 * touches stock: stock is taken when the payment is confirmed (P3, App\Services\Shop\PaymentOperations).
 *
 * Status changes after placing (paid, cancelled…) are not done here: they belong to OrderOperations and
 * PaymentOperations.
 */
class CheckoutService
{
    public function __construct(
        private readonly CartService $carts,
        private readonly OrderNumberGenerator $numbers,
        private readonly PaymentProviderRegistry $payments,
    ) {}

    /** Store pickup address while pickup is offered (enabled with an address); null otherwise. */
    public function pickupAddress(): ?string
    {
        $address = Setting::get('shop_pickup_address');

        return (bool) Setting::get('shop_pickup_enabled', false) && is_string($address) && trim($address) !== ''
            ? trim($address)
            : null;
    }

    /** @return Collection<int, ShippingZone> zones the customer can choose, in panel order */
    public function zones(): Collection
    {
        return ShippingZone::query()->enabled()->get();
    }

    /** @return Collection<int, PaymentMethod> methods offered: enabled and ready, in panel order */
    public function paymentMethods(): Collection
    {
        return $this->payments->available();
    }

    /** The published purchase policies page chosen in the panel (shop_terms_page_id), in the current language when it has one. */
    public function termsPage(): ?Page
    {
        $id = Setting::get('shop_terms_page_id');
        if (! is_numeric($id) || (int) $id <= 0) {
            return null;
        }
        $page = Page::published()->find((int) $id);
        if ($page === null) {
            return null;
        }

        return $page->locale === app()->getLocale() ? $page : ($page->publishedTranslationFor(app()->getLocale()) ?? $page);
    }

    /**
     * Place the order of a cart.
     *
     * @param  array{customer_name: string, customer_email: string, customer_phone: string, delivery_method: string, shipping_zone_id?: int|string|null, address?: string|null, city?: string|null, department?: string|null, reference?: string|null, notes?: string|null, payment_method: string}  $data
     * @param  array{ip?: string|null, user_agent?: string|null, recaptcha_score?: float|null, locale?: string|null}  $meta
     *
     * @throws ValidationException when the cart is empty or changed, or the delivery or payment method is not available
     */
    public function place(Cart $cart, array $data, array $meta = []): Order
    {
        $order = DB::transaction(function () use ($cart, $data, $meta): Order {
            // Locking the cart row makes a double submit wait here and then find the cart gone.
            $locked = Cart::query()->whereKey($cart->id)->where('expires_at', '>', now())->lockForUpdate()->first();
            $summary = $this->carts->summary($locked);
            if ($locked === null || $summary['empty']) {
                throw ValidationException::withMessages(['cart' => __('checkout.checkout_cart_empty')]);
            }
            if ($summary['blocked']) {
                throw ValidationException::withMessages(['cart' => __('checkout.error_cart_changed')]);
            }

            [$deliveryMethod, $zone] = $this->delivery($data);
            $method = $this->payments->availableMethod((string) ($data['payment_method'] ?? ''));
            if ($method === null) {
                throw ValidationException::withMessages(['payment_method' => __('checkout.error_method_unavailable')]);
            }

            $subtotal = $summary['subtotal'];
            $shipping = $zone?->price_amount ?? Money::zero($subtotal->currency);
            $total = $subtotal->add($shipping);
            $token = Order::generateAccessToken();

            $order = $this->numbers->assign(fn (string $number): Order => Order::create([
                'number' => $number,
                'status' => Order::STATUS_PENDING,
                'locale' => $meta['locale'] ?? app()->getLocale(),
                'access_token_hash' => Order::hashAccessToken($token),
                'access_token' => $token,
                'customer_name' => trim($data['customer_name']),
                'customer_email' => trim($data['customer_email']),
                'customer_phone' => trim($data['customer_phone']),
                'delivery_method' => $deliveryMethod,
                'shipping_zone_id' => $zone?->id,
                'shipping_zone_name' => $zone?->name,
                'payment_method_key' => $method->key,
                'currency' => $total->currency,
                'subtotal_amount' => $subtotal->amount,
                'shipping_amount' => $shipping->amount,
                'total_amount' => $total->amount,
                'notes' => $this->nullableText($data['notes'] ?? null),
                'ip' => $meta['ip'] ?? null,
                'user_agent' => isset($meta['user_agent']) ? mb_substr((string) $meta['user_agent'], 0, 500) : null,
                'recaptcha_score' => $meta['recaptcha_score'] ?? null,
                'placed_at' => now(),
            ]));

            foreach ($summary['lines'] as $line) {
                $order->items()->create([
                    'product_id' => $line['product']?->id,
                    'variant_id' => $line['variant']?->id,
                    'title' => $line['title'],
                    'variant_name' => $line['variant_name'],
                    'sku' => $line['sku'],
                    'unit_price_amount' => $line['unit_price']->amount,
                    'quantity' => $line['quantity'],
                    'line_total_amount' => $line['line_total']->amount,
                ]);
            }
            if ($deliveryMethod === Order::DELIVERY_DELIVERY) {
                $order->addresses()->create([
                    'type' => OrderAddress::TYPE_SHIPPING,
                    'address' => trim((string) $data['address']),
                    'city' => trim((string) $data['city']),
                    'department' => $this->nullableText($data['department'] ?? null),
                    'reference' => $this->nullableText($data['reference'] ?? null),
                ]);
            }
            $this->createAttempt($order, $method);
            $this->carts->clear($locked);

            return $order;
        });

        OrderPlaced::dispatch($order);

        return $order;
    }

    /**
     * A new pending attempt for an order whose last attempt failed or expired (the order page's "try again").
     * Returns the current attempt untouched when it is still pending or the order no longer waits for a payment.
     *
     * @throws ValidationException (`payment_method`) when the order's method is no longer offered
     */
    public function retryPayment(Order $order): Payment
    {
        return DB::transaction(function () use ($order): Payment {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $latest = $order->latestPayment()->first();
            if ($order->status !== Order::STATUS_PENDING || ($latest !== null && ! in_array($latest->status, [Payment::STATUS_FAILED, Payment::STATUS_EXPIRED], true))) {
                return $latest;
            }
            $method = $this->payments->availableMethod($order->payment_method_key);
            if ($method === null) {
                throw ValidationException::withMessages(['payment_method' => __('checkout.error_method_unavailable')]);
            }

            return $this->createAttempt($order, $method);
        });
    }

    /**
     * Ask the attempt's provider how the customer pays it: instructions (manual) or an embedded form (iframe) that
     * returns to $returnUrl.
     *
     * @throws \RuntimeException when the provider's service cannot be reached (message without credentials)
     */
    public function startPayment(Payment $payment, string $returnUrl): PaymentStart
    {
        return $this->payments->get($payment->provider)->start($payment, new PaymentUrls($returnUrl));
    }

    /** Whether the attempt is collected online (an iframe provider), as opposed to by hand. */
    public function isOnline(Payment $payment): bool
    {
        return $payment->provider !== ManualPaymentProvider::KEY;
    }

    /** Label of a payment method for the customer: `label` setting, else checkout.method_<key>, else the key. */
    public static function methodLabel(PaymentMethod|string|null $method): string
    {
        $key = $method instanceof PaymentMethod ? $method->key : (string) $method;
        $label = $method instanceof PaymentMethod ? $method->setting('label') : null;
        if (is_string($label)) {
            return $label;
        }

        return Lang::has('checkout.method_'.$key) ? __('checkout.method_'.$key) : $key;
    }

    /**
     * One pending attempt. Online attempts carry the method's environment and use their own id as the provider's
     * process reference (Bancard shop_process_id, ≤ 15 digits).
     */
    private function createAttempt(Order $order, PaymentMethod $method): Payment
    {
        $online = $method->provider !== ManualPaymentProvider::KEY;
        $payment = $order->payments()->create([
            'method_key' => $method->key,
            'provider' => $method->provider,
            'status' => Payment::STATUS_PENDING,
            'amount' => $order->total_amount->amount,
            'currency' => $order->currency,
            'environment' => $online ? $method->environment : null,
        ]);
        if ($online && $payment->id <= Payment::MAX_SHOP_PROCESS_ID) {
            $payment->forceFill(['shop_process_id' => $payment->id])->save();
        }

        return $payment;
    }

    /**
     * @return array{0: string, 1: ShippingZone|null}
     *
     * @throws ValidationException
     */
    private function delivery(array $data): array
    {
        if (($data['delivery_method'] ?? null) === Order::DELIVERY_PICKUP) {
            if ($this->pickupAddress() === null) {
                throw ValidationException::withMessages(['delivery_method' => __('checkout.error_pickup_unavailable')]);
            }

            return [Order::DELIVERY_PICKUP, null];
        }

        $zoneId = $data['shipping_zone_id'] ?? null;
        if (! is_numeric($zoneId)) {
            throw ValidationException::withMessages(['shipping_zone_id' => __('checkout.error_zone_required')]);
        }
        $zone = ShippingZone::query()->whereKey((int) $zoneId)->where('enabled', true)->first();
        if ($zone === null) {
            throw ValidationException::withMessages(['shipping_zone_id' => __('checkout.error_zone_unavailable')]);
        }
        if (trim((string) ($data['address'] ?? '')) === '' || trim((string) ($data['city'] ?? '')) === '') {
            throw ValidationException::withMessages(['address' => __('checkout.error_address_required')]);
        }

        return [Order::DELIVERY_DELIVERY, $zone];
    }

    private function nullableText(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
