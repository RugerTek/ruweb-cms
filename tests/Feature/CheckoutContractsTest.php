<?php

namespace Tests\Feature;

use App\Console\Commands\ShopExpirePaymentsCommand;
use App\Contracts\Shop\PaymentProvider;
use App\Events\Shop\OrderCancelled;
use App\Events\Shop\OrderPaid;
use App\Events\Shop\OrderPlaced;
use App\Events\Shop\PaymentFailed;
use App\Events\Shop\StockShortfall;
use App\Http\Middleware\ApplyRedirects;
use App\Http\Middleware\EnsureShopCatalog;
use App\Http\Middleware\EnsureShopCheckout;
use App\Http\Middleware\SetPublicLocale;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Locale;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Page;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\ShippingZone;
use App\Models\User;
use App\Services\Products\ProductInput;
use App\Services\Settings\SettingOperations;
use App\Services\Shop\CheckoutReadiness;
use App\Services\Shop\ReadinessItem;
use App\Services\Shop\ShopModuleOperations;
use App\Services\Snapshots\SnapshotService;
use App\Shop\Payments\Bancard\BancardProvider;
use App\Shop\Payments\CancelResult;
use App\Shop\Payments\ManualPaymentProvider;
use App\Shop\Payments\PaymentNotification;
use App\Shop\Payments\PaymentProviderRegistry;
use App\Shop\Payments\PaymentStart;
use App\Shop\Payments\PaymentUrls;
use App\Support\SettingDefaults;
use App\Support\ShopModule;
use App\Support\SiteUrl;
use App\ValueObjects\Money;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CheckoutFixtures;
use Tests\Support\FakeBancard;
use Tests\Support\FakePaymentProvider;
use Tests\TestCase;
use ZipArchive;

/** Data, contracts and surface of the checkout (PLAN.md, stage 3): P0a data and providers, P0b routes, readiness, slugs, CSRF and snapshots. */
class CheckoutContractsTest extends TestCase
{
    use RefreshDatabase;

    private const STAGING = 'https://vpos.infonet.com.py:8888';

    private const PRODUCTION = 'https://vpos.infonet.com.py';

    public function test_checkout_tables_have_their_columns(): void
    {
        $tables = [
            'carts' => ['token', 'expires_at'],
            'cart_items' => ['cart_id', 'product_id', 'variant_id', 'line_key', 'quantity'],
            'orders' => ['number', 'status', 'locale', 'access_token_hash', 'access_token', 'customer_name', 'customer_email', 'customer_phone', 'delivery_method', 'shipping_zone_id', 'shipping_zone_name', 'payment_method_key', 'currency', 'subtotal_amount', 'shipping_amount', 'total_amount', 'notes', 'internal_notes', 'stock_applied_at', 'stock_shortfall', 'ip', 'user_agent', 'recaptcha_score', 'placed_at', 'paid_at', 'fulfilled_at', 'cancelled_at', 'refunded_at'],
            'order_items' => ['order_id', 'product_id', 'variant_id', 'title', 'variant_name', 'sku', 'unit_price_amount', 'quantity', 'line_total_amount'],
            'order_addresses' => ['order_id', 'type', 'address', 'city', 'department', 'reference'],
            'payment_methods' => ['key', 'provider', 'enabled', 'sort_order', 'settings_json', 'secrets', 'environment', 'bound_origin'],
            'payments' => ['order_id', 'method_key', 'provider', 'status', 'amount', 'currency', 'environment', 'shop_process_id', 'process_id', 'authorization_number', 'ticket_number', 'response_code', 'response_description', 'payload_json', 'confirmed_at', 'failed_at', 'expired_at', 'refunded_at'],
            'payment_events' => ['payment_id', 'provider', 'type', 'token_valid', 'payload_json', 'created_at'],
            'shipping_zones' => ['name', 'price_amount', 'enabled', 'sort_order'],
        ];

        foreach ($tables as $table => $columns) {
            $this->assertTrue(Schema::hasColumns($table, $columns), $table.' lacks a column.');
        }
        $this->assertFalse(Schema::hasColumn('payment_events', 'updated_at'));
    }

    public function test_status_columns_only_accept_the_state_machine_values(): void
    {
        $order = CheckoutFixtures::order();
        foreach (Order::STATUSES as $status) {
            DB::table('orders')->where('id', $order->id)->update(['status' => $status]);
        }
        foreach (Payment::STATUSES as $status) {
            DB::table('payments')->where('order_id', $order->id)->update(['status' => $status]);
        }
        $this->assertSame(Order::STATUSES, array_keys(Order::TRANSITIONS));
        $this->assertSame(Payment::STATUSES, array_keys(Payment::TRANSITIONS));

        $rejected = 0;
        foreach ([['orders', 'status', 'shipped'], ['payments', 'status', 'done'], ['orders', 'delivery_method', 'drone']] as [$table, $column, $value]) {
            try {
                DB::table($table)->update([$column => $value]);
            } catch (QueryException) {
                $rejected++;
            }
        }
        $this->assertSame(3, $rejected);
    }

    public function test_settings_and_payment_methods_start_disabled_and_empty(): void
    {
        foreach (['shop_orders_email' => null, 'shop_pickup_enabled' => '0', 'shop_pickup_address' => null, 'shop_terms_page_id' => null] as $key => $value) {
            $this->assertDatabaseHas('settings', ['key' => $key, 'value' => $value, 'group' => 'shop']);
            $this->assertSame($value, SettingDefaults::for($key)['value']);
        }
        $this->assertFalse(Setting::get('shop_pickup_enabled'));

        $methods = PaymentMethod::query()->orderBy('sort_order')->get();
        $this->assertSame(['bank_transfer', 'cod', 'bancard'], $methods->pluck('key')->all());
        $this->assertSame(['manual', 'manual', 'bancard'], $methods->pluck('provider')->all());
        foreach ($methods as $method) {
            $this->assertFalse($method->enabled);
            $this->assertNull($method->settings_json);
            $this->assertSame([], $method->readSecrets());
            $this->assertNull($method->bound_origin);
        }
        $this->assertSame('staging', $methods->last()->environment);
        $this->assertSame(0, PaymentMethod::query()->enabled()->count());
    }

