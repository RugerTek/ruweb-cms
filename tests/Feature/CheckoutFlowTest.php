<?php

namespace Tests\Feature;

use App\Events\Shop\OrderPlaced;
use App\Http\Requests\Public\CheckoutRequest;
use App\Models\Cart;
use App\Models\Locale;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\Page;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\ShippingZone;
use App\Services\Forms\FormToken;
use App\Services\Forms\Recaptcha;
use App\Services\Shop\CartService;
use App\Services\Shop\CheckoutService;
use App\Services\Shop\OrderNumberGenerator;
use App\Support\SettingDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\CheckoutFixtures;
use Tests\Support\FakePaymentProvider;
use Tests\TestCase;

/** Checkout form, order placement and the order page by token (PLAN.md E3.2 steps 2–5, track 3A). */
class CheckoutFlowTest extends TestCase
{
    use RefreshDatabase;

    private ShippingZone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        Locale::updateOrCreate(['code' => 'es'], ['name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
        Locale::updateOrCreate(['code' => 'en'], ['name' => 'English', 'is_base' => false, 'is_active' => true, 'is_public' => true, 'sort_order' => 1]);
        $this->zone = $this->useCheckout();
        RateLimiter::clear('forms');
    }

    public function test_checkout_form_carries_the_anti_spam_layer_and_the_choices(): void
    {
        $this->cartWith(CheckoutFixtures::product(['title' => 'Mate', 'price_amount' => 150000]), 2);
        CheckoutFixtures::shippingZone('Interior', 40000, false);

        $this->get(route('shop.checkout.show'))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('name="_token"', false)
            ->assertSee('name="'.CheckoutRequest::HONEYPOT_FIELD.'"', false)
            ->assertSee('name="_form_token"', false)
            ->assertSee('data-form-fill-wait="3"', false)
            ->assertSee(__('checkout.delivery_pickup_address', ['address' => 'Av. España 123, Asunción']))
            ->assertSee('Asunción — Gs. 25.000')
            ->assertDontSee('Interior')
            ->assertSee('data-total-label="Gs. 325.000"', false)
            ->assertSee('data-payment-method="bank_transfer"', false)
            ->assertSee('data-payment-method="cod"', false)
            ->assertDontSee('data-payment-method="bancard"', false)
            ->assertSee('Gs. 300.000')
            // «Preparando el envío…» and the total sit right above the button (on mobile the summary comes after).
            ->assertSeeInOrder(['data-checkout-submit', 'data-summary-total-inline', 'Gs. 300.000', 'data-form-fill-status', __('submissions.preparing'), 'data-place-order', 'data-order-summary'], false);

        Setting::set('recaptcha_site_key', 'site-key');
        Setting::set('recaptcha_secret_key', 'secret-key');
        $this->get(route('shop.checkout.show'))->assertSee('data-recaptcha-action="checkout"', false);
    }

    public function test_pickup_order_freezes_the_cart_and_leaves_stock_untouched(): void
    {
        Event::fake([OrderPlaced::class]);
        $product = CheckoutFixtures::product(['title' => 'Mate de calabaza', 'sku' => 'MATE-1', 'price_amount' => 150000, 'stock' => 5]);
        $withVariants = CheckoutFixtures::productWithVariants([['name' => 'Talle S', 'sku' => 'REM-S', 'price_amount' => 90000, 'stock' => 3]]);
        $cart = $this->cartWith($product, 2, [[$withVariants, 1, $withVariants->variants[0]]]);

        $response = $this->post(route('shop.checkout.store'), $this->payload(['notes' => 'Llamar antes']));

        $order = Order::query()->sole();
        $token = $this->tokenFrom($response->headers->get('Location'));
        $response->assertRedirect(route('shop.order.show', $token))->assertSessionHas('checkout_order_placed');
        $this->assertLessThan(time(), $response->getCookie(CartService::COOKIE, false)?->getExpiresTime() ?? 0);

        $this->assertSame(Order::ACCESS_TOKEN_LENGTH, strlen($token));
        $this->assertSame(hash('sha256', $token), $order->access_token_hash);
        $this->assertStringNotContainsString($token, (string) DB::table('orders')->value('access_token'));
        $this->assertSame($token, $order->plainAccessToken());
        $this->assertMatchesRegularExpression('/^ORD-'.now()->format('Y').'-000001$/', $order->number);
        $this->assertSame([Order::STATUS_PENDING, Order::DELIVERY_PICKUP, 'bank_transfer', 'es', 'Llamar antes'], [$order->status, $order->delivery_method, $order->payment_method_key, $order->locale, $order->notes]);
        $this->assertSame([390000, 0, 390000], [$order->subtotal_amount->amount, $order->shipping_amount->amount, $order->total_amount->amount]);
        $this->assertNull($order->shipping_zone_id);
        $this->assertSame(0, OrderAddress::query()->count());
        $this->assertSame('127.0.0.1', $order->ip);

        $items = $order->items->map(fn ($item) => [$item->title, $item->variant_name, $item->sku, $item->unit_price_amount->amount, $item->quantity, $item->line_total_amount->amount])->all();
        $this->assertSame([['Mate de calabaza', null, 'MATE-1', 150000, 2, 300000], [$withVariants->title, 'Talle S', 'REM-S', 90000, 1, 90000]], $items);

        $payment = $order->payments()->sole();
        $this->assertSame([Payment::STATUS_PENDING, 'bank_transfer', 'manual', 390000, null], [$payment->status, $payment->method_key, $payment->provider, $payment->amount->amount, $payment->shop_process_id]);

        // P3: placing an order never takes stock; the cart is gone.
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertSame(3, $withVariants->variants[0]->fresh()->stock);
        $this->assertNull(Cart::find($cart->id));
        $this->assertDatabaseCount('cart_items', 0);
        Event::assertDispatchedTimes(OrderPlaced::class, 1);
        Event::assertDispatched(OrderPlaced::class, fn (OrderPlaced $event) => $event->order->is($order));

        // A second submit of the same form finds no cart and creates nothing.
        $this->post(route('shop.checkout.store'), $this->payload())->assertRedirect(route('shop.cart.index'))->assertSessionHasErrors('cart');
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_delivery_adds_the_zone_cost_and_needs_an_address(): void
    {
        $this->cartWith(CheckoutFixtures::product(['price_amount' => 150000]), 1);
        $delivery = ['delivery_method' => 'delivery', 'shipping_zone_id' => $this->zone->id, 'address' => 'Av. Mariscal López 1234', 'city' => 'Asunción', 'department' => 'Central', 'reference' => 'Portón verde'];

        $this->post(route('shop.checkout.store'), $this->payload(['delivery_method' => 'delivery', 'shipping_zone_id' => null]))->assertSessionHasErrors(['shipping_zone_id' => __('checkout.error_zone_required')]);
        $this->post(route('shop.checkout.store'), $this->payload(['address' => ''] + $delivery))->assertSessionHasErrors(['address' => __('checkout.error_address_required')]);
        $disabled = CheckoutFixtures::shippingZone('Interior', 40000, false);
        $this->post(route('shop.checkout.store'), $this->payload(['shipping_zone_id' => $disabled->id] + $delivery))->assertSessionHasErrors(['shipping_zone_id' => __('checkout.error_zone_unavailable')]);
        $this->assertDatabaseCount('orders', 0);

        $this->post(route('shop.checkout.store'), $this->payload($delivery))->assertRedirect();
        $order = Order::query()->sole();
        $this->assertSame([Order::DELIVERY_DELIVERY, $this->zone->id, 'Asunción'], [$order->delivery_method, $order->shipping_zone_id, $order->shipping_zone_name]);
        $this->assertSame([150000, 25000, 175000], [$order->subtotal_amount->amount, $order->shipping_amount->amount, $order->total_amount->amount]);
        $address = $order->shippingAddress;
        $this->assertSame(['Av. Mariscal López 1234', 'Asunción', 'Central', 'Portón verde'], [$address->address, $address->city, $address->department, $address->reference]);

        // Editing the zone afterwards never changes the order.
        $this->zone->update(['price_amount' => 99000, 'name' => 'Gran Asunción']);
        $this->assertSame([25000, 'Asunción'], [$order->fresh()->shipping_amount->amount, $order->fresh()->shipping_zone_name]);
    }

    public function test_address_is_stored_only_for_home_delivery_and_pickup_must_be_offered(): void
    {
        $this->cartWith(CheckoutFixtures::product(), 1);
        $this->post(route('shop.checkout.store'), $this->payload(['address' => 'Calle falsa 123', 'city' => 'Luque', 'shipping_zone_id' => $this->zone->id]))->assertRedirect();
        $order = Order::query()->sole();
        $this->assertSame([Order::DELIVERY_PICKUP, null, 0], [$order->delivery_method, $order->shipping_zone_id, $order->shipping_amount->amount]);
        $this->assertDatabaseCount('order_addresses', 0);

        $this->setSetting('shop_pickup_enabled', '0');
        $this->cartWith(CheckoutFixtures::product(), 1);
        $this->post(route('shop.checkout.store'), $this->payload())->assertSessionHasErrors(['delivery_method' => __('checkout.error_pickup_unavailable')]);
        $this->get(route('shop.checkout.show'))->assertOk()->assertDontSee('value="pickup"', false);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_honeypot_answers_like_a_success_and_creates_nothing(): void
    {
        Event::fake([OrderPlaced::class]);
        $cart = $this->cartWith(CheckoutFixtures::product(), 1);

        $this->post(route('shop.checkout.store'), $this->payload([CheckoutRequest::HONEYPOT_FIELD => 'http://spam.example']))
            ->assertRedirect(route('shop.cart.index'))
            ->assertSessionHas('checkout_order_placed')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertNotNull(Cart::find($cart->id));
        Event::assertNotDispatched(OrderPlaced::class);
    }

    public function test_a_form_sent_too_fast_or_with_a_foreign_token_is_rejected(): void
    {
        $this->cartWith(CheckoutFixtures::product(), 1);

        $fresh = app(FormToken::class)->issue(CheckoutRequest::PAGE_ID, CheckoutRequest::FORM, 'es');
        $this->post(route('shop.checkout.store'), $this->payload(['_form_token' => $fresh]))->assertSessionHasErrors(['_form_token' => __('checkout.error_too_fast')]);

        $contact = app(FormToken::class)->issue(1, 'contact', 'es');
        $this->travel(10)->seconds();
        $this->post(route('shop.checkout.store'), $this->payload(['_form_token' => $contact]))->assertSessionHasErrors(['_form_token' => __('submissions.token_expired')]);
        $this->post(route('shop.checkout.store'), $this->payload(['_form_token' => null]))->assertSessionHasErrors('_form_token');
        $this->travelBack();

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_recaptcha_is_enforced_with_the_checkout_action(): void
    {
        $this->cartWith(CheckoutFixtures::product(), 1);
        Setting::set('recaptcha_site_key', 'site-key');
        Setting::set('recaptcha_secret_key', 'secret-key');
        Setting::set('recaptcha_min_score', '0.5');
        Http::fake([
            Recaptcha::VERIFY_URL => Http::sequence()
                ->push(['success' => true, 'score' => 0.1, 'action' => 'checkout', 'hostname' => 'localhost'])
                ->push(['success' => true, 'score' => 0.9, 'action' => 'contact', 'hostname' => 'localhost'])
                ->push(['success' => true, 'score' => 0.9, 'action' => 'checkout', 'hostname' => 'localhost']),
        ]);

        $this->post(route('shop.checkout.store'), $this->payload(['recaptcha_token' => 'low']))->assertSessionHasErrors('recaptcha_token');
        $this->post(route('shop.checkout.store'), $this->payload(['recaptcha_token' => 'other-form']))->assertSessionHasErrors('recaptcha_token');
        $this->post(route('shop.checkout.store'), $this->payload())->assertSessionHasErrors('recaptcha_token');
        $this->assertDatabaseCount('orders', 0);

        $this->post(route('shop.checkout.store'), $this->payload(['recaptcha_token' => 'good']))->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(0.9, Order::query()->sole()->recaptcha_score, 0.001);
    }

    public function test_checkout_posts_are_rate_limited_per_ip(): void
    {
        foreach (range(1, 10) as $i) {
            $this->post(route('shop.checkout.store'), $this->payload([CheckoutRequest::HONEYPOT_FIELD => 'bot']))->assertRedirect();
        }

        $this->post(route('shop.checkout.store'), $this->payload())->assertStatus(429);
    }

    public function test_payment_method_and_terms_are_checked(): void
    {
        $this->cartWith(CheckoutFixtures::product(), 1);
        $this->post(route('shop.checkout.store'), $this->payload(['payment_method' => 'bancard']))->assertSessionHasErrors(['payment_method' => __('checkout.error_method_unavailable')]);
        $this->post(route('shop.checkout.store'), $this->payload(['payment_method' => 'no-existe']))->assertSessionHasErrors('payment_method');

        $terms = Page::create(['title' => 'Políticas', 'slug' => 'politicas', 'locale' => 'es', 'template_key' => 'default', 'content_json' => ['sections' => ['content' => ['heading' => 'Políticas']]], 'status' => 'published', 'published_at' => now()]);
        $this->setSetting('shop_terms_page_id', (string) $terms->id);
        $this->get(route('shop.checkout.show'))->assertSee('name="accept_terms"', false)->assertSee('href="'.e($terms->url()).'"', false);
        $this->post(route('shop.checkout.store'), $this->payload())->assertSessionHasErrors(['accept_terms' => __('checkout.error_terms_required')]);
        $this->assertDatabaseCount('orders', 0);

        $this->post(route('shop.checkout.store'), $this->payload(['accept_terms' => '1', 'payment_method' => 'cod']))->assertSessionHasNoErrors();
        $this->assertSame('cod', Order::query()->sole()->payment_method_key);
    }

    public function test_an_empty_or_changed_cart_goes_back_to_the_cart(): void
    {
        $this->get(route('shop.checkout.show'))->assertRedirect(route('shop.cart.index'))->assertSessionHasErrors(['cart' => __('checkout.checkout_cart_empty')]);
        $this->post(route('shop.checkout.store'), $this->payload())->assertRedirect(route('shop.cart.index'))->assertSessionHasErrors('cart');

        $product = CheckoutFixtures::product(['stock' => 3]);
        $this->cartWith($product, 2);
        $this->get(route('shop.checkout.show'))->assertOk();
        // Another customer bought it meanwhile (or the admin lowered the stock).
        $product->update(['stock' => 1]);
        $this->post(route('shop.checkout.store'), $this->payload())->assertRedirect(route('shop.cart.index'))->assertSessionHasErrors(['cart' => __('checkout.error_cart_changed')]);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_numbers_are_unique_and_retried_on_collision(): void
    {
        $this->cartWith(CheckoutFixtures::product(), 1);
        $this->post(route('shop.checkout.store'), $this->payload())->assertRedirect();
        $this->cartWith(CheckoutFixtures::product(), 1);
        $this->post(route('shop.checkout.store'), $this->payload())->assertRedirect();
        $year = now()->format('Y');
        $this->assertSame(['ORD-'.$year.'-000001', 'ORD-'.$year.'-000002'], Order::query()->orderBy('id')->pluck('number')->all());

        // A generator that does not see the last order (another checkout committed it a moment ago) collides once
        // with the unique index and retries with the next number.
        $stale = new class extends OrderNumberGenerator
        {
            protected function lastSequence(string $prefix): int
            {
                return 1;
            }
        };
        $this->app->instance(OrderNumberGenerator::class, $stale);
        $this->cartWith(CheckoutFixtures::product(), 1);
        $this->post(route('shop.checkout.store'), $this->payload())->assertRedirect();
        $this->assertSame('ORD-'.$year.'-000003', Order::query()->latest('id')->value('number'));

        // next(1) skips one number past the last one seen.
        $this->assertSame('ORD-'.$year.'-000005', (new OrderNumberGenerator)->next(1));
    }

    public function test_order_page_is_reached_only_by_its_token(): void
    {
        $order = CheckoutFixtures::order([[CheckoutFixtures::product(['title' => 'Termo'])]]);
        $token = $order->plainAccessToken();

        $this->get(route('shop.order.show', $token))
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertSee('<meta name="robots" content="noindex, nofollow, noarchive">', false)
            ->assertSee($order->number)
            ->assertSee('Termo')
            ->assertSee(__('checkout.status_pending'));

        $this->get('/productos/pedido/'.str_repeat('a', Order::ACCESS_TOKEN_LENGTH))->assertNotFound();
        $this->get('/productos/pedido/'.$order->number)->assertNotFound();
        $this->get('/productos/pedido/'.$order->id)->assertNotFound();
        $this->get('/productos/pedido/'.substr($token, 0, 48))->assertNotFound();
        $this->get('/productos/pedido/'.$order->access_token_hash)->assertNotFound();
    }

    public function test_manual_methods_show_their_instructions(): void
    {
        $transfer = CheckoutFixtures::order();
        $this->get(route('shop.order.show', $transfer->plainAccessToken()))
            ->assertOk()
            ->assertSee('data-order-payment="collect"', false)
            ->assertSee(__('checkout.payment_instructions'))
            ->assertSee(CheckoutFixtures::BANK_DETAILS['bank_name'])
            ->assertSee(CheckoutFixtures::BANK_DETAILS['account_number'])
            ->assertSee(__('checkout.transfer_reference', ['number' => $transfer->number]))
            ->assertDontSee('data-payment-iframe', false);

        $cod = CheckoutFixtures::order([], ['method' => 'cod']);
        $this->get(route('shop.order.show', $cod->plainAccessToken()))
            ->assertOk()
            ->assertSee(CheckoutFixtures::COD_INSTRUCTIONS)
            ->assertDontSee(CheckoutFixtures::BANK_DETAILS['bank_name']);
    }

    public function test_online_provider_mounts_its_iframe_and_the_return_is_not_trusted(): void
    {
        $fake = $this->useFakePaymentProvider();
        $this->cartWith(CheckoutFixtures::product(['price_amount' => 150000]), 1);
        $this->post(route('shop.checkout.store'), $this->payload(['payment_method' => FakePaymentProvider::KEY]))->assertRedirect();
        $order = Order::query()->sole();
        $payment = $order->payments()->sole();
        $this->assertSame([FakePaymentProvider::KEY, $payment->id], [$payment->provider, $payment->shop_process_id]);
        $url = route('shop.order.show', $order->plainAccessToken());

        $this->get($url)
            ->assertOk()
            ->assertSee('data-payment-iframe', false)
            ->assertSee('data-process-id="fake-'.$payment->id.'"', false)
            ->assertSee('<script src="'.FakePaymentProvider::SCRIPT_URL.'"></script>', false);
        $this->assertSame(['start'], $fake->calledMethods());

        // Back from the payment form: "?status=payment_success" confirms nothing.
        $this->get($url.'?status=payment_success')
            ->assertOk()
            ->assertSee('data-order-payment="confirming"', false)
            ->assertSee(__('checkout.payment_confirming'))
            ->assertSee('http-equiv="refresh"', false)
            ->assertDontSee('data-payment-iframe', false);
        $this->assertSame([Payment::STATUS_PENDING, Order::STATUS_PENDING], [$payment->fresh()->status, $order->fresh()->status]);
        // It asks the provider once (track 3C2); with nothing paid yet the page keeps confirming.
        $this->assertSame(['start', 'query'], $fake->calledMethods());

        // The service is down: the page explains it instead of failing.
        $fake->failStart = true;
        $this->get($url)->assertOk()->assertSee('data-payment-start-failed', false);
    }

    public function test_a_failed_online_attempt_can_be_retried_with_a_new_attempt(): void
    {
        $this->useFakePaymentProvider();
        $order = CheckoutFixtures::order([], ['method' => FakePaymentProvider::KEY]);
        $first = $order->latestPayment;
        $url = route('shop.order.show', $order->plainAccessToken());
        $pay = route('shop.order.pay', $order->plainAccessToken());

        // Pending: "try again" changes nothing.
        $this->post($pay)->assertRedirect($url);
        $this->assertSame(1, $order->payments()->count());

        $first->forceFill(['status' => Payment::STATUS_FAILED, 'failed_at' => now(), 'response_description' => 'Tarjeta sin fondos', 'response_code' => '51'])->save();
        $this->get($url)
            ->assertOk()
            ->assertSee('data-order-payment="failed"', false)
            ->assertSee(__('checkout.payment_failed_retry'))
            ->assertSee('Tarjeta sin fondos')
            ->assertDontSee('>51<', false)
            ->assertSee('action="'.$pay.'"', false);

        $this->post($pay)->assertRedirect($url);
        $retry = $order->payments()->get()->last();
        $this->assertNotSame($first->id, $retry->id);
        $this->assertSame([Payment::STATUS_PENDING, $retry->id, 150000], [$retry->status, $retry->shop_process_id, $retry->amount->amount]);
        $this->get($url)->assertSee('data-process-id="fake-'.$retry->id.'"', false);

        // Manual methods and orders that no longer wait for a payment are not retried.
        $manual = CheckoutFixtures::order();
        $manual->latestPayment->forceFill(['status' => Payment::STATUS_FAILED])->save();
        $this->post(route('shop.order.pay', $manual->plainAccessToken()))->assertRedirect();
        $this->assertSame(1, $manual->payments()->count());
        $this->post(route('shop.order.pay', str_repeat('b', Order::ACCESS_TOKEN_LENGTH)))->assertNotFound();
    }

    public function test_confirmed_payment_shows_only_what_bancard_allows(): void
    {
        $order = CheckoutFixtures::order();
        $order->forceFill(['status' => Order::STATUS_PAID, 'paid_at' => now()])->save();
        $order->latestPayment->forceFill([
            'status' => Payment::STATUS_CONFIRMED,
            'confirmed_at' => now(),
            'authorization_number' => '123456',
            'response_code' => '00',
            'response_description' => 'Transacción aprobada',
            'payload_json' => ['extended_response_description' => 'detalle interno', 'security_information' => ['card_source' => 'L']],
        ])->save();

        $this->get(route('shop.order.show', $order->plainAccessToken()))
            ->assertOk()
            ->assertSee('data-payment-receipt', false)
            ->assertSee('123456')
            ->assertSee('Transacción aprobada')
            ->assertSee($order->number)
            ->assertSee('Gs. 150.000')
            ->assertDontSee('detalle interno')
            ->assertDontSee('card_source')
            ->assertDontSee('data-payment-instructions', false);
    }

    public function test_order_page_survives_leaving_checkout_mode(): void
    {
        $order = CheckoutFixtures::order();
        $this->useShop('catalog');

        $this->get(route('shop.order.show', $order->plainAccessToken()))->assertOk()->assertSee($order->number);
        $this->get(route('shop.checkout.show'))->assertNotFound();
        $this->post(route('shop.checkout.store'), $this->payload())->assertNotFound();

        $this->useShop('off');
        $this->get(route('shop.order.show', $order->plainAccessToken()))->assertNotFound();
    }

    public function test_localized_checkout_places_the_order_in_that_language(): void
    {
        $this->cartWith(CheckoutFixtures::product(), 1);
        $token = $this->formToken('en');

        $response = $this->post(route('localized.shop.checkout.store', ['locale' => 'en']), $this->payload(['_form_token' => $token]));
        $order = Order::query()->sole();
        $response->assertRedirect(route('localized.shop.order.show', ['locale' => 'en', 'token' => $order->plainAccessToken()]));
        $this->assertSame('en', $order->locale);
    }

    public function test_checkout_service_exposes_the_offered_choices(): void
    {
        $service = app(CheckoutService::class);
        $this->assertSame('Av. España 123, Asunción', $service->pickupAddress());
        $this->assertSame([$this->zone->id], $service->zones()->pluck('id')->all());
        $this->assertSame(['bank_transfer', 'cod'], $service->paymentMethods()->pluck('key')->all());
        $this->assertSame(__('checkout.method_bank_transfer'), CheckoutService::methodLabel('bank_transfer'));
        $this->assertSame('custom', CheckoutService::methodLabel('custom'));

        $this->setSetting('shop_pickup_address', '  ');
        $this->assertNull($service->pickupAddress());
    }

    /**
     * Payload of a valid pickup order paid by bank transfer, with a form token issued 10 seconds ago.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Ana Pérez',
            'customer_email' => 'ana@example.com',
            'customer_phone' => '0981 123 456',
            'delivery_method' => 'pickup',
            'payment_method' => 'bank_transfer',
            CheckoutRequest::HONEYPOT_FIELD => '',
            '_form_token' => $this->formToken(),
        ], $overrides);
    }

    private function formToken(string $locale = 'es'): string
    {
        $this->travel(-10)->seconds();
        $token = app(FormToken::class)->issue(CheckoutRequest::PAGE_ID, CheckoutRequest::FORM, $locale);
        $this->travelBack();

        return $token;
    }

    /**
     * A live cart behind the request cookie.
     *
     * @param  list<array{0: Product, 1?: int, 2?: ProductVariant|null}>  $more
     */
    private function cartWith(Product $product, int $quantity, array $more = []): Cart
    {
        $cart = CheckoutFixtures::cart([[$product, $quantity], ...$more]);
        $this->withCookie(CartService::COOKIE, $cart->token);

        return $cart;
    }

    private function tokenFrom(?string $location): string
    {
        $this->assertNotNull($location);

        return basename((string) parse_url($location, PHP_URL_PATH));
    }

    private function setSetting(string $key, ?string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value] + SettingDefaults::for($key));
        Setting::clearCache();
    }
}
