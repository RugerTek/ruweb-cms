<?php

namespace Tests\Feature;

use App\Connectors\Bancard\BancardConnector;
use App\Connectors\ConnectorRegistry;
use App\Connectors\ConnectorStatus;
use App\Http\Controllers\Admin\ShopPaymentsController;
use App\Models\Locale;
use App\Models\Order;
use App\Models\Page;
use App\Models\PaymentEvent;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\ShippingZone;
use App\Models\User;
use App\Services\Shop\CheckoutReadiness;
use App\Shop\Payments\Bancard\BancardProvider;
use App\Support\SettingDefaults;
use App\Support\ShopModule;
use App\Support\SiteUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\CheckoutFixtures;
use Tests\Support\FakeBancard;
use Tests\TestCase;

/**
 * Admin > Payments and shipping, Settings > Shop requirements and the Bancard connector card (PLAN.md E3.3, E3.4,
 * stage 3 track 3D2). Bancard only ever talks to FakeBancard.
 */
class AdminShopPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const PUBLIC_KEY = 'AbCdEfGh0123456789IjKlMnOpQr9f7Z';

    private const PRIVATE_KEY = 'very-secret-private-key-0123456789abcdef';

    private User $admin;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
        config([
            'cms.shop.payment_providers.bancard' => BancardProvider::class,
            'cms.connectors.bancard' => BancardConnector::class,
        ]);
        $this->useShop('catalog');
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->editor = User::factory()->create(['role' => 'editor']);
    }

    private function bancard(): PaymentMethod
    {
        return PaymentMethod::query()->where('key', 'bancard')->firstOrFail();
    }

    private function setting(string $key, ?string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value] + SettingDefaults::for($key));
        Setting::clearCache();
    }

    /** Every CheckoutReadiness requirement met with manual methods, pickup, orders email and mail on. */
    private function readyToSell(): void
    {
        CheckoutFixtures::enableManualMethods();
        $this->setting('shop_pickup_enabled', '1');
        $this->setting('shop_pickup_address', 'Av. España 123, Asunción');
        $this->setting('shop_orders_email', 'pedidos@example.com');
        $this->setting('mail_enabled', '1');
    }

    private function saveKeys(string $environment = 'staging', ?string $public = self::PUBLIC_KEY, ?string $private = self::PRIVATE_KEY): TestResponse
    {
        return $this->actingAs($this->admin)->put(route('admin.shop-payments.bancard'), [
            'environment' => $environment,
            'public_key' => $public,
            'private_key' => $private,
        ]);
    }

    public function test_only_administrators_reach_the_screen_and_it_needs_the_catalog(): void
    {
        $zone = CheckoutFixtures::shippingZone();
        $routes = [
            ['get', route('admin.shop-payments.edit')],
            ['put', route('admin.shop-payments.update')],
            ['put', route('admin.shop-payments.bancard')],
            ['post', route('admin.shop-payments.bancard-test')],
            ['put', route('admin.shop-payments.environment')],
            ['post', route('admin.shipping-zones.store')],
            ['put', route('admin.shipping-zones.update', ['zone' => $zone->id])],
            ['delete', route('admin.shipping-zones.destroy', ['zone' => $zone->id])],
        ];
        $this->actingAs($this->editor);
        foreach ($routes as [$method, $url]) {
            $this->{$method}($url)->assertForbidden();
        }
        $this->assertDatabaseHas('shipping_zones', ['id' => $zone->id]);

        $this->actingAs($this->admin)->get(route('admin.shop-payments.edit'))->assertOk()
            ->assertSee(__('checkout.payments_title'))
            ->assertSee(__('checkout.method_bank_transfer'))
            ->assertSee(__('checkout.guide_title'))
            ->assertSee('href="https://www.bancard.com.py/vpos"', false);

        $this->useShop('off');
        $this->get(route('admin.shop-payments.edit'))->assertNotFound();
    }

    public function test_keys_are_stored_encrypted_bound_to_the_site_and_never_shown(): void
    {
        $this->saveKeys()->assertRedirect(route('admin.shop-payments.edit').'#bancard')->assertSessionHas('success', __('checkout.bancard_keys_saved'));

        $raw = (string) DB::table('payment_methods')->where('key', 'bancard')->value('secrets');
        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString(self::PRIVATE_KEY, $raw);
        $this->assertStringNotContainsString(self::PUBLIC_KEY, $raw);
        $method = $this->bancard();
        $this->assertSame(['public_key' => self::PUBLIC_KEY, 'private_key' => self::PRIVATE_KEY], $method->credentials('staging'));
        $this->assertSame(SiteUrl::origin(), $method->bound_origin);
        $this->assertSame('staging', $method->environment);

        $html = $this->get(route('admin.shop-payments.edit'))->assertOk()
            ->assertSee(__('checkout.bancard_key_saved', ['last' => '9f7Z']))
            ->assertSee('data-bancard-status="staging"', false)
            ->getContent();
        $this->assertStringNotContainsString(self::PRIVATE_KEY, $html);
        $this->assertStringNotContainsString(self::PUBLIC_KEY, $html);
        // Not even what was typed comes back after a failed submit.
        $this->saveKeys('production', 'too-short', 'other-private-key-typed');
        $html = $this->get(route('admin.shop-payments.edit'))->assertSee(__('checkout.bancard_public_key_invalid'))->getContent();
        $this->assertStringNotContainsString('other-private-key-typed', $html);
    }

    public function test_an_empty_field_keeps_the_stored_key_and_clear_removes_the_pair(): void
    {
        $this->saveKeys();
        $this->saveKeys('staging', null, null)->assertSessionHasNoErrors();
        $this->assertSame(['public_key' => self::PUBLIC_KEY, 'private_key' => self::PRIVATE_KEY], $this->bancard()->credentials('staging'));
        $this->saveKeys('staging', 'NewPublicKey0123456789abcdefghij', '')->assertSessionHasNoErrors();
        $this->assertSame(['public_key' => 'NewPublicKey0123456789abcdefghij', 'private_key' => self::PRIVATE_KEY], $this->bancard()->credentials('staging'));
        $this->saveKeys('staging', '', 'replaced-private-key')->assertSessionHasNoErrors();
        $this->assertSame('replaced-private-key', $this->bancard()->credentials('staging')['private_key']);

        // Shape: 32 letters or digits; a first save needs both keys.
        $this->saveKeys('production', 'not a key', 'x')->assertSessionHasErrors(['public_key' => __('checkout.bancard_public_key_invalid')]);
        $this->saveKeys('production', self::PUBLIC_KEY, '')->assertSessionHasErrors(['private_key' => __('checkout.bancard_private_key_required')]);
        $this->assertNull($this->bancard()->credentials('production'));

        $this->actingAs($this->admin)->put(route('admin.shop-payments.bancard'), ['environment' => 'staging', 'clear' => '1'])
            ->assertSessionHas('success', __('checkout.bancard_keys_cleared'));
        $this->assertNull($this->bancard()->credentials('staging'));
        $this->assertNull(DB::table('payment_methods')->where('key', 'bancard')->value('secrets'));
        $this->get(route('admin.shop-payments.edit'))->assertSee('data-bancard-status="unconfigured"', false);
    }

    public function test_production_needs_its_keys_and_an_explicit_confirmation(): void
    {
        $this->saveKeys();
        $this->put(route('admin.shop-payments.environment'), ['environment' => 'production', 'confirm' => '1'])
            ->assertSessionHasErrors(['environment' => __('checkout.bancard_production_requires_keys')]);
        $this->get(route('admin.shop-payments.edit'))->assertSee(__('checkout.bancard_production_confirm'));

        $this->saveKeys('production', 'Prod0123456789abcdefghijklmnopqr', 'production-private');
        $this->put(route('admin.shop-payments.environment'), ['environment' => 'production'])->assertSessionHasErrors('confirm');
        $this->assertSame('staging', $this->bancard()->environment);

        $this->put(route('admin.shop-payments.environment'), ['environment' => 'production', 'confirm' => '1'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', __('checkout.bancard_environment_saved', ['environment' => __('checkout.environment_production')]));
        $this->assertSame('production', $this->bancard()->environment);

        // Attempts started in staging keep being validated with their keys: the screen says so.
        $order = CheckoutFixtures::order([], ['method' => 'bancard']);
        $order->payments()->update(['environment' => 'staging', 'status' => 'pending']);
        $this->get(route('admin.shop-payments.edit'))->assertOk()
            ->assertSee('data-bancard-status="production"', false)
            ->assertSee(trans_choice('checkout.bancard_pending_other_environment', 1, ['count' => 1, 'environment' => __('checkout.environment_staging')]));

        $this->put(route('admin.shop-payments.environment'), ['environment' => 'staging'])->assertSessionHasNoErrors();
        $this->assertSame('staging', $this->bancard()->environment);
    }

    public function test_connection_test_against_fake_bancard_accepts_and_rejects_keys(): void
    {
        $fake = $this->useFakeBancard();
        $this->actingAs($this->admin);

        $this->post(route('admin.shop-payments.bancard-test'), ['environment' => 'staging'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', __('checkout.bancard_test_ok'));
        $fake->assertCalled(FakeBancard::OPERATION_CONFIRMATIONS);
        $this->assertSame(ShopPaymentsController::TEST_SHOP_PROCESS_ID, (int) $fake->calls(FakeBancard::OPERATION_CONFIRMATIONS)[0]['body']['operation']['shop_process_id']);
        $event = PaymentEvent::query()->latestFor('bancard')->firstOrFail();
        $this->assertSame(PaymentEvent::TYPE_API_CALL, $event->type);
        $this->assertSame('accepted', $event->payload_json['result']);
        $this->assertStringNotContainsString(FakeBancard::STAGING_PRIVATE_KEY, json_encode($event->payload_json));
        $this->get(route('admin.shop-payments.edit'))->assertSee(__('checkout.bancard_test_ok'));

        $fake->scenario(FakeBancard::OPERATION_CONFIRMATIONS, FakeBancard::INVALID_TOKEN);
        $this->post(route('admin.shop-payments.bancard-test'), ['environment' => 'staging'])
            ->assertSessionHasErrors(['bancard' => __('checkout.bancard_test_rejected', ['reason' => __('checkout.bancard_errors.InvalidTokenError')])]);

        $fake->scenario(FakeBancard::OPERATION_CONFIRMATIONS, FakeBancard::TIMEOUT);
        $this->post(route('admin.shop-payments.bancard-test'), ['environment' => 'staging'])
            ->assertSessionHasErrors(['bancard' => __('checkout.bancard_test_failed', ['reason' => __('checkout.bancard_errors.timeout')])]);
        $this->assertSame(['accepted', 'rejected', 'failed'], PaymentEvent::query()->orderBy('id')->get()->map(fn ($e) => $e->payload_json['result'])->all());

        // Keys of an environment that has none: nothing is sent.
        $this->bancard()->forceFill(['secrets' => ['staging' => $fake->keys('staging')]])->save();
        $calls = count($fake->calls());
        $this->post(route('admin.shop-payments.bancard-test'), ['environment' => 'production'])->assertSessionHasErrors('bancard');
        $this->assertCount($calls, $fake->calls());
    }

    public function test_the_confirmation_url_is_copyable_and_warns_without_public_https(): void
    {
        config(['app.url' => 'http://localhost:8000']);
        $this->actingAs($this->admin)->get(route('admin.shop-payments.edit'))->assertOk()
            ->assertSee('value="http://localhost:8000/webhooks/bancard"', false)
            ->assertSee('data-copy-target="bancard-webhook-url"', false)
            ->assertSee('data-bancard-https-warning', false);

        config(['app.url' => 'https://tienda.example.com.py']);
        $this->get(route('admin.shop-payments.edit'))->assertOk()
            ->assertSee('value="https://tienda.example.com.py/webhooks/bancard"', false)
            ->assertDontSee('data-bancard-https-warning', false);
    }

    public function test_keys_saved_on_another_site_leave_bancard_inactive_until_loaded_again(): void
    {
        $fake = $this->useFakeBancard();
        $this->readyToSell();
        $this->assertTrue(app(BancardProvider::class)->isReady($this->bancard()));
        $this->bancard()->forceFill(['bound_origin' => 'https://copia-de-staging.example.com'])->save();

        $this->assertFalse(app(BancardProvider::class)->isReady($this->bancard()));
        $this->actingAs($this->admin)->get(route('admin.shop-payments.edit'))->assertOk()
            ->assertSee(__('checkout.bancard_reload_keys'))
            ->assertSee('data-bancard-status="unconfigured"', false)
            ->assertSee('data-bancard-keys="staging" data-configured="0"', false);
        $this->post(route('admin.shop-payments.bancard-test'), ['environment' => 'staging'])
            ->assertSessionHasErrors(['bancard' => __('checkout.bancard_reload_keys')]);
        $fake->assertNotCalled();

        // Loading one environment again binds the method to this site and drops the foreign pairs.
        $this->saveKeys('staging')->assertSessionHasNoErrors();
        $method = $this->bancard();
        $this->assertSame(SiteUrl::origin(), $method->bound_origin);
        $this->assertNull($method->credentials('production'));
        $this->assertTrue(app(BancardProvider::class)->isReady($method));
        $this->get(route('admin.shop-payments.edit'))->assertDontSee(__('checkout.bancard_reload_keys'));
    }

    public function test_manual_methods_pickup_and_orders_email_are_saved_and_an_incomplete_method_stays_off(): void
    {
        $page = Page::create(['title' => 'Políticas', 'slug' => 'politicas', 'locale' => 'es', 'status' => 'published', 'translation_group_id' => (string) Str::uuid()]);
        $this->actingAs($this->admin)->put(route('admin.shop-payments.update'), [
            'methods' => ['bank_transfer' => ['enabled' => '1', 'sort_order' => '1', 'settings' => ['bank_name' => 'Banco Ejemplo', 'account_holder' => '']]],
        ])->assertSessionHasErrors('methods.bank_transfer.enabled');
        $this->assertFalse(PaymentMethod::query()->where('key', 'bank_transfer')->value('enabled'));

        $this->put(route('admin.shop-payments.update'), [
            'methods' => [
                'bank_transfer' => ['enabled' => '1', 'sort_order' => '2', 'settings' => ['bank_name' => ' Banco Ejemplo ', 'account_holder' => 'Mi Tienda SA', 'account_number' => '123-456', 'alias' => '']],
                'cod' => ['enabled' => '0', 'sort_order' => '1', 'settings' => ['instructions' => 'Pagás al recibir.']],
                'bancard' => ['enabled' => '1'],
            ],
            'shop_pickup_enabled' => '1',
            'shop_pickup_address' => 'Av. España 123',
            'shop_orders_email' => 'pedidos@example.com',
            'shop_terms_page_id' => (string) $page->id,
        ])->assertSessionHasErrors(['methods.bancard.enabled' => __('checkout.method_incomplete', ['fields' => __('checkout.bancard_status_unconfigured')])]);

        $this->put(route('admin.shop-payments.update'), [
            'methods' => [
                'bank_transfer' => ['enabled' => '1', 'sort_order' => '2', 'settings' => ['bank_name' => ' Banco Ejemplo ', 'account_holder' => 'Mi Tienda SA', 'account_number' => '123-456', 'alias' => '']],
                'cod' => ['enabled' => '0', 'sort_order' => '1', 'settings' => ['instructions' => 'Pagás al recibir.']],
            ],
            'shop_pickup_enabled' => '1',
            'shop_pickup_address' => 'Av. España 123',
            'shop_orders_email' => 'pedidos@example.com',
            'shop_terms_page_id' => (string) $page->id,
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.shop-payments.edit'));

        $bank = PaymentMethod::query()->where('key', 'bank_transfer')->firstOrFail();
        $this->assertTrue($bank->enabled);
        $this->assertSame(2, $bank->sort_order);
        $this->assertSame(['bank_name' => 'Banco Ejemplo', 'account_holder' => 'Mi Tienda SA', 'account_number' => '123-456'], $bank->settings_json);
        $this->assertSame('Pagás al recibir.', PaymentMethod::query()->where('key', 'cod')->firstOrFail()->setting('instructions'));
        $this->assertTrue((bool) Setting::get('shop_pickup_enabled'));
        $this->assertSame('Av. España 123', Setting::get('shop_pickup_address'));
        $this->assertSame('pedidos@example.com', Setting::get('shop_orders_email'));
        $this->assertSame((string) $page->id, (string) Setting::get('shop_terms_page_id'));

        $this->get(route('admin.shop-payments.edit'))->assertOk()
            ->assertSee('value="Banco Ejemplo"', false)
            ->assertSee('data-readiness-item="payment_method" data-met="1"', false)
            ->assertSee('data-readiness-item="delivery" data-met="1"', false)
            ->assertSee('data-readiness-item="mail" data-met="0"', false)
            ->assertSee(route('admin.email.index'), false);
    }

    public function test_delivery_zones_are_created_edited_and_deleted(): void
    {
        $this->actingAs($this->admin);
        $this->post(route('admin.shipping-zones.store'), ['name' => 'Asunción', 'price' => '25.000', 'enabled' => '1'])
            ->assertRedirect(route('admin.shop-payments.edit').'#shipping');
        $zone = ShippingZone::query()->firstOrFail();
        $this->assertSame(25000, $zone->price_amount->amount);
        $this->assertTrue($zone->enabled);
        $this->get(route('admin.shop-payments.edit'))->assertSee('value="25.000"', false)->assertSee('value="Asunción"', false);

        $this->put(route('admin.shipping-zones.update', ['zone' => $zone->id]), ['name' => 'Gran Asunción', 'price' => '30000', 'enabled' => '0', 'sort_order' => '3'])
            ->assertSessionHasNoErrors();
        $zone->refresh();
        $this->assertSame(['Gran Asunción', 30000, false, 3], [$zone->name, $zone->price_amount->amount, $zone->enabled, $zone->sort_order]);

        $this->post(route('admin.shipping-zones.store'), ['name' => '', 'price' => 'gratis'])->assertSessionHasErrors(['name', 'price']);
        $this->assertSame(1, ShippingZone::query()->count());

        $this->delete(route('admin.shipping-zones.destroy', ['zone' => $zone->id]))->assertSessionHas('success', __('checkout.zone_deleted'));
        $this->assertSame(0, ShippingZone::query()->count());
        $this->delete(route('admin.shipping-zones.destroy', ['zone' => $zone->id]))->assertNotFound();
    }

    public function test_a_selling_site_cannot_lose_its_last_payment_method_or_delivery_option(): void
    {
        $this->readyToSell();
        $this->setting('shop_pickup_enabled', '0');
        $zone = CheckoutFixtures::shippingZone();
        $this->useShop('checkout');
        $this->actingAs($this->admin);

        $this->put(route('admin.shop-payments.update'), ['methods' => ['bank_transfer' => ['enabled' => '0'], 'cod' => ['enabled' => '0']]])
            ->assertSessionHasErrors(['selling' => __('checkout.selling_would_stop', ['missing' => __('checkout.readiness_payment_method')])]);
        $this->assertSame(2, PaymentMethod::query()->where('enabled', true)->count());
        $this->put(route('admin.shop-payments.update'), ['methods' => ['cod' => ['enabled' => '0']]])->assertSessionHasNoErrors();

        $this->delete(route('admin.shipping-zones.destroy', ['zone' => $zone->id]))->assertSessionHasErrors('zone');
        $this->assertDatabaseHas('shipping_zones', ['id' => $zone->id]);
        $this->put(route('admin.shipping-zones.update', ['zone' => $zone->id]), ['name' => 'Asunción', 'price' => '25000', 'enabled' => '0'])->assertSessionHasErrors('zone');
        $this->assertTrue($zone->fresh()->enabled);

        // With pickup on, the zone can go.
        $this->put(route('admin.shop-payments.update'), ['shop_pickup_enabled' => '1'])->assertSessionHasNoErrors();
        $this->delete(route('admin.shipping-zones.destroy', ['zone' => $zone->id]))->assertSessionHasNoErrors();
        $this->assertTrue(app(ShopModule::class)->checkout());
    }

    public function test_settings_shop_lists_what_is_missing_with_links_and_activates_with_everything_met(): void
    {
        $this->actingAs($this->admin);
        $this->get(route('admin.settings.shop'))->assertOk()
            ->assertSee('data-shop-mode-choice="checkout" data-selectable="0"', false)
            ->assertSee(__('checkout.readiness_missing'))
            ->assertSee(ucfirst(__('checkout.readiness_payment_method')))
            ->assertSee(ucfirst(__('checkout.readiness_mail')))
            ->assertSee('href="'.route('admin.shop-payments.edit').'"', false)
            ->assertSee('href="'.route('admin.email.index').'"', false);
        // A hand-typed ?mode=checkout gets no confirmation while something is missing.
        $this->get(route('admin.settings.shop', ['mode' => 'checkout']))->assertOk()->assertDontSee('data-shop-module-confirm', false);
        $this->put(route('admin.settings.shop.update'), ['mode' => 'checkout', 'confirm' => '1'])->assertSessionHasErrors('mode');

        $this->readyToSell();
        $this->setting('mail_enabled', '0');
        $this->assertSame([CheckoutReadiness::MAIL], array_map(fn ($item) => $item->key, app(CheckoutReadiness::class)->missing()));
        $this->put(route('admin.settings.shop.update'), ['mode' => 'checkout', 'confirm' => '1'])
            ->assertSessionHasErrors(['mode' => __('checkout.readiness_blocked', ['missing' => __('checkout.readiness_mail')])]);
        $this->setting('mail_enabled', '1');

        $this->get(route('admin.settings.shop'))->assertOk()
            ->assertSee('data-shop-mode-choice="checkout" data-selectable="1"', false)
            ->assertDontSee('data-shop-readiness-missing', false)
            ->assertSee(__('checkout.readiness_all_met'));
        $this->get(route('admin.settings.shop', ['mode' => 'checkout']))->assertSee('data-shop-module-confirm="checkout"', false);
        $this->put(route('admin.settings.shop.update'), ['mode' => 'checkout', 'confirm' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue(app(ShopModule::class)->checkout());

        // Leaving checkout tells how many orders are still open.
        CheckoutFixtures::order();
        CheckoutFixtures::order()->forceFill(['status' => Order::STATUS_PAID])->save();
        CheckoutFixtures::order()->forceFill(['status' => Order::STATUS_CANCELLED])->save();
        $this->get(route('admin.settings.shop', ['mode' => 'catalog']))->assertOk()
            ->assertSee('data-shop-open-orders="2"', false)
            ->assertSee(trans_choice('checkout.open_orders_warning', 2, ['count' => 2]))
            // Coming down to the catalog says what turns off, never that something turns on.
            ->assertSee('Se apagan el carrito, el checkout y la venta en línea')
            ->assertDontSee(__('shop.module_confirm_catalog'));
        $this->get(route('admin.settings.shop', ['mode' => 'off']))->assertOk()
            ->assertSee('data-shop-selling-off', false)
            ->assertSee(__('shop.module_confirm_selling_off'))
            ->assertSee('data-shop-open-orders="2"', false);

        $this->put(route('admin.settings.shop.update'), ['mode' => 'catalog', 'confirm' => '1'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', __('shop.module_saved_catalog_from_checkout').' '.trans_choice('checkout.open_orders_warning', 2, ['count' => 2]));
        $this->assertSame('catalog', app(ShopModule::class)->mode());

        // From the catalog nothing about selling is said: it was not on.
        $this->get(route('admin.settings.shop', ['mode' => 'off']))->assertOk()
            ->assertDontSee('data-shop-selling-off', false)
            ->assertDontSee('data-shop-open-orders', false);
        $this->put(route('admin.settings.shop.update'), ['mode' => 'off', 'confirm' => '1'])
            ->assertSessionHas('success', __('shop.module_saved_off'));
    }

    public function test_bancard_card_in_connectors_follows_the_payment_method(): void
    {
        $this->actingAs($this->admin);
        $registry = app(ConnectorRegistry::class);

        // Not configured: the card sends to the payments screen.
        $this->assertSame(ConnectorStatus::NeedsAction, $registry->status('bancard'));
        $this->get(route('admin.connectors.index'))->assertOk()
            ->assertSee('data-connector="bancard" data-status="needs_action"', false)
            ->assertSee(__('checkout.connector_bancard_configure'))
            ->assertSee(route('admin.shop-payments.edit', ['connector' => 'bancard']), false);

        // Keys loaded and Bancard turned on, but not selling yet: still needs action, now pointing at Settings > Shop.
        $this->saveKeys();
        $this->put(route('admin.shop-payments.update'), ['methods' => ['bancard' => ['enabled' => '1']]])->assertSessionHasNoErrors();
        $this->assertSame([BancardConnector::REQUIREMENT_CHECKOUT], array_map(fn ($r) => $r->key, $registry->unmet('bancard')));
        $this->assertSame(ConnectorStatus::NeedsAction, $registry->status('bancard'));

        // Selling with Bancard on: connected (the row mirrors the method).
        $this->readyToSell();
        $this->useShop('checkout');
        $this->put(route('admin.shop-payments.update'), ['methods' => ['bancard' => ['enabled' => '0']]])->assertSessionHasNoErrors();
        $this->put(route('admin.shop-payments.update'), ['methods' => ['bancard' => ['enabled' => '1']]])->assertSessionHasNoErrors();
        $this->assertSame(ConnectorStatus::Connected, $registry->status('bancard'));
        $this->get(route('admin.connectors.index'))->assertSee('data-connector="bancard" data-status="connected"', false);

        // Turned off from the payments screen: the card follows.
        $this->put(route('admin.shop-payments.update'), ['methods' => ['bancard' => ['enabled' => '0']]])->assertSessionHasNoErrors();
        $this->assertFalse((bool) $registry->record('bancard')->enabled);
        $this->assertSame(ConnectorStatus::NeedsAction, $registry->status('bancard'));

        // Shop off: only the catalog button.
        $this->useShop('off');
        $this->get(route('admin.connectors.index'))->assertOk()
            ->assertSee('data-connector="bancard" data-status="needs_action"', false)
            ->assertDontSee(route('admin.shop-payments.edit', ['connector' => 'bancard']), false);
    }

    public function test_turning_selling_on_and_off_in_settings_shop_syncs_the_bancard_card(): void
    {
        $this->actingAs($this->admin);
        $registry = app(ConnectorRegistry::class);
        $this->readyToSell();
        $this->saveKeys();
        $this->put(route('admin.shop-payments.update'), ['methods' => ['bancard' => ['enabled' => '1']]])->assertSessionHasNoErrors();
        $this->assertFalse((bool) $registry->record('bancard')?->enabled, 'Catalog only: Bancard cannot be connected yet.');

        // Selling online is turned on from Settings > Shop, not from the payments screen: the card follows at once.
        $this->put(route('admin.settings.shop.update'), ['mode' => 'checkout', 'confirm' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue((bool) $registry->record('bancard')->enabled);
        $this->assertSame(ConnectorStatus::Connected, $registry->status('bancard'));
        $this->get(route('admin.connectors.index'))->assertSee('data-connector="bancard" data-status="connected"', false);

        // Back to catalog only: the card is off again, and the method keeps its keys and its switch.
        $this->put(route('admin.settings.shop.update'), ['mode' => 'catalog', 'confirm' => '1'])->assertSessionHasNoErrors();
        $this->assertFalse((bool) $registry->record('bancard')->enabled);
        $this->assertSame(ConnectorStatus::NeedsAction, $registry->status('bancard'));
        $this->assertTrue((bool) $this->bancard()->enabled);
        $this->assertNotNull($this->bancard()->credentials('staging'));
    }
}