    public function test_a_cart_keeps_one_line_per_product_and_variant(): void
    {
        $product = CheckoutFixtures::product();
        $withVariants = CheckoutFixtures::productWithVariants();
        [$small, $large] = $withVariants->variants->all();
        $cart = CheckoutFixtures::cart([[$product, 2], [$withVariants, 1, $small], [$withVariants, 1, $large]]);

        $this->assertSame(
            [$product->id.':0', $withVariants->id.':'.$small->id, $withVariants->id.':'.$large->id],
            $cart->items->pluck('line_key')->all(),
        );
        $this->assertSame(CartItem::lineKey($product->id, null), $cart->items[0]->line_key);
        $this->assertSame($small->id, $cart->items[1]->variant->id);
        $this->assertSame(64, strlen($cart->token));
        $this->assertArrayNotHasKey('token', $cart->toArray());
        $this->assertTrue($cart->is(Cart::findByToken($cart->token)));
        $this->assertNull(Cart::findByToken('short'));

        try {
            CartItem::create(['cart_id' => $cart->id, 'product_id' => $product->id, 'variant_id' => null, 'quantity' => 1]);
            $this->fail('A second line for the same product without variant was stored.');
        } catch (QueryException) {
            $this->assertSame(3, $cart->items()->count());
        }

        $cart->update(['expires_at' => now()->subMinute()]);
        $this->assertNull(Cart::findByToken($cart->token));
        $this->assertTrue($cart->fresh()->isExpired());
        $this->assertSame(1, Cart::query()->expired()->count());
    }

    public function test_orders_are_found_only_by_their_hashed_token(): void
    {
        $order = CheckoutFixtures::order();
        $token = $order->plainAccessToken();
        $raw = DB::table('orders')->where('id', $order->id)->first();

        $this->assertSame(Order::ACCESS_TOKEN_LENGTH, strlen($token));
        $this->assertSame(hash('sha256', $token), $raw->access_token_hash);
        $this->assertStringNotContainsString($token, $raw->access_token);
        $this->assertTrue($order->is(Order::findByAccessToken($token)));
        $this->assertNull(Order::findByAccessToken($order->number));
        $this->assertNull(Order::findByAccessToken(str_repeat('a', Order::ACCESS_TOKEN_LENGTH)));
        $this->assertMatchesRegularExpression('/^ORD-\d{4}-000001$/', $order->number);
        $json = $order->toJson();
        $this->assertStringNotContainsString($token, $json);
        $this->assertArrayNotHasKey('access_token_hash', $order->toArray());

        // Restored under another APP_KEY: the page still opens by hash, the link cannot be resent.
        $other = new Encrypter(random_bytes(32), 'AES-256-CBC');
        DB::table('orders')->where('id', $order->id)->update(['access_token' => $other->encryptString($token)]);
        $this->assertNull($order->fresh()->plainAccessToken());
        $this->assertTrue($order->is(Order::findByAccessToken($token)));
    }

    public function test_orders_freeze_lines_delivery_and_amounts(): void
    {
        $zone = CheckoutFixtures::shippingZone('Central', 30000);
        $product = CheckoutFixtures::product(['sku' => 'MATE-1', 'price_amount' => 120000]);
        $order = CheckoutFixtures::order([[$product, 2]], ['zone' => $zone]);

        $this->assertSame(Order::DELIVERY_DELIVERY, $order->delivery_method);
        $this->assertFalse($order->isPickup());
        $this->assertSame('Central', $order->shipping_zone_name);
        $this->assertInstanceOf(Money::class, $order->total_amount);
        $this->assertSame(240000, $order->subtotal_amount->amount);
        $this->assertSame(30000, $order->shipping_amount->amount);
        $this->assertSame(270000, $order->total_amount->amount);
        $this->assertSame('Gs. 270.000', $order->total_amount->format());
        $this->assertSame('Asunción', $order->shippingAddress->city);
        $this->assertSame('MATE-1', $order->items->first()->sku);
        $this->assertSame(240000, $order->items->first()->line_total_amount->amount);
        $this->assertTrue($zone->is($order->shippingZone));
        $this->assertSame('bank_transfer', $order->paymentMethod->key);
        $this->assertSame(Payment::STATUS_PENDING, $order->latestPayment->status);
        $this->assertSame(270000, $order->latestPayment->amount->amount);
        $this->assertNull($order->latestPayment->shop_process_id);
        $this->assertSame(10, $product->fresh()->stock, 'Placing an order never takes stock (P3).');

        // Deleting the product or the zone keeps the frozen copies.
        $product->delete();
        $zone->delete();
        $item = OrderItem::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertNull($item->product_id);
        $this->assertSame($product->title, $item->title);
        $this->assertNull($order->fresh()->shipping_zone_id);
        $this->assertSame('Central', $order->fresh()->shipping_zone_name);

        $pickup = CheckoutFixtures::order();
        $this->assertTrue($pickup->isPickup());
        $this->assertNull($pickup->shippingAddress);
        $this->assertSame(0, $pickup->shipping_amount->amount);
    }

    public function test_order_and_payment_transitions_follow_the_plan(): void
    {
        $order = new Order(['status' => 'pending']);
        $this->assertSame(['paid', 'cancelled'], $order->availableTransitions());
        $this->assertFalse($order->canTransitionTo('fulfilled'));
        $this->assertSame(['fulfilled', 'cancelled', 'refunded'], (new Order(['status' => 'paid']))->availableTransitions());
        $this->assertSame(['refunded'], (new Order(['status' => 'fulfilled']))->availableTransitions());
        $this->assertSame([], (new Order(['status' => 'cancelled']))->availableTransitions());
        $this->assertSame([], (new Order(['status' => 'refunded']))->availableTransitions());

        $payment = new Payment(['status' => 'pending']);
        $this->assertTrue($payment->isPending());
        $this->assertFalse($payment->isFinal());
        $this->assertSame(['confirmed', 'failed', 'expired'], $payment->availableTransitions());
        $confirmed = new Payment(['status' => 'confirmed']);
        $this->assertFalse($confirmed->isFinal());
        $this->assertTrue($confirmed->canTransitionTo('refunded'));
        foreach (['failed', 'expired', 'refunded'] as $status) {
            $this->assertTrue((new Payment(['status' => $status]))->isFinal(), $status);
        }
    }

