<?php

namespace Tests\Support;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingZone;
use App\ValueObjects\Money;
use Illuminate\Support\Str;

/**
 * Rows for checkout tests (PLAN.md E3.5), written straight to the models so the tracks can test without each
 * other's services. Orders built here follow the same rules as App\Services\Shop\CheckoutService: frozen lines and
 * totals, a `shipping` address only for delivery, one pending payment, and no stock taken.
 */
final class CheckoutFixtures
{
    /** Public bank details stored in the `bank_transfer` method by enableManualMethods(). */
    public const BANK_DETAILS = [
        'bank_name' => 'Banco de Prueba',
        'account_holder' => 'Comercio de Prueba S.A.',
        'account_number' => '000123456789',
        'document_number' => '80012345-6',
    ];

    public const COD_INSTRUCTIONS = 'Pagás en efectivo al recibir el pedido.';

    /**
     * A published base-language product with price and tracked stock.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function product(array $attributes = []): Product
    {
        $title = $attributes['title'] ?? 'Producto '.Str::lower(Str::random(6));

        return Product::create($attributes + [
            'title' => $title,
            'slug' => Str::slug($title),
            'price_amount' => 150000,
            'track_stock' => true,
            'allow_backorder' => false,
            'stock' => 10,
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ])->fresh();
    }

    /**
     * A published product with variants (each ['name' => …, 'price_amount' => …, 'stock' => …]).
     *
     * @param  list<array<string, mixed>>  $variants
     * @param  array<string, mixed>  $attributes
     */
    public static function productWithVariants(array $variants = [], array $attributes = []): Product
    {
        $product = self::product($attributes + ['has_variants' => true, 'price_amount' => null, 'stock' => null]);
        $variants = $variants !== [] ? $variants : [
            ['name' => 'Talle S', 'price_amount' => 90000, 'stock' => 5],
            ['name' => 'Talle L', 'price_amount' => 95000, 'stock' => 5],
        ];
        foreach ($variants as $index => $variant) {
            $product->variants()->create($variant + ['order' => $index + 1]);
        }

        return $product->fresh('variants');
    }

    /**
     * A live cart with lines [product, quantity, variant|null].
     *
     * @param  list<array{0: Product, 1?: int, 2?: ProductVariant|null}>  $lines
     */
    public static function cart(array $lines = []): Cart
    {
        $cart = Cart::create(['token' => Cart::generateToken(), 'expires_at' => now()->addDays((int) config('cms.shop.checkout.cart_days', 30))]);
        foreach ($lines as $line) {
            CartItem::create(['cart_id' => $cart->id, 'product_id' => $line[0]->id, 'variant_id' => ($line[2] ?? null)?->id, 'quantity' => $line[1] ?? 1]);
        }

        return $cart->fresh('items');
    }

    public static function shippingZone(string $name = 'Asunción', int $price = 25000, bool $enabled = true): ShippingZone
    {
        return ShippingZone::create(['name' => $name, 'price_amount' => $price, 'enabled' => $enabled, 'sort_order' => ShippingZone::query()->count() + 1]);
    }

    /** Enable `bank_transfer` (with BANK_DETAILS) and `cod` (with COD_INSTRUCTIONS), complete and ready. */
    public static function enableManualMethods(): void
    {
        PaymentMethod::query()->where('key', 'bank_transfer')->firstOrFail()->update(['enabled' => true, 'settings_json' => self::BANK_DETAILS]);
        PaymentMethod::query()->where('key', 'cod')->firstOrFail()->update(['enabled' => true, 'settings_json' => ['instructions' => self::COD_INSTRUCTIONS]]);
    }

    /**
     * A placed order with one pending payment. Options: `method` (payment method key, default `bank_transfer`),
     * `zone` (ShippingZone: home delivery with a shipping address; none = pickup), `status`, `locale`, and any
     * Order attribute. The plain access token is $order->plainAccessToken().
     *
     * @param  list<array{0: Product, 1?: int, 2?: ProductVariant|null}>  $lines
     * @param  array<string, mixed>  $options
     */
    public static function order(array $lines = [], array $options = []): Order
    {
        $lines = $lines !== [] ? $lines : [[self::product(), 1]];
        $method = PaymentMethod::query()->where('key', $options['method'] ?? 'bank_transfer')->firstOrFail();
        $zone = $options['zone'] ?? null;
        unset($options['method'], $options['zone']);

        $items = [];
        $subtotal = 0;
        foreach ($lines as $line) {
            [$product, $quantity, $variant] = [$line[0], $line[1] ?? 1, $line[2] ?? null];
            $unit = ($variant?->price_amount ?? $product->price_amount)?->amount ?? 0;
            $items[] = [
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'title' => $product->title,
                'variant_name' => $variant?->name,
                'sku' => $variant?->sku ?? $product->sku,
                'unit_price_amount' => $unit,
                'quantity' => $quantity,
                'line_total_amount' => $unit * $quantity,
            ];
            $subtotal += $unit * $quantity;
        }
        $shipping = $zone?->price_amount->amount ?? 0;
        $token = Order::generateAccessToken();

        $order = Order::create($options + [
            'number' => self::nextNumber(),
            'status' => Order::STATUS_PENDING,
            'locale' => config('cms.locales.default', 'es'),
            'access_token_hash' => Order::hashAccessToken($token),
            'access_token' => $token,
            'customer_name' => 'Ana Pérez',
            'customer_email' => 'ana@example.com',
            'customer_phone' => '0981 123 456',
            'delivery_method' => $zone === null ? Order::DELIVERY_PICKUP : Order::DELIVERY_DELIVERY,
            'shipping_zone_id' => $zone?->id,
            'shipping_zone_name' => $zone?->name,
            'payment_method_key' => $method->key,
            'currency' => config('cms.shop.currency', 'PYG'),
            'subtotal_amount' => $subtotal,
            'shipping_amount' => $shipping,
            'total_amount' => $subtotal + $shipping,
            'placed_at' => now(),
        ]);
        $order->items()->createMany($items);
        if ($zone !== null) {
            $order->addresses()->create(['type' => OrderAddress::TYPE_SHIPPING, 'address' => 'Av. Mariscal López 1234', 'city' => 'Asunción', 'department' => 'Central', 'reference' => 'Portón verde']);
        }
        self::payment($order, $method);

        return $order->fresh(['items', 'shippingAddress', 'payments']);
    }

    /** A new pending attempt for an order (Bancard attempts get shop_process_id = id and the method's environment). */
    public static function payment(Order $order, ?PaymentMethod $method = null): Payment
    {
        $method ??= $order->paymentMethod;
        $payment = $order->payments()->create([
            'method_key' => $method->key,
            'provider' => $method->provider,
            'status' => Payment::STATUS_PENDING,
            'amount' => $order->total_amount instanceof Money ? $order->total_amount->amount : (int) $order->total_amount,
            'currency' => $order->currency,
            'environment' => $method->provider === 'bancard' ? ($method->environment ?? PaymentMethod::ENVIRONMENT_STAGING) : null,
        ]);
        if ($method->provider === 'bancard') {
            $payment->forceFill(['shop_process_id' => $payment->id])->save();
        }

        return $payment->fresh();
    }

    private static function nextNumber(): string
    {
        $prefix = config('cms.shop.checkout.order_prefix', 'ORD').'-'.now()->format('Y').'-';

        return $prefix.str_pad((string) (Order::query()->where('number', 'like', $prefix.'%')->count() + 1), 6, '0', STR_PAD_LEFT);
    }
}
