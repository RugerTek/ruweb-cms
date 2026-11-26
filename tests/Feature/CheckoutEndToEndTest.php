<?php

namespace Tests\Feature;

use App\Http\Requests\Public\CheckoutRequest;
use App\Mail\Shop\OrderPaidCustomer;
use App\Mail\Shop\OrderPlacedCustomer;
use App\Mail\Shop\OrderPlacedStore;
use App\Models\Cart;
use App\Models\Locale;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\ShippingZone;
use App\Models\User;
use App\Services\Forms\FormToken;
use App\Services\Mail\MailSettings;
use App\Services\Shop\CartService;
use App\Shop\Payments\Bancard\BancardProvider;
use App\Support\SettingDefaults;
use App\Support\ShopModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeBancard;
use Tests\TestCase;

/**
 * Selling online from a fresh site, only through the panel and the public pages (PLAN.md Etapa 3): the shop off, the
 * administrator turns the catalog on, creates a product with variants and stock, sets up payments, shipping and email,
 * turns selling on; a visitor buys and the order is paid by hand (bank transfer) or through Bancard (FakeBancard).
 * Email is optional (docs/shop/checkout.md#sin-email): a shop without it sells the same, and nobody gets an email.
 */
class CheckoutEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Locale::updateOrCreate(['code' => 'es'], ['name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
        config(['cms.shop.payment_providers.bancard' => BancardProvider::class]);
        $this->useShop('off');
        $this->admin = User::factory()->create(['role' => 'admin']);
        RateLimiter::clear('forms');
        Mail::fake();
    }

    public function test_manual_payment_from_a_fresh_site_to_a_delivered_order(): void
    {
        $this->get('/productos')->assertNotFound();

        [$product, $small] = $this->openTheShop();
        $zone = ShippingZone::query()->sole();

        // A visitor: product page, add to cart, checkout with delivery to the zone.
        $this->get('/productos/remera-e2e?variante='.$small->id)->assertOk()->assertSee('data-add-to-cart', false);
        $cart = $this->addToCart($product, $small, 2);
        $this->get(route('shop.cart.index'))->assertOk()->assertSee('Remera E2E')->assertSee('Gs. 180.000');
        $this->get(route('shop.checkout.show'))->assertOk()->assertSee('data-payment-method="bank_transfer"', false)->assertSee('Asunción — Gs. 25.000');

        $response = $this->post(route('shop.checkout.store'), $this->payload([
            'delivery_method' => 'delivery', 'shipping_zone_id' => $zone->id, 'address' => 'Av. Mariscal López 1234', 'city' => 'Asunción',
            'payment_method' => 'bank_transfer',
        ]))->assertSessionHasNoErrors();
        $order = Order::query()->sole();
        $url = $response->headers->get('Location');
        $this->assertSame(route('shop.order.show', $order->plainAccessToken()), $url);
        $this->assertSame([Order::STATUS_PENDING, 205000, 10], [$order->status, $order->total_amount->amount, $small->fresh()->stock], 'Placing an order takes no stock.');
        $this->assertNull($cart->fresh(), 'The cart is gone once the order is placed.');

        $this->get($url)->assertOk()->assertSee($order->number)->assertSee('Banco E2E')->assertSee('Gs. 205.000')
            ->assertSee('data-order-link-hint="mail"', false)->assertSee(__('checkout.order_link_hint'));
        Mail::assertQueued(OrderPlacedCustomer::class, fn (OrderPlacedCustomer $mail) => $mail->hasTo('ana@example.com') && str_contains($mail->render(), $url));
        Mail::assertQueued(OrderPlacedStore::class, fn (OrderPlacedStore $mail) => $mail->hasTo('pedidos@example.com'));

        // The administrator confirms the transfer: the order is paid and the stock is taken.
        $payment = $order->payments()->sole();
        $this->actingAs($this->admin)->post(route('admin.payments.confirm', $payment))->assertRedirect(route('admin.orders.show', $order));
        $this->assertSame([Payment::STATUS_CONFIRMED, Order::STATUS_PAID, 8], [$payment->fresh()->status, $order->fresh()->status, $small->fresh()->stock]);
        Mail::assertQueued(OrderPaidCustomer::class, 1);

        $this->post(route('admin.orders.transition', $order), ['status' => Order::STATUS_FULFILLED])->assertSessionHasNoErrors();
        $this->assertSame(Order::STATUS_FULFILLED, $order->fresh()->status);
        $this->get(route('admin.orders.show', $order))->assertOk()->assertSee(__('checkout.status_fulfilled'));
    }

    public function test_bancard_payment_from_checkout_to_a_paid_order(): void
    {
        $bancard = (new FakeBancard)->install();
        $this->app->instance(FakeBancard::class, $bancard);
        [$product, $small] = $this->openTheShop();

        // Bancard keys loaded and the method turned on from Admin > Payments and shipping.
        $this->actingAs($this->admin)->put(route('admin.shop-payments.bancard'), ['environment' => PaymentMethod::ENVIRONMENT_STAGING] + $bancard->keys())->assertSessionHasNoErrors();
        $this->put(route('admin.shop-payments.update'), ['methods' => ['bancard' => ['enabled' => '1']]])->assertSessionHasNoErrors();
        $this->assertTrue((bool) PaymentMethod::query()->where('key', 'bancard')->value('enabled'));
        auth()->logout();

        $this->addToCart($product, $small, 1);
        $this->get(route('shop.checkout.show'))->assertOk()->assertSee('data-payment-method="bancard"', false);
        $url = $this->post(route('shop.checkout.store'), $this->payload(['delivery_method' => 'pickup', 'payment_method' => 'bancard']))
            ->assertSessionHasNoErrors()->headers->get('Location');
        $order = Order::query()->sole();
        $payment = $order->payments()->sole();

        // The order page opens Bancard's iframe (single_buy) for this attempt.
        $this->get($url)->assertOk()->assertSee('data-payment-iframe', false);
        $bancard->assertCalled(FakeBancard::OPERATION_SINGLE_BUY, 1);
        $payment->refresh();
        $this->assertNotNull($payment->process_id);
        $this->assertSame(10, $small->fresh()->stock);

        // Bancard confirms with a valid token: paid, stock taken, customer told.
        $this->postJson(route('webhooks.bancard'), $bancard->webhookPayload($payment))->assertOk()->assertExactJson(['status' => 'success']);
        $this->assertSame([Payment::STATUS_CONFIRMED, Order::STATUS_PAID, 9], [$payment->fresh()->status, $order->fresh()->status, $small->fresh()->stock]);
        Mail::assertQueued(OrderPaidCustomer::class, fn (OrderPaidCustomer $mail) => $mail->hasTo('ana@example.com'));
        $this->get($url)->assertOk()->assertSee('data-order-payment="confirmed"', false)->assertSee('123456');
    }

    public function test_a_shop_without_email_sells_and_shows_the_order_without_sending_anything(): void
    {
        [$product, $small] = $this->openTheShop(mail: false);
        $this->assertFalse(app(MailSettings::class)->active());

        // The panel sells, but says plainly that nobody gets an email.
        $this->actingAs($this->admin)->get(route('admin.orders.index'))->assertOk()
            ->assertSee('data-shop-mail-off', false)->assertSee(__('checkout.mail_off_title'));
        auth()->logout();

        $this->addToCart($product, $small, 1);
        $response = $this->post(route('shop.checkout.store'), $this->payload(['delivery_method' => 'pickup', 'payment_method' => 'bank_transfer']))
            ->assertSessionHasNoErrors();
        $order = Order::query()->sole();
        $url = $response->headers->get('Location');
        $this->assertSame(route('shop.order.show', $order->plainAccessToken()), $url);
        $this->assertSame(Order::STATUS_PENDING, $order->status);

        // The confirmation page alone carries the order: number, bank details and the link, without promising an email.
        $this->get($url)->assertOk()->assertSee($order->number)->assertSee('Banco E2E')->assertSee('555-1')
            ->assertSee('data-order-link-hint="no-mail"', false)->assertSee(__('checkout.order_link_hint_no_mail'))
            ->assertDontSee(__('checkout.order_link_hint'));
        Mail::assertNothingQueued();
        Mail::assertNothingSent();

        // The order is in Admin > Orders and is paid and delivered the same way.
        $payment = $order->payments()->sole();
        $this->actingAs($this->admin)->get(route('admin.orders.index'))->assertOk()->assertSee($order->number);
        $this->post(route('admin.payments.confirm', $payment))->assertRedirect(route('admin.orders.show', $order));
        $this->assertSame([Payment::STATUS_CONFIRMED, Order::STATUS_PAID, 9], [$payment->fresh()->status, $order->fresh()->status, $small->fresh()->stock]);
        $this->post(route('admin.orders.transition', $order), ['status' => Order::STATUS_FULFILLED])->assertSessionHasNoErrors();
        $this->assertSame(Order::STATUS_FULFILLED, $order->fresh()->status);
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    /**
     * Through the panel: catalog on, a product with two variants and stock, bank transfer, pickup, one delivery zone,
     * the orders email, email sending on with a Brevo key (unless $mail is false), and then selling online.
     *
     * @return array{0: Product, 1: ProductVariant}
     */
    private function openTheShop(bool $mail = true): array
    {
        $this->actingAs($this->admin);
        $this->put(route('admin.settings.shop.update'), ['mode' => 'catalog', 'confirm' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue(app(ShopModule::class)->catalog());

        $this->post('/admin/products', [
            'title' => 'Remera E2E', 'slug' => 'remera-e2e', 'status' => 'published', 'locale' => 'es', 'sku' => 'E2E',
            'has_variants' => '1', 'track_stock' => '1', 'allow_backorder' => '0',
            'items' => ['variants' => [
                ['id' => '', 'name' => 'Talle S', 'sku' => 'E2E-S', 'price_amount' => '90.000', 'stock' => '10'],
                ['id' => '', 'name' => 'Talle L', 'sku' => 'E2E-L', 'price_amount' => '95.000', 'stock' => '3'],
            ]],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $product = Product::query()->where('slug', 'remera-e2e')->sole();
        $small = $product->variants()->where('name', 'Talle S')->sole();

        $this->put(route('admin.shop-payments.update'), [
            'methods' => ['bank_transfer' => ['enabled' => '1', 'sort_order' => '1', 'settings' => ['bank_name' => 'Banco E2E', 'account_holder' => 'Tienda E2E SA', 'account_number' => '555-1']]],
            'shop_pickup_enabled' => '1',
            'shop_pickup_address' => 'Av. España 123, Asunción',
            'shop_orders_email' => 'pedidos@example.com',
        ])->assertSessionHasNoErrors();
        $this->post(route('admin.shipping-zones.store'), ['name' => 'Asunción', 'price' => '25.000', 'enabled' => '1'])->assertSessionHasNoErrors();
        if ($mail) {
            foreach (['mail_enabled', 'brevo_api_key'] as $key) {
                Setting::query()->firstOrCreate(['key' => $key], SettingDefaults::for($key));
            }
            $this->put(route('admin.email.update'), ['settings' => ['mail_enabled' => '1', 'brevo_api_key' => 'xkeysib-e2e']])->assertSessionHasNoErrors();
        }

        $this->put(route('admin.settings.shop.update'), ['mode' => 'checkout', 'confirm' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue(app(ShopModule::class)->checkout());
        auth()->logout();

        return [$product, $small];
    }

    /** Post the product page form and keep the guest cart cookie for the next requests. */
    private function addToCart(Product $product, ProductVariant $variant, int $quantity): Cart
    {
        $response = $this->post(route('shop.cart.add'), ['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => $quantity])
            ->assertSessionHasNoErrors()->assertRedirect(route('shop.cart.index'));
        $token = $this->cartCookie($response);
        $this->withCookie(CartService::COOKIE, $token);

        return Cart::query()->where('token', $token)->sole();
    }

    private function cartCookie(TestResponse $response): string
    {
        $cookie = $response->getCookie(CartService::COOKIE);
        $this->assertNotNull($cookie);

        return $cookie->getValue();
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides): array
    {
        $this->travel(-10)->seconds();
        $token = app(FormToken::class)->issue(CheckoutRequest::PAGE_ID, CheckoutRequest::FORM, 'es');
        $this->travelBack();

        return $overrides + [
            'customer_name' => 'Ana Pérez',
            'customer_email' => 'ana@example.com',
            'customer_phone' => '0981 123 456',
            CheckoutRequest::HONEYPOT_FIELD => '',
            '_form_token' => $token,
        ];
    }
}