    public function test_payment_method_secrets_are_encrypted_and_bound_to_the_site(): void
    {
        $bancard = PaymentMethod::query()->where('key', 'bancard')->firstOrFail();
        (new FakeBancard)->configure($bancard);
        $raw = (string) DB::table('payment_methods')->where('key', 'bancard')->value('secrets');

        $this->assertStringNotContainsString(FakeBancard::STAGING_PRIVATE_KEY, $raw);
        $this->assertStringNotContainsString(FakeBancard::STAGING_PUBLIC_KEY, $raw);
        $fresh = $bancard->fresh();
        $this->assertSame(['public_key' => FakeBancard::STAGING_PUBLIC_KEY, 'private_key' => FakeBancard::STAGING_PRIVATE_KEY], $fresh->credentials('staging'));
        $this->assertSame(FakeBancard::PRODUCTION_PUBLIC_KEY, $fresh->credentials('production')['public_key']);
        $this->assertStringNotContainsString(FakeBancard::STAGING_PRIVATE_KEY, $fresh->toJson());
        $this->assertTrue($fresh->isBoundToThisSite());
        $this->assertSame(SiteUrl::origin(), $fresh->bound_origin);

        config(['app.url' => 'https://copia-de-staging.example']);
        $this->assertFalse($fresh->isBoundToThisSite());

        $other = new Encrypter(random_bytes(32), 'AES-256-CBC');
        DB::table('payment_methods')->where('key', 'bancard')->update(['secrets' => $other->encryptString(json_encode(['staging' => ['public_key' => 'x', 'private_key' => 'y']]))]);
        $unreadable = $bancard->fresh();
        $this->assertNull($unreadable->readSecrets());
        $this->assertTrue($unreadable->secretsUnreadable());
        $this->assertNull($unreadable->credentials('staging'));

        $unreadable->update(['secrets' => ['staging' => ['public_key' => FakeBancard::STAGING_PUBLIC_KEY]]]);
        $this->assertNull($unreadable->fresh()->credentials('staging'), 'An incomplete pair is not a credential.');
        $this->assertNull($unreadable->fresh()->credentials('production'));
    }

    public function test_registry_offers_only_enabled_and_ready_methods(): void
    {
        $registry = app(PaymentProviderRegistry::class);
        $this->assertSame(['manual', 'bancard'], array_keys($registry->all()));
        // A site that does not offer Bancard: without its provider the method is never offered.
        config(['cms.shop.payment_providers' => ['manual' => ManualPaymentProvider::class]]);
        $this->assertSame(['manual'], array_keys($registry->all()));
        $this->assertInstanceOf(ManualPaymentProvider::class, $registry->get('manual'));

        $transfer = PaymentMethod::query()->where('key', 'bank_transfer')->firstOrFail();
        $cod = PaymentMethod::query()->where('key', 'cod')->firstOrFail();
        $bancard = PaymentMethod::query()->where('key', 'bancard')->firstOrFail();
        $this->assertSame(['bank_name', 'account_holder', 'account_number'], $registry->missing($transfer));
        $this->assertSame(['instructions'], $registry->missing($cod));
        $this->assertSame([PaymentProviderRegistry::PROVIDER_UNAVAILABLE], $registry->missing($bancard));

        $transfer->update(['enabled' => true, 'settings_json' => ['bank_name' => 'Banco', 'account_holder' => '  ', 'account_number' => '123']]);
        $this->assertSame(['account_holder'], $registry->missing($transfer->fresh()), 'Blank values count as missing.');
        $this->assertCount(0, $registry->available());

        CheckoutFixtures::enableManualMethods();
        $bancard->update(['enabled' => true]);
        $this->assertSame(['bank_transfer', 'cod'], $registry->available()->pluck('key')->all());
        $this->assertSame('cod', $registry->availableMethod('cod')?->key);
        $this->assertNull($registry->availableMethod('bancard'));
        $this->assertNull($registry->availableMethod('missing'));
        $cod->fresh()->update(['enabled' => false]);
        $this->assertNull($registry->availableMethod('cod'));

        $this->expectException(LogicException::class);
        $registry->for($bancard);
    }

