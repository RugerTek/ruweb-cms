<?php

namespace Tests\Feature;

use App\Http\Controllers\McpServerController;
use App\Http\Middleware\EnforceMcpScopes;
use App\Models\McpApiKey;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\ShippingZone;
use App\Models\User;
use App\Shop\Payments\CancelResult;
use App\Shop\Payments\ManualPaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Support\CheckoutFixtures;
use Tests\Support\FakePaymentProvider;
use Tests\TestCase;

/** orders_*, payments_*, payment_methods_* and shipping_zones_* MCP tools and REST mirror (PLAN.md E3.6, track 3F). */
class McpOrdersTest extends TestCase
{
    use RefreshDatabase;

    private const ORDER_TOOLS = ['orders_list', 'orders_get', 'orders_transition', 'orders_note', 'payments_confirm', 'payments_fail', 'payments_refund'];

    private const SETTING_TOOLS = ['payment_methods_list', 'payment_methods_update', 'shipping_zones_list', 'shipping_zones_update'];

    private const PUBLIC_KEY = 'PubKeyAbcdefghij0123456789KLMNOP';

    private const PRIVATE_KEY = 'PrivateKeySecretValue-0123456789-abcdefXYZ';

    private User $admin;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->useCheckout();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->editor = User::factory()->create(['role' => 'editor']);
        $this->as($this->admin);
    }

    private function as(User $user, string $scope = 'full'): void
    {
        $this->withToken(McpApiKey::issue($user, 'Orders '.$scope.' '.$user->id, $scope, null));
    }

    private function rpc(string $tool, array $args = []): TestResponse
    {
        return $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args]]);
    }

    private function rpcData(TestResponse $response): array
    {
        $response->assertOk()->assertJsonMissingPath('error');
        $this->assertFalse($response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function toolNames(): array
    {
        return array_column($this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk()->json('result.tools'), 'name');
    }

    private function revision(Order $order): string
    {
        return $this->rpcData($this->rpc('orders_get', ['order_id' => $order->id]))['data']['revision'];
    }

    private function methodRevision(string $key): string
    {
        $methods = $this->rpcData($this->rpc('payment_methods_list'))['data'];

        return collect($methods)->firstWhere('key', $key)['revision'];
    }

    public function test_tools_follow_the_panel_and_are_absent_with_the_shop_off(): void
    {
        $this->useShop('off');
        $this->assertSame([], array_values(array_intersect($this->toolNames(), [...self::ORDER_TOOLS, ...self::SETTING_TOOLS])));
        $this->rpc('orders_list')->assertOk()->assertJsonPath('error.code', -32601);
        $this->rpc('payment_methods_list')->assertOk()->assertJsonPath('error.code', -32601);
        $this->getJson('/mcp/orders')->assertNotFound();
        $this->getJson('/mcp/payment-methods')->assertNotFound();

        // Catalog without orders: payment methods and shipping can be prepared; there are no orders to manage.
        $this->useShop('catalog');
        $this->assertSame(self::SETTING_TOOLS, array_values(array_intersect($this->toolNames(), [...self::ORDER_TOOLS, ...self::SETTING_TOOLS])));
        $this->rpc('orders_list')->assertOk()->assertJsonPath('error.code', -32601);
        $this->getJson('/mcp/orders')->assertNotFound();
        $this->getJson('/mcp/shipping-zones')->assertOk();

        // Catalog with orders placed while selling: they stay manageable, like the Orders sidebar entry.
        CheckoutFixtures::order();
        $this->assertSame([...self::ORDER_TOOLS, ...self::SETTING_TOOLS], array_values(array_intersect($this->toolNames(), [...self::ORDER_TOOLS, ...self::SETTING_TOOLS])));
        $this->getJson('/mcp/orders')->assertOk()->assertJsonPath('meta.total', 1);

        $this->useShop('checkout');
        $tools = $this->toolNames();
        $this->assertSame([...self::ORDER_TOOLS, ...self::SETTING_TOOLS], array_values(array_intersect($tools, [...self::ORDER_TOOLS, ...self::SETTING_TOOLS])));
        $resolve = new \ReflectionMethod(McpServerController::class, 'resolveHandler');
        $this->assertSame([], array_values(array_filter([...self::ORDER_TOOLS, ...self::SETTING_TOOLS], fn ($tool) => $resolve->invoke(app(McpServerController::class), $tool) === null)));
        $this->assertSame(['orders_list', 'orders_get', 'payment_methods_list', 'shipping_zones_list'], array_values(array_intersect([...self::ORDER_TOOLS, ...self::SETTING_TOOLS], EnforceMcpScopes::reads())));
        $doc = (string) file_get_contents(base_path('docs/operations/mcp.md'));
        $this->assertSame([], array_values(array_filter([...self::ORDER_TOOLS, ...self::SETTING_TOOLS], fn ($tool) => ! str_contains($doc, '`'.$tool.'`'))));
    }

    public function test_editors_see_orders_note_and_fulfil_but_only_administrators_move_money_or_configure(): void
    {
        $order = CheckoutFixtures::order();
        $payment = $order->payments->first();
        $this->as($this->editor);

        $list = $this->rpcData($this->rpc('orders_list', ['status' => 'pending']));
        $this->assertSame([$order->number], array_column($list['data'], 'number'));
        $data = $this->rpcData($this->rpc('orders_get', ['order_id' => $order->id]))['data'];
        $this->assertSame(['pending', ['paid', 'cancelled'], 'bank_transfer', 'pending'], [$data['status'], $data['available_transitions'], $data['payment_method'], $data['payments'][0]['status']]);

        $noted = $this->rpcData($this->rpc('orders_note', ['order_id' => $order->id, 'expected_revision' => $data['revision'], 'internal_notes' => 'Llamar antes de entregar']))['data'];
        $this->assertSame('Llamar antes de entregar', $noted['internal_notes']);

        foreach ([
            ['orders_transition', ['order_id' => $order->id, 'expected_revision' => $noted['revision'], 'status' => 'cancelled']],
            ['orders_transition', ['order_id' => $order->id, 'expected_revision' => $noted['revision'], 'status' => 'paid']],
            ['payments_confirm', ['payment_id' => $payment->id, 'expected_revision' => $noted['revision']]],
            ['payments_fail', ['payment_id' => $payment->id, 'expected_revision' => $noted['revision']]],
            ['payment_methods_list', []],
            ['shipping_zones_list', []],
            ['shipping_zones_update', ['expected_revision' => str_repeat('a', 64), 'pickup_enabled' => false]],
        ] as [$tool, $args]) {
            $this->rpc($tool, $args)->assertOk()->assertJsonPath('error.code', McpServerController::FORBIDDEN);
        }
        $this->getJson('/mcp/payment-methods')->assertForbidden();
        $this->assertSame(['pending', 'pending'], [$order->fresh()->status, $payment->fresh()->status]);

        // The administrator confirms the transfer; then the editor marks the order delivered.
        $this->as($this->admin);
        $paid = $this->rpcData($this->rpc('payments_confirm', ['payment_id' => $payment->id, 'expected_revision' => $noted['revision']]))['data'];
        $this->assertSame(['paid', 'confirmed'], [$paid['status'], $paid['payments'][0]['status']]);
        $this->as($this->editor);
        $fulfilled = $this->rpcData($this->rpc('orders_transition', ['order_id' => $order->id, 'expected_revision' => $paid['revision'], 'status' => 'fulfilled']))['data'];
        $this->assertSame('fulfilled', $fulfilled['status']);
        $this->assertNotNull($order->fresh()->fulfilled_at);
    }

    public function test_writes_need_the_current_revision_and_report_invalid_moves(): void
    {
        $order = CheckoutFixtures::order();
        $payment = $order->payments->first();
        $stale = $this->revision($order);
        $this->rpcData($this->rpc('orders_note', ['order_id' => $order->id, 'expected_revision' => $stale, 'internal_notes' => 'Primera']));

        $this->rpc('payments_confirm', ['payment_id' => $payment->id, 'expected_revision' => $stale])->assertOk()->assertJsonPath('error.code', -32009);
        $this->rpc('orders_note', ['order_id' => $order->id, 'expected_revision' => $stale, 'internal_notes' => 'Pisada'])->assertOk()->assertJsonPath('error.code', -32009);
        $this->assertSame(['pending', 'Primera'], [$payment->fresh()->status, $order->fresh()->internal_notes]);

        $current = $this->revision($order);
        $this->rpc('orders_transition', ['order_id' => $order->id, 'expected_revision' => $current, 'status' => 'fulfilled'])->assertOk()->assertJsonPath('error.code', -32602);
        $this->rpc('orders_transition', ['order_id' => $order->id, 'expected_revision' => $current, 'status' => 'pending'])->assertOk()->assertJsonPath('error.code', -32602);
        $this->rpc('orders_get', ['order_id' => 999999])->assertOk()->assertJsonPath('error.code', -32602);

        // Online attempts are settled by their provider, never by hand.
        $this->useFakePaymentProvider();
        $online = CheckoutFixtures::order([], ['method' => FakePaymentProvider::KEY]);
        $this->rpc('payments_confirm', ['payment_id' => $online->payments->first()->id, 'expected_revision' => $this->revision($online)])
            ->assertOk()->assertJsonPath('error.code', -32602);
        $this->assertSame(Payment::STATUS_PENDING, $online->payments->first()->fresh()->status);

        // Cancelling restores nothing for a pending order and expires its attempt; failing keeps the order pending.
        $failed = $this->rpcData($this->rpc('payments_fail', ['payment_id' => $payment->id, 'expected_revision' => $this->revision($order)]))['data'];
        $this->assertSame(['pending', 'failed'], [$failed['status'], $failed['payments'][0]['status']]);
        $cancelled = $this->rpcData($this->rpc('orders_transition', ['order_id' => $order->id, 'expected_revision' => $failed['revision'], 'status' => 'cancelled']))['data'];
        $this->assertSame('cancelled', $cancelled['status']);
    }

    public function test_read_only_and_draft_only_credentials_read_but_never_write(): void
    {
        $order = CheckoutFixtures::order();
        $revision = $this->revision($order);
        $zones = ShippingZone::query()->count();

        foreach (['read-only', 'draft-only'] as $scope) {
            $this->as($this->admin, $scope);
            $this->rpcData($this->rpc('orders_list'));
            $this->rpcData($this->rpc('orders_get', ['order_id' => $order->id]));
            $this->rpcData($this->rpc('payment_methods_list'));
            $this->rpcData($this->rpc('shipping_zones_list'));
            $this->getJson('/mcp/orders/'.$order->id)->assertOk();

            foreach ([
                ['orders_transition', ['order_id' => $order->id, 'expected_revision' => $revision, 'status' => 'cancelled']],
                ['orders_note', ['order_id' => $order->id, 'expected_revision' => $revision, 'internal_notes' => 'No']],
                ['payments_confirm', ['payment_id' => $order->payments->first()->id, 'expected_revision' => $revision]],
                ['payments_refund', ['payment_id' => $order->payments->first()->id, 'expected_revision' => $revision]],
                ['payment_methods_update', ['method' => 'cod', 'expected_revision' => str_repeat('a', 64), 'enabled' => false]],
                ['shipping_zones_update', ['expected_revision' => str_repeat('a', 64), 'zones' => []]],
            ] as [$tool, $args]) {
                $this->rpc($tool, $args)->assertOk()->assertJsonPath('error.code', McpServerController::FORBIDDEN)->assertJsonPath('error.data.scope', $scope);
            }
            $this->postJson('/mcp/orders/'.$order->id.'/transition', ['expected_revision' => $revision, 'status' => 'cancelled'])->assertForbidden();
            $this->postJson('/mcp/orders/'.$order->id.'/note', ['expected_revision' => $revision, 'internal_notes' => 'No'])->assertForbidden();
            $this->patchJson('/mcp/payment-methods/cod', ['expected_revision' => str_repeat('a', 64), 'enabled' => false])->assertForbidden();
            $this->patchJson('/mcp/shipping-zones', ['expected_revision' => str_repeat('a', 64), 'zones' => []])->assertForbidden();
        }

        $this->assertSame(['pending', null, true, $zones], [$order->fresh()->status, $order->fresh()->internal_notes, PaymentMethod::query()->where('key', 'cod')->value('enabled'), ShippingZone::query()->count()]);
    }

    public function test_no_response_ever_contains_payment_method_keys_or_order_tokens(): void
    {
        // A site that does not offer Bancard: its provider is not registered, so it cannot be enabled.
        config(['cms.shop.payment_providers' => ['manual' => ManualPaymentProvider::class]]);
        $order = CheckoutFixtures::order();
        $token = $order->plainAccessToken();
        $this->assertIsString($token);
        $bodies = [];

        $revision = $this->methodRevision('bancard');
        $saved = $this->rpc('payment_methods_update', ['method' => 'bancard', 'expected_revision' => $revision, 'credentials' => ['environment' => 'staging', 'public_key' => self::PUBLIC_KEY, 'private_key' => self::PRIVATE_KEY]]);
        $bodies[] = $saved->getContent();
        $data = $this->rpcData($saved)['data'];
        $this->assertSame([true, false], [$data['credentials']['staging']['configured'], $data['credentials']['production']['configured']]);
        $bancard = PaymentMethod::query()->where('key', 'bancard')->firstOrFail();
        $this->assertSame(['public_key' => self::PUBLIC_KEY, 'private_key' => self::PRIVATE_KEY], $bancard->credentials('staging'));
        $this->assertStringNotContainsString(self::PRIVATE_KEY, (string) $bancard->getAttributes()['secrets']);

        // A Bancard attempt whose stored payloads carry what Bancard sends (md5 token, security information).
        $bancardOrder = CheckoutFixtures::order([], ['method' => 'bancard']);
        $attempt = $bancardOrder->payments->first();
        $md5 = md5(self::PRIVATE_KEY.$attempt->id.'confirm');
        $attempt->forceFill(['process_id' => 'procABCDEFGHIJ123456', 'payload_json' => ['token' => $md5, 'security_information' => ['card_source' => 'L']]])->save();
        PaymentEvent::create(['payment_id' => $attempt->id, 'provider' => 'bancard', 'type' => PaymentEvent::TYPE_NOTIFICATION, 'token_valid' => true, 'payload_json' => ['token' => $md5, 'status_before' => 'pending']]);

        $orderRevision = $this->revision($order);
        foreach ([
            ['orders_list', []],
            ['orders_list', ['search' => $order->number]],
            ['orders_get', ['order_id' => $order->id]],
            ['orders_get', ['order_id' => $bancardOrder->id]],
            ['orders_note', ['order_id' => $order->id, 'expected_revision' => $orderRevision, 'internal_notes' => 'Nota']],
            ['payment_methods_list', []],
            ['shipping_zones_list', []],
        ] as [$tool, $args]) {
            $response = $this->rpc($tool, $args);
            $this->rpcData($response);
            $bodies[] = $response->getContent();
        }
        $failure = $this->rpc('payment_methods_update', ['method' => 'bancard', 'expected_revision' => $this->methodRevision('bancard'), 'enabled' => true, 'credentials' => ['environment' => 'production', 'public_key' => self::PUBLIC_KEY, 'private_key' => self::PRIVATE_KEY.'-prod']]);
        $failure->assertOk()->assertJsonPath('error.code', -32602);
        $bodies[] = $failure->getContent();
        $bodies[] = $this->rpc('payment_methods_update', ['method' => 'cod', 'expected_revision' => $this->methodRevision('cod'), 'credentials' => ['environment' => 'staging', 'public_key' => self::PUBLIC_KEY, 'private_key' => self::PRIVATE_KEY]])->getContent();
        $bodies[] = $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->getContent();
        foreach (['/mcp/orders', '/mcp/orders/'.$order->id, '/mcp/orders/'.$bancardOrder->id, '/mcp/payment-methods', '/mcp/shipping-zones'] as $path) {
            $bodies[] = $this->getJson($path)->assertOk()->getContent();
        }
        $bodies[] = $this->patchJson('/mcp/payment-methods/bancard', ['expected_revision' => $this->methodRevision('bancard'), 'credentials' => ['environment' => 'production', 'public_key' => self::PUBLIC_KEY, 'private_key' => self::PRIVATE_KEY]])->assertOk()->getContent();

        $all = implode("\n", $bodies);
        foreach ([self::PRIVATE_KEY, self::PUBLIC_KEY, $token, Order::hashAccessToken($token), $bancardOrder->plainAccessToken(), $md5, 'security_information', '/pedido/'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
            $this->assertStringNotContainsString(json_encode($secret), $all);
        }
        $this->assertNotNull(PaymentMethod::query()->where('key', 'bancard')->firstOrFail()->credentials('production'));
    }

    public function test_payment_methods_update_patches_public_settings_and_refuses_incomplete_activation(): void
    {
        // A site that does not offer Bancard: its provider is not registered, so it cannot be enabled.
        config(['cms.shop.payment_providers' => ['manual' => ManualPaymentProvider::class]]);
        $this->useShop('catalog');
        $revision = $this->methodRevision('bank_transfer');
        $response = $this->rpcData($this->rpc('payment_methods_update', ['method' => 'bank_transfer', 'expected_revision' => $revision, 'sort_order' => 7, 'settings' => ['bank_name' => 'Banco Nuevo', 'alias' => null, 'private_key' => 'x']]));
        $this->assertSame(['Banco Nuevo', 7], [$response['data']['settings']['bank_name'], $response['data']['sort_order']]);
        $this->assertArrayNotHasKey('alias', $response['data']['settings']);
        $this->assertSame(['unknown_setting', 'settings.private_key'], [$response['warnings'][0]['code'], $response['warnings'][0]['field']]);
        $this->assertArrayNotHasKey('private_key', PaymentMethod::query()->where('key', 'bank_transfer')->value('settings_json'));

        $this->rpc('payment_methods_update', ['method' => 'bank_transfer', 'expected_revision' => $revision, 'enabled' => false])->assertOk()->assertJsonPath('error.code', -32009);

        // Bancard without its provider registered (or keys) cannot be enabled; nothing is saved, keys included.
        $this->rpc('payment_methods_update', ['method' => 'bancard', 'expected_revision' => $this->methodRevision('bancard'), 'enabled' => true, 'sort_order' => 1, 'credentials' => ['environment' => 'staging', 'public_key' => self::PUBLIC_KEY, 'private_key' => self::PRIVATE_KEY]])
            ->assertOk()->assertJsonPath('error.code', -32602);
        $bancard = PaymentMethod::query()->where('key', 'bancard')->firstOrFail();
        $this->assertSame([false, 3, null], [$bancard->enabled, $bancard->sort_order, $bancard->credentials('staging')]);
        $this->rpc('payment_methods_update', ['method' => 'bancard', 'expected_revision' => $this->methodRevision('bancard'), 'credentials' => ['environment' => 'staging', 'public_key' => 'short', 'private_key' => 'x']])
            ->assertOk()->assertJsonPath('error.code', -32602);

        // An enabled method stays complete, like the panel: clearing a required field is refused with nothing saved.
        $this->rpc('payment_methods_update', ['method' => 'cod', 'expected_revision' => $this->methodRevision('cod'), 'settings' => ['instructions' => null]])
            ->assertOk()->assertJsonPath('error.code', -32602);
        $this->assertSame(CheckoutFixtures::COD_INSTRUCTIONS, PaymentMethod::query()->where('key', 'cod')->firstOrFail()->setting('instructions'));
        $cod = $this->rpcData($this->rpc('payment_methods_update', ['method' => 'cod', 'expected_revision' => $this->methodRevision('cod'), 'enabled' => false, 'settings' => ['instructions' => null]]));
        $this->assertSame([false, false, ['instructions']], [$cod['data']['enabled'], $cod['data']['offered'], $cod['data']['missing']]);
        $this->rpc('payment_methods_update', ['method' => 'nope', 'expected_revision' => str_repeat('a', 64), 'enabled' => true])->assertOk()->assertJsonPath('error.code', -32602);

        // While selling, a change that would leave the checkout without a payment method is refused.
        $this->useShop('checkout');
        $this->rpc('payment_methods_update', ['method' => 'bank_transfer', 'expected_revision' => $this->methodRevision('bank_transfer'), 'enabled' => false])
            ->assertOk()->assertJsonPath('error.code', -32602);
        $this->assertTrue(PaymentMethod::query()->where('key', 'bank_transfer')->value('enabled'));
    }

    public function test_shipping_zones_update_replaces_the_whole_list_and_store_pickup(): void
    {
        $kept = ShippingZone::query()->firstOrFail();
        $gone = CheckoutFixtures::shippingZone('Interior', 50000);
        $order = CheckoutFixtures::order([], ['zone' => $gone]);
        $list = $this->rpcData($this->rpc('shipping_zones_list'))['data'];
        $this->assertSame([true, 'Av. España 123, Asunción', 2], [$list['pickup_enabled'], $list['pickup_address'], count($list['zones'])]);

        $response = $this->rpcData($this->rpc('shipping_zones_update', ['expected_revision' => $list['revision'], 'zones' => [
            ['name' => 'Central', 'price_amount' => 30000],
            ['id' => $kept->id, 'name' => 'Asunción centro', 'price_amount' => 20000, 'enabled' => false],
        ], 'pickup_address' => 'Mcal. López 1000']));
        $this->assertSame(['Central', 'Asunción centro'], array_column($response['data']['zones'], 'name'));
        $this->assertSame([30000, 20000], array_column($response['data']['zones'], 'price_amount'));
        $this->assertSame([true, false], array_column($response['data']['zones'], 'enabled'));
        $this->assertSame('zone_deleted', $response['warnings'][0]['code']);
        $this->assertSame([null, 'Interior', 50000], [$order->fresh()->shipping_zone_id, $order->fresh()->shipping_zone_name, $order->fresh()->shipping_amount->amount]);
        $this->assertSame('Mcal. López 1000', Setting::get('shop_pickup_address'));

        $this->rpc('shipping_zones_update', ['expected_revision' => $list['revision'], 'zones' => []])->assertOk()->assertJsonPath('error.code', -32009);
        $this->rpc('shipping_zones_update', ['expected_revision' => $response['data']['revision'], 'pickup_enabled' => true, 'pickup_address' => ''])->assertOk()->assertJsonPath('error.code', -32602);
        $this->assertSame(2, ShippingZone::query()->count());

        // While selling, removing every delivery option is refused with nothing changed.
        $current = $this->rpcData($this->rpc('shipping_zones_list'))['data']['revision'];
        $this->rpc('shipping_zones_update', ['expected_revision' => $current, 'zones' => [], 'pickup_enabled' => false])->assertOk()->assertJsonPath('error.code', -32602);
        $this->assertSame([2, true], [ShippingZone::query()->count(), (bool) Setting::get('shop_pickup_enabled')]);
    }

    public function test_cancelling_an_online_payment_in_flight_asks_the_provider_first(): void
    {
        $fake = $this->useFakePaymentProvider();
        $paidThere = CheckoutFixtures::order([], ['method' => FakePaymentProvider::KEY]);
        $attempt = $paidThere->payments->first();
        $attempt->forceFill(['process_id' => 'fake-'.$attempt->id])->save();
        $fake->queryResult = $fake->notificationFor($attempt);

        $this->rpc('orders_transition', ['order_id' => $paidThere->id, 'expected_revision' => $this->revision($paidThere), 'status' => 'cancelled'])
            ->assertOk()->assertJsonPath('error.code', -32602);
        $this->assertSame([Order::STATUS_PAID, Payment::STATUS_CONFIRMED], [$paidThere->fresh()->status, $attempt->fresh()->status]);
        // The answer is verified before it is applied, and nothing is cancelled at the provider.
        $this->assertSame(['query', 'verify'], $fake->calledMethods());

        $fake->queryResult = null;
        $fake->calls = [];
        $notPaid = CheckoutFixtures::order([], ['method' => FakePaymentProvider::KEY]);
        $second = $notPaid->payments->first();
        $second->forceFill(['process_id' => 'fake-'.$second->id])->save();
        $fake->cancelResult = CancelResult::manual('already_settled');
        $this->rpc('orders_transition', ['order_id' => $notPaid->id, 'expected_revision' => $this->revision($notPaid), 'status' => 'cancelled'])
            ->assertOk()->assertJsonPath('error.code', -32602);
        $this->assertSame(Order::STATUS_PENDING, $notPaid->fresh()->status);

        $fake->cancelResult = CancelResult::success('fake_cancelled');
        $fake->calls = [];
        $data = $this->rpcData($this->rpc('orders_transition', ['order_id' => $notPaid->id, 'expected_revision' => $this->revision($notPaid), 'status' => 'cancelled']))['data'];
        $this->assertSame(['cancelled', 'expired'], [$data['status'], $data['payments'][0]['status']]);
        $this->assertSame(['query', 'cancel'], $fake->calledMethods());
    }

    public function test_refunds_go_through_the_provider_or_by_hand(): void
    {
        $product = CheckoutFixtures::product(['stock' => 5, 'track_stock' => true]);
        $order = CheckoutFixtures::order([[$product, 2]]);
        $payment = $order->payments->first();
        $paid = $this->rpcData($this->rpc('payments_confirm', ['payment_id' => $payment->id, 'expected_revision' => $this->revision($order)]))['data'];
        $this->assertSame(3, $product->fresh()->stock);

        // A manual method cannot reverse money by itself: the error says so and nothing changes.
        $this->rpc('payments_refund', ['payment_id' => $payment->id, 'expected_revision' => $paid['revision']])->assertOk()->assertJsonPath('error.code', -32602);
        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->fresh()->status);

        $this->as($this->editor);
        $this->rpc('payments_refund', ['payment_id' => $payment->id, 'expected_revision' => $paid['revision'], 'manual' => true])->assertOk()->assertJsonPath('error.code', McpServerController::FORBIDDEN);
        $this->as($this->admin);
        $refunded = $this->rpcData($this->rpc('payments_refund', ['payment_id' => $payment->id, 'expected_revision' => $paid['revision'], 'manual' => true]))['data'];
        $this->assertSame(['refunded', 'refunded'], [$refunded['status'], $refunded['payments'][0]['status']]);
        $this->assertSame(5, $product->fresh()->stock);
    }
}