    public function test_registry_rejects_misconfigured_providers(): void
    {
        config(['cms.shop.payment_providers.wrong' => ManualPaymentProvider::class]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('return "wrong" from key()');
        app(PaymentProviderRegistry::class)->get('wrong');
    }

    public function test_manual_provider_shows_instructions_and_never_trusts_requests(): void
    {
        CheckoutFixtures::enableManualMethods();
        PaymentMethod::query()->where('key', 'bank_transfer')->firstOrFail()->update(['settings_json' => CheckoutFixtures::BANK_DETAILS + ['alias' => '', 'internal' => 'no se muestra']]);
        $provider = app(PaymentProviderRegistry::class)->get('manual');
        $order = CheckoutFixtures::order();
        $payment = $order->latestPayment;

        $start = $provider->start($payment, new PaymentUrls('https://tienda.example/productos/pedido/abc'));
        $this->assertSame(PaymentStart::KIND_INSTRUCTIONS, $start->kind);
        $this->assertFalse($start->isIframe());
        $this->assertSame(CheckoutFixtures::BANK_DETAILS, $start->instructions);

        $cod = CheckoutFixtures::order([], ['method' => 'cod']);
        $this->assertSame(['instructions' => CheckoutFixtures::COD_INSTRUCTIONS], $provider->start($cod->latestPayment, new PaymentUrls('https://tienda.example/x'))->instructions);

        $request = Request::create('/webhooks/manual', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"reference":"1","approved":true}');
        $this->assertNull($provider->parseNotification($request));
        $this->assertFalse($provider->verify(new PaymentNotification('manual', (string) $payment->id, true, $payment->amount), $payment));
        $this->assertNull($provider->query($payment));

        $this->assertEquals(CancelResult::success(ManualPaymentProvider::CODE_NOTHING_TO_CANCEL), $provider->cancel($payment));
        $payment->update(['status' => Payment::STATUS_CONFIRMED]);
        $result = $provider->cancel($payment);
        $this->assertFalse($result->ok);
        $this->assertTrue($result->manualRequired);
        $this->assertSame(ManualPaymentProvider::CODE_MANUAL_REFUND, $result->code);
    }

    public function test_payment_dtos_guard_their_invariants(): void
    {
        $urls = new PaymentUrls('https://tienda.example/pedido/x');
        $this->assertSame('https://tienda.example/pedido/x', $urls->cancelUrl);
        $this->assertSame('https://tienda.example/cancel', (new PaymentUrls('https://tienda.example/pedido/x', 'https://tienda.example/cancel'))->cancelUrl);

        $iframe = PaymentStart::iframe('proc-1', 'https://vpos.example/checkout.js');
        $this->assertTrue($iframe->isIframe());
        $this->assertSame('proc-1', $iframe->processId);

        $notification = new PaymentNotification('bancard', '15', true, Money::fromInt(150000));
        $this->assertTrue($notification->matchesAmount(Money::fromInt(150000)));
        $this->assertFalse($notification->matchesAmount(Money::fromInt(150001)));
        $this->assertFalse($notification->matchesAmount(new Money(150000, 'USD')));
        $this->assertFalse((new PaymentNotification('bancard', '15', true))->matchesAmount(Money::fromInt(150000)));

        $this->assertTrue(CancelResult::manual('TransactionAlreadyConfirmed')->manualRequired);
        $this->assertFalse(CancelResult::failed('PosCommunicationError')->ok);

        foreach ([
            fn () => new PaymentUrls('/productos/pedido/x'),
            fn () => new PaymentUrls('https://tienda.example/x', 'javascript:alert(1)'),
            fn () => new PaymentStart(PaymentStart::KIND_IFRAME, 'proc-1'),
            fn () => new PaymentStart('redirect'),
        ] as $index => $invalid) {
            try {
                $invalid();
                $this->fail('Invalid DTO #'.$index.' was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_fake_bancard_validates_tokens_and_shapes_like_bancard(): void
    {
        $fake = $this->useFakeBancard();
        $keys = $fake->keys('staging');
        $buy = fn (array $operation, string $base = self::STAGING, ?string $publicKey = null) => Http::post($base.'/vpos/api/0.3/single_buy', [
            'public_key' => $publicKey ?? $keys['public_key'],
            'operation' => $operation + ['shop_process_id' => 7, 'amount' => '150000.00', 'currency' => 'PYG', 'description' => 'Pedido ORD-2026-000001', 'return_url' => 'https://tienda.example/pedido/x'],
        ])->json();

        // Vectors computed by hand: md5("<staging private key>7150000.00PYG") and friends.
        $this->assertSame('b09d9a64acbf0505225f77454ecada93', md5(FakeBancard::STAGING_PRIVATE_KEY.'7150000.00PYG'));
        $ok = $buy(['token' => 'b09d9a64acbf0505225f77454ecada93']);
        $this->assertSame('success', $ok['status']);
        $this->assertSame(20, strlen($ok['process_id']));

        $this->assertSame('InvalidTokenError', $buy(['token' => md5('otra-clave'.'7150000.00PYG')])['messages'][0]['key']);
        $this->assertSame('InvalidPublicKeyError', $buy(['token' => 'b09d9a64acbf0505225f77454ecada93'], publicKey: FakeBancard::PRODUCTION_PUBLIC_KEY)['messages'][0]['key']);
        $this->assertSame('InvalidOperationError', $buy(['token' => 'b09d9a64acbf0505225f77454ecada93', 'amount' => '150000'])['messages'][0]['key']);
        $this->assertSame('InvalidPublicKeyError', $buy(['token' => 'b09d9a64acbf0505225f77454ecada93'], self::PRODUCTION)['messages'][0]['key'], 'Production checks the production keys.');
        $this->assertSame(400, Http::post(self::STAGING.'/vpos/api/0.3/single_buy', ['public_key' => $keys['public_key']])->status());

        $confirmation = Http::post(self::STAGING.'/vpos/api/0.3/single_buy/confirmations', [
            'public_key' => $keys['public_key'],
            'operation' => ['token' => '0d2f69f263800649ba6ea16c27ad9bc9', 'shop_process_id' => 7],
        ])->json();
        $this->assertSame('success', $confirmation['status']);
        $this->assertSame('00', $confirmation['confirmation']['response_code']);
        $this->assertSame('84a643386f3e6af4f8d546a484125e06', $confirmation['confirmation']['token']);
        $notFound = Http::post(self::STAGING.'/vpos/api/0.3/single_buy/confirmations', [
            'public_key' => $keys['public_key'],
            'operation' => ['token' => md5(FakeBancard::STAGING_PRIVATE_KEY.'8get_confirmation'), 'shop_process_id' => 8],
        ])->json();
        $this->assertSame('BuyNotFoundError', $notFound['messages'][0]['key']);

        $rollback = fn () => Http::post(self::STAGING.'/vpos/api/0.3/single_buy/rollback', [
            'public_key' => $keys['public_key'],
            'operation' => ['token' => '0edab61b10b1972108e9659cd66e35c2', 'shop_process_id' => 7],
        ])->json();
        $this->assertSame('RollbackSuccessful', $rollback()['messages'][0]['key']);
        $fake->scenario(FakeBancard::OPERATION_ROLLBACK, FakeBancard::ALREADY_CONFIRMED);
        $this->assertSame('TransactionAlreadyConfirmed', $rollback()['messages'][0]['key']);

        $fake->assertCalled(FakeBancard::OPERATION_SINGLE_BUY, 6);
        $fake->assertCalled(FakeBancard::OPERATION_CONFIRMATIONS, 2);
        $fake->assertCalled(FakeBancard::OPERATION_ROLLBACK, 2);
        $this->assertSame(404, Http::get(self::STAGING.'/checkout/new?process_id=x')->status(), 'No request leaves for Bancard.');

        $fake->scenario(FakeBancard::OPERATION_CONFIRMATIONS, FakeBancard::TIMEOUT);
        $this->expectException(ConnectionException::class);
        Http::post(self::STAGING.'/vpos/api/0.3/single_buy/confirmations', ['public_key' => $keys['public_key'], 'operation' => ['token' => 'x', 'shop_process_id' => 7]]);
    }

    public function test_fake_bancard_builds_webhooks_with_the_attempt_environment_key(): void
    {
        $fake = $this->useFakeBancard();
        $bancard = PaymentMethod::query()->where('key', 'bancard')->firstOrFail();
        $this->assertTrue($bancard->enabled);
        $this->assertSame('staging', $bancard->environment);
        $this->assertStringNotContainsString(FakeBancard::STAGING_PRIVATE_KEY, (string) DB::table('payment_methods')->where('key', 'bancard')->value('secrets'));

        $order = CheckoutFixtures::order([[CheckoutFixtures::product()]], ['method' => 'bancard']);
        $payment = $order->latestPayment;
        $this->assertSame('bancard', $payment->provider);
        $this->assertSame('staging', $payment->environment);
        $this->assertSame($payment->id, $payment->shop_process_id);
        $payment->forceFill(['shop_process_id' => 7])->save();

        $approved = $fake->webhookPayload($payment)['operation'];
        $this->assertSame('84a643386f3e6af4f8d546a484125e06', $approved['token']);
        $this->assertSame(['S', '00', '150000.00', 'PYG', 7], [$approved['response'], $approved['response_code'], $approved['amount'], $approved['currency'], $approved['shop_process_id']]);
        $rejected = $fake->webhookPayload($payment, false)['operation'];
        $this->assertSame(['N', FakeBancard::REJECTED_RESPONSE_CODE], [$rejected['response'], $rejected['response_code']]);
        $this->assertSame([], $fake->monitoringPayload());

        // An attempt created in staging keeps its staging key after the site moves to production.
        $bancard->update(['environment' => 'production']);
        $this->assertSame($approved['token'], $fake->webhookPayload($payment->fresh())['operation']['token']);
        $production = CheckoutFixtures::payment($order->fresh(), $bancard->fresh());
        $this->assertSame('production', $production->environment);
        $this->assertSame(
            md5(FakeBancard::PRODUCTION_PRIVATE_KEY.$production->shop_process_id.'confirm150000.00PYG'),
            $fake->webhookPayload($production)['operation']['token'],
        );

        $event = PaymentEvent::create(['payment_id' => $payment->id, 'provider' => 'bancard', 'type' => PaymentEvent::TYPE_INVALID_TOKEN, 'token_valid' => false, 'payload_json' => ['operation' => ['shop_process_id' => 7]]]);
        $this->assertNotNull($event->created_at);
        $this->assertTrue($event->is($payment->events()->first()));
        $this->assertTrue($event->is(PaymentEvent::query()->latestFor('bancard')->first()));
        $this->assertSame(1, Payment::query()->pendingSince('bancard', now()->addMinute())->where('id', $payment->id)->count());
    }

    public function test_use_checkout_and_the_fake_provider_leave_a_ready_shop(): void
    {
        $zone = $this->useCheckout();

        $this->assertTrue(app(ShopModule::class)->checkout());
        $this->assertTrue(Setting::get('shop_pickup_enabled'));
        $this->assertSame('pedidos@example.com', Setting::get('shop_orders_email'));
        $this->assertSame(['Asunción'], ShippingZone::query()->enabled()->pluck('name')->all());
        $this->assertSame(25000, $zone->price_amount->amount);

        $fake = $this->useFakePaymentProvider();
        $registry = app(PaymentProviderRegistry::class);
        $this->assertSame(['bank_transfer', 'cod', 'fake'], $registry->available()->pluck('key')->all());
        $this->assertInstanceOf(PaymentProvider::class, $registry->get('fake'));
        $this->assertSame($fake, $registry->get('fake'));

        $order = CheckoutFixtures::order([], ['method' => 'fake']);
        $start = $registry->for($order->paymentMethod)->start($order->latestPayment, new PaymentUrls('https://tienda.example/pedido/x'));
        $this->assertTrue($start->isIframe());
        $this->assertSame(FakePaymentProvider::SCRIPT_URL, $start->scriptUrl);
        $this->assertSame('fake-'.$order->latestPayment->id, $order->latestPayment->fresh()->process_id);
        $notification = $fake->notificationFor($order->latestPayment);
        $this->assertTrue($fake->verify($notification, $order->latestPayment));
        $this->assertTrue($notification->matchesAmount($order->latestPayment->amount));

        $fake->missing = ['api_key'];
        $this->assertSame(['bank_transfer', 'cod'], $registry->available()->pluck('key')->all());
        $this->assertSame(['start', 'verify'], $fake->calledMethods());
    }

    public function test_checkout_events_carry_their_models(): void
    {
        Event::fake();
        $order = CheckoutFixtures::order();
        $payment = $order->latestPayment;
        $shortfall = [['product_id' => 1, 'variant_id' => null, 'title' => 'Mate', 'requested' => 2, 'available' => 1]];

        OrderPlaced::dispatch($order);
        OrderPaid::dispatch($order, $payment);
        OrderCancelled::dispatch($order, OrderCancelled::REASON_UNPAID);
        PaymentFailed::dispatch($payment, PaymentFailed::REASON_AMOUNT_MISMATCH);
        StockShortfall::dispatch($order, $shortfall);

        Event::assertDispatched(OrderPlaced::class, fn (OrderPlaced $event) => $event->order->is($order));
        Event::assertDispatched(OrderPaid::class, fn (OrderPaid $event) => $event->payment->is($payment));
        Event::assertDispatched(OrderCancelled::class, fn (OrderCancelled $event) => $event->reason === 'unpaid');
        Event::assertDispatched(PaymentFailed::class, fn (PaymentFailed $event) => $event->reason === 'amount_mismatch');
        Event::assertDispatched(StockShortfall::class, fn (StockShortfall $event) => $event->shortfall === $shortfall);
        $this->assertSame('admin', (new OrderCancelled($order))->reason);
        $this->assertSame('rejected', (new PaymentFailed($payment))->reason);
    }

    public function test_checkout_configuration(): void
    {
        $this->assertSame(['cart_days' => 30, 'unpaid_order_hours' => 48, 'bancard_pending_minutes' => 10, 'order_prefix' => 'ORD'], config('cms.shop.checkout'));
        $this->assertSame(['manual' => ManualPaymentProvider::class, 'bancard' => BancardProvider::class], config('cms.shop.payment_providers'));
        $this->assertSame(['staging' => self::STAGING, 'production' => self::PRODUCTION], config('cms.shop.bancard.urls'));
        $this->assertSame('5.0.1', config('cms.shop.bancard.checkout_js_version'));
        $this->assertContains('webhooks/*', config('cms.redirects.ignore_paths'));
        $this->assertSame(PaymentMethod::ENVIRONMENTS, ['staging', 'production']);
    }

    public function test_readiness_blocks_checkout_until_every_requirement_is_met(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->useShop('catalog');
        $readiness = app(CheckoutReadiness::class);
        $operations = app(ShopModuleOperations::class);

        // Sending email is not a requirement: a shop sells without it (docs/shop/checkout.md#sin-email).
        $this->assertSame(['payment_method', 'delivery', 'orders_email'], array_map(fn (ReadinessItem $item) => $item->key, $readiness->missing()));
        $this->assertSame(['payment_method' => false, 'delivery' => false, 'orders_email' => false], collect($readiness->items())->mapWithKeys(fn (ReadinessItem $item) => [$item->key => $item->met])->all());
        $this->assertSame(route('admin.shop-payments.edit'), $readiness->missing()[0]->url());
        $this->assertSame(__('checkout.readiness_payment_method'), $readiness->missing()[0]->toArray()['label']);
        $choices = collect($operations->choices())->keyBy('mode');
        $this->assertTrue($choices['checkout']['available']);
        $this->assertCount(3, $choices['checkout']['missing']);
        $this->assertSame([], $choices['catalog']['missing']);
        $this->assertBlocked($admin, [__('checkout.readiness_payment_method'), __('checkout.readiness_delivery'), __('checkout.readiness_orders_email')]);

        // Payment: an enabled method counts only once it is complete, and never without a registered provider.
        PaymentMethod::query()->where('key', 'cod')->update(['enabled' => true]);
        PaymentMethod::query()->where('key', 'bancard')->update(['enabled' => true, 'secrets' => null]);
        $this->assertFalse($readiness->items()[0]->met);
        CheckoutFixtures::enableManualMethods();
        $this->assertTrue($readiness->items()[0]->met);

        // Delivery: pickup needs its address; otherwise an enabled zone.
        $this->setSetting('shop_pickup_enabled', '1');
        $this->assertFalse($readiness->items()[1]->met);
        $this->setSetting('shop_pickup_address', 'Av. España 123');
        $this->assertTrue($readiness->items()[1]->met);
        $this->setSetting('shop_pickup_enabled', '0');
        CheckoutFixtures::shippingZone('Interior', 40000, false);
        $this->assertFalse($readiness->items()[1]->met);
        CheckoutFixtures::shippingZone('Asunción');
        $this->assertTrue($readiness->items()[1]->met);

        // Orders email: its own setting, else the contact email; an invalid address does not count.
        $this->setSetting('contact_email', 'hola@example.com');
        $this->assertSame('hola@example.com', $readiness->ordersEmail());
        $this->setSetting('shop_orders_email', 'no es un email');
        $this->assertNull($readiness->ordersEmail());
        $this->assertBlocked($admin, [__('checkout.readiness_orders_email')]);
        $this->setSetting('shop_orders_email', 'pedidos@example.com');
        $this->assertTrue($readiness->isReady());
        $this->assertSame([], collect($operations->choices())->keyBy('mode')['checkout']['missing']);

        $operations->setMode($admin, 'checkout');
        $this->assertTrue(app(ShopModule::class)->checkout());

        // Leaving checkout: open orders (pending, or paid and undelivered) are counted for the confirmation.
        CheckoutFixtures::order();
        CheckoutFixtures::order()->forceFill(['status' => Order::STATUS_CANCELLED])->save();
        $this->assertSame(1, $operations->openOrderCount());
        $operations->setMode($admin, 'catalog');
        $this->assertSame('catalog', app(ShopModule::class)->mode());
    }

    public function test_mcp_settings_update_gets_the_same_missing_requirements(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->useShop('catalog');

        try {
            app(SettingOperations::class)->update($admin, [ShopModule::SETTING => 'checkout']);
            $this->fail('checkout was stored with requirements missing.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(__('checkout.readiness_delivery'), $exception->errors()['mode'][0]);
        }
        $this->assertSame('catalog', app(ShopModule::class)->mode());

        $this->useCheckout();
        $this->useShop('catalog');
        app(SettingOperations::class)->update($admin, [ShopModule::SETTING => 'checkout']);
        $this->assertSame('checkout', app(ShopModule::class)->mode());
    }

    public function test_checkout_routes_are_registered_with_their_names(): void
    {
        $token = str_repeat('a', Order::ACCESS_TOKEN_LENGTH);
        $this->assertTrue(app(ShopModule::class)->checkoutAvailable());
        $this->assertSame(['carrito', 'checkout', 'pedido'], ShopModule::RESERVED_SLUGS);

        foreach (['shop.', 'localized.shop.'] as $prefix) {
            foreach (['cart.index', 'cart.add', 'cart.update', 'cart.remove', 'checkout.show', 'checkout.store', 'order.show', 'order.pay'] as $name) {
                $this->assertTrue(Route::has($prefix.$name), $prefix.$name);
            }
        }
        $this->assertSame(url('/productos/carrito'), route('shop.cart.index'));
        $this->assertSame(url('/productos/carrito/5'), route('shop.cart.update', 5));
        $this->assertSame(url('/en/productos/checkout'), route('localized.shop.checkout.show', ['locale' => 'en']));
        $this->assertSame(url('/productos/pedido/'.$token), route('shop.order.show', $token));
        $this->assertSame(url('/productos/pedido/'.$token.'/pagar'), route('shop.order.pay', $token));
        $this->assertSame(url('/webhooks/bancard'), route('webhooks.bancard'));
        $this->assertFalse(Route::has('localized.webhooks.bancard'));

        foreach (['orders.index', 'orders.show', 'orders.transition', 'orders.note', 'orders.resend', 'payments.confirm', 'payments.fail', 'payments.refund', 'payments.refund-manual',
            'shop-payments.edit', 'shop-payments.update', 'shop-payments.bancard', 'shop-payments.bancard-test', 'shop-payments.environment',
            'shipping-zones.store', 'shipping-zones.update', 'shipping-zones.destroy'] as $name) {
            $this->assertTrue(Route::has('admin.'.$name), 'admin.'.$name);
        }
        $this->assertSame(url('/admin/shop/payments'), route('admin.shop-payments.edit'));
        foreach (['orders.list', 'orders.get', 'orders.transition', 'orders.note', 'payments.confirm', 'payments.fail', 'payments.refund',
            'payment_methods.list', 'payment_methods.update', 'shipping_zones.list', 'shipping_zones.update'] as $name) {
            $this->assertTrue(Route::has('mcp.'.$name), 'mcp.'.$name);
        }
        $this->assertSame(url('/mcp/orders/7'), route('mcp.orders.get', 7));

        // Middleware per route: cart and checkout need `checkout`; the order page only the catalog; the webhook neither.
        $middleware = fn (string $name): array => Route::getRoutes()->getByName($name)->gatherMiddleware();
        foreach (['shop.cart.index', 'shop.cart.add', 'shop.checkout.store', 'localized.shop.cart.remove'] as $name) {
            $this->assertContains(EnsureShopCatalog::class.':page', $middleware($name));
            $this->assertContains(EnsureShopCheckout::class, $middleware($name));
        }
        $this->assertContains('throttle:cart', $middleware('shop.cart.add'));
        $this->assertContains('throttle:forms', $middleware('shop.checkout.store'));
        $this->assertContains('throttle:forms', $middleware('shop.order.pay'));
        $this->assertContains(EnsureShopCatalog::class.':page', $middleware('shop.order.show'));
        $this->assertNotContains(EnsureShopCheckout::class, $middleware('shop.order.show'));
        $this->assertContains(EnsureShopCatalog::class, $middleware('admin.orders.index'));
        $this->assertContains('role:admin', $middleware('admin.shop-payments.edit'));
        $this->assertNotContains('role:admin', $middleware('admin.orders.transition'));
        $this->assertContains(EnsureShopCatalog::class, $middleware('mcp.orders.list'));
        $this->assertContains('throttle:webhooks', $middleware('webhooks.bancard'));
        $webhook = app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName('webhooks.bancard'));
        foreach ([EnsureShopCatalog::class, EnsureShopCheckout::class, StartSession::class, PreventRequestForgery::class, ApplyRedirects::class, SetPublicLocale::class] as $absent) {
            $this->assertNotContains($absent, $webhook);
        }
    }

    public function test_cart_and_checkout_answer_only_in_checkout_mode(): void
    {
        // Stub actions: this test is about the middleware stack, not the controllers (track 3A).
        foreach (['shop.cart.index' => 'cart', 'shop.order.show' => 'order', 'localized.shop.checkout.show' => 'checkout'] as $name => $body) {
            Route::getRoutes()->getByName($name)->uses(fn () => $body);
        }
        Locale::updateOrCreate(['code' => 'es'], ['name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
        Locale::updateOrCreate(['code' => 'en'], ['name' => 'English', 'is_base' => false, 'is_active' => true, 'is_public' => true, 'sort_order' => 1]);
        $order = '/productos/pedido/'.str_repeat('a', Order::ACCESS_TOKEN_LENGTH);

        // Off: the shop prefix belongs to pages again.
        $this->useShop('off');
        $this->get('/productos/carrito')->assertNotFound();
        Page::create(['title' => 'Carrito de mate', 'slug' => 'productos/carrito', 'locale' => 'es', 'template_key' => 'default', 'content_json' => ['sections' => ['content' => ['heading' => 'Carrito de mate']]], 'status' => 'published', 'published_at' => now()]);
        $this->get('/productos/carrito')->assertOk()->assertSee('Carrito de mate');
        $this->get($order)->assertNotFound();

        // Catalog: cart and checkout do not exist; the order page of an earlier order still answers.
        $this->useShop('catalog');
        $this->get('/productos/carrito')->assertNotFound();
        $this->get('/en/productos/checkout')->assertNotFound();
        $this->get($order)->assertOk()->assertSee('order');

        $this->useShop('checkout');
        $this->get('/productos/carrito')->assertOk()->assertSee('cart');
        $this->get('/en/productos/checkout')->assertOk()->assertSee('checkout');

        $this->assertSame(404, $this->runEnsureShopCheckout('catalog'));
        $this->assertSame(200, $this->runEnsureShopCheckout('checkout'));
    }

    public function test_only_webhooks_skip_csrf_and_they_answer_in_any_mode(): void
    {
        Route::getRoutes()->getByName('webhooks.bancard')->uses(fn () => response()->json(['status' => 'success']));
        // The framework skips CSRF while running tests; this instance checks it as production does.
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $csrf = $this->app->make(PreventRequestForgery::class);
        $this->assertContains('webhooks/*', $csrf->getExcludedPaths());
        $this->assertNotContains('forms/*', $csrf->getExcludedPaths());

        $this->post('/forms/contact', ['name' => 'Ana'])->assertStatus(419);
        $this->useShop('off');
        $response = $this->postJson('/webhooks/bancard', [])->assertOk()->assertExactJson(['status' => 'success']);
        $this->assertEmpty($response->headers->getCookies());
        $this->useShop('checkout');
        $this->postJson('/webhooks/bancard', ['operation' => []])->assertOk();
    }

    public function test_sidebar_links_orders_and_payments_by_mode_and_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $editor = User::factory()->create(['role' => 'editor']);
        $orders = 'href="'.route('admin.orders.index').'"';
        $payments = 'href="'.route('admin.shop-payments.edit').'"';

        $this->useShop('off');
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertDontSee($orders, false)->assertDontSee($payments, false);
        $this->useShop('catalog');
        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee($orders, false)->assertSee($payments, false)->assertSee(__('checkout.payments_and_shipping'));
        CheckoutFixtures::order();
        $this->get(route('admin.dashboard'))->assertOk()->assertSee($orders, false)->assertSee(__('checkout.orders'));
        $this->useShop('checkout');
        $this->actingAs($editor)->get(route('admin.dashboard'))->assertOk()->assertSee($orders, false)->assertDontSee($payments, false);
    }

    public function test_products_cannot_use_checkout_slugs(): void
    {
        $this->useShop('catalog');
        foreach (ShopModule::RESERVED_SLUGS as $slug) {
            try {
                (new ProductInput(['title' => 'Carrito', 'slug' => $slug, 'locale' => 'es', 'status' => 'draft']))->validate();
                $this->fail("Slug {$slug} was accepted.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('slug', $exception->errors());
            }
        }
    }

    public function test_snapshots_leave_out_carts_and_payment_events_and_keep_orders(): void
    {
        Storage::fake('public');
        $order = CheckoutFixtures::order();
        $product = CheckoutFixtures::product();
        CheckoutFixtures::cart([[$product, 2]]);
        PaymentEvent::create(['payment_id' => $order->latestPayment->id, 'provider' => 'bancard', 'type' => 'notification', 'token_valid' => true, 'payload_json' => ['operation' => []]]);

        $archive = app(SnapshotService::class)->create('checkout-tables');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archive));
        try {
            $manifest = json_decode($zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
            $exported = array_column($manifest['database']['tables'], 'name');
            foreach (['carts', 'cart_items', 'payment_events'] as $table) {
                $this->assertContains($table, $manifest['database']['excluded_tables']);
                $this->assertNotContains($table, $exported);
                $this->assertFalse($zip->getFromName('database/'.$table.'.jsonl'));
            }
            foreach (['orders', 'order_items', 'payments', 'payment_methods', 'shipping_zones'] as $table) {
                $this->assertContains($table, $exported);
            }
        } finally {
            $zip->close();
        }

        // Restoring drops this site's carts and provider events: they would point at the archive's ids.
        CheckoutFixtures::cart([[$product, 1]]);
        app(SnapshotService::class)->restore($archive);
        $this->assertDatabaseCount('carts', 0);
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertDatabaseCount('payment_events', 0);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'number' => $order->number]);
    }

    public function test_scheduler_runs_checkout_maintenance(): void
    {
        $events = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command ?? '');
        $this->assertTrue($events->contains(fn (string $command) => str_contains($command, 'shop:prune-carts')));
        $this->assertSame(
            class_exists(ShopExpirePaymentsCommand::class),
            $events->contains(fn (string $command) => str_contains($command, 'shop:expire-payments')),
        );

        $expired = CheckoutFixtures::cart([[CheckoutFixtures::product(), 1]]);
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();
        $live = CheckoutFixtures::cart([[CheckoutFixtures::product(), 1]]);
        $this->artisan('shop:prune-carts')->assertSuccessful();
        $this->assertDatabaseMissing('carts', ['id' => $expired->id]);
        $this->assertDatabaseMissing('cart_items', ['cart_id' => $expired->id]);
        $this->assertDatabaseHas('carts', ['id' => $live->id]);
    }

    public function test_checkout_language_files_have_the_same_keys(): void
    {
        $es = require lang_path('es/checkout.php');
        $en = require lang_path('en/checkout.php');
        $this->assertSame(array_keys($es), array_keys($en));
        $this->assertSame(array_keys($es['bancard_errors']), array_keys($en['bancard_errors']));
        foreach ([CheckoutReadiness::PAYMENT_METHOD, CheckoutReadiness::DELIVERY, CheckoutReadiness::ORDERS_EMAIL] as $key) {
            $this->assertArrayHasKey('readiness_'.$key, $es);
        }
    }

    /** @param list<string> $labels */
    private function assertBlocked(User $admin, array $labels): void
    {
        try {
            app(ShopModuleOperations::class)->setMode($admin, 'checkout');
            $this->fail('checkout was stored with requirements missing.');
        } catch (ValidationException $exception) {
            $this->assertSame([__('checkout.readiness_blocked', ['missing' => implode(', ', $labels)])], $exception->errors()['mode']);
        }
        $this->assertNotSame('checkout', app(ShopModule::class)->stored());
    }

    private function setSetting(string $key, ?string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value] + SettingDefaults::for($key));
        Setting::clearCache();
    }

    private function runEnsureShopCheckout(string $mode): int
    {
        $this->useShop($mode);
        try {
            return app(EnsureShopCheckout::class)->handle(Request::create('/productos/carrito'), fn () => response('ok'))->getStatusCode();
        } catch (HttpException $exception) {
            return $exception->getStatusCode();
        }
    }
}
