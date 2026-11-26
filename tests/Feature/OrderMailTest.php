<?php

namespace Tests\Feature;

use App\Events\Shop\OrderCancelled;
use App\Events\Shop\OrderFulfilled;
use App\Events\Shop\OrderPaid;
use App\Events\Shop\OrderPlaced;
use App\Events\Shop\StockShortfall;
use App\Listeners\Shop\SendOrderMails;
use App\Mail\Shop\OrderCancelledCustomer;
use App\Mail\Shop\OrderFulfilledCustomer;
use App\Mail\Shop\OrderPaidCustomer;
use App\Mail\Shop\OrderPlacedCustomer;
use App\Mail\Shop\OrderPlacedStore;
use App\Mail\Shop\StockShortfallStore;
use App\Models\Locale;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Services\Forms\SubmissionNotifier;
use App\Support\SettingDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CheckoutFixtures;
use Tests\TestCase;

/**
 * Order emails (PLAN.md E3.6, track 3E). Events are dispatched by hand: the services that fire them belong to
 * other tracks.
 */
class OrderMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Locale::query()->updateOrCreate(['code' => 'es'], ['name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
        Locale::query()->updateOrCreate(['code' => 'en'], ['name' => 'English', 'is_base' => false, 'is_active' => true, 'is_public' => true, 'sort_order' => 1]);
        $this->useCheckout();
        $this->setting('mail_enabled', '1');
        $this->setting('site_name', 'Tienda Prueba');
        Mail::fake();
    }

    public function test_subscriber_is_registered_once_for_each_order_event(): void
    {
        foreach ([OrderPlaced::class, OrderPaid::class, OrderFulfilled::class, OrderCancelled::class, StockShortfall::class] as $event) {
            $ours = array_filter(
                Event::getRawListeners()[$event] ?? [],
                fn ($listener) => (is_array($listener) && ($listener[0] ?? null) === SendOrderMails::class)
                    || (is_string($listener) && str_starts_with($listener, SendOrderMails::class)),
            );
            $this->assertCount(1, $ours, $event);
        }
    }

    public function test_order_placed_queues_the_customer_and_store_mails(): void
    {
        $order = CheckoutFixtures::order([[CheckoutFixtures::product(['title' => 'Remera azul']), 1]]);

        OrderPlaced::dispatch($order);

        Mail::assertNothingSent();
        Mail::assertQueuedCount(2);
        Mail::assertQueued(OrderPlacedCustomer::class, fn (OrderPlacedCustomer $mail) => $mail->hasTo('ana@example.com')
            && $mail->connection === 'database'
            && $mail->afterCommit === true
            && $mail->mailer === app(SubmissionNotifier::class)->mailer());
        Mail::assertQueued(OrderPlacedStore::class, fn (OrderPlacedStore $mail) => $mail->hasTo('pedidos@example.com')
            && $mail->hasReplyTo('ana@example.com'));
    }

    public function test_mails_use_the_brevo_mailer_when_the_site_has_a_key(): void
    {
        $this->setting('brevo_api_key', 'xkeysib-prueba');
        $order = CheckoutFixtures::order();

        OrderPlaced::dispatch($order);

        Mail::assertQueued(OrderPlacedCustomer::class, fn (OrderPlacedCustomer $mail) => $mail->mailer === 'brevo');
        Mail::assertQueued(OrderPlacedStore::class, fn (OrderPlacedStore $mail) => $mail->mailer === 'brevo');
    }

    public function test_customer_mail_has_the_token_link_bank_details_and_prices_in_guaranies(): void
    {
        $order = CheckoutFixtures::order([[CheckoutFixtures::product(['title' => 'Remera azul']), 1]], ['zone' => CheckoutFixtures::shippingZone('Central', 25000)]);
        $token = $order->plainAccessToken();

        OrderPlaced::dispatch($order);

        $mail = $this->queued(OrderPlacedCustomer::class);
        $html = $mail->render();
        $this->assertStringContainsString(route('shop.order.show', ['token' => $token]), $html);
        $this->assertStringContainsString('Gs. 150.000', $html);
        $this->assertStringContainsString('Gs. 25.000', $html);
        $this->assertStringContainsString('Gs. 175.000', $html);
        $this->assertStringContainsString('Remera azul', $html);
        $this->assertStringContainsString('Hola, Ana Pérez:', $html);
        foreach (CheckoutFixtures::BANK_DETAILS as $value) {
            $this->assertStringContainsString($value, $html);
        }
        $this->assertStringContainsString('Indicá el número de pedido '.$order->number, $html);
        $this->assertStringContainsString('Av. Mariscal López 1234', $html);
        $this->assertSame('Recibimos tu pedido '.$order->number, $mail->withLocale('es', fn () => $mail->envelope()->subject));
        $this->assertSame('pedidos@example.com', $mail->envelope()->replyTo[0]->address);
    }

    public function test_cash_on_delivery_shows_its_instructions_and_pickup_its_address(): void
    {
        $order = CheckoutFixtures::order([], ['method' => 'cod']);

        OrderPlaced::dispatch($order);

        $html = $this->queued(OrderPlacedCustomer::class)->render();
        $this->assertStringContainsString(e(CheckoutFixtures::COD_INSTRUCTIONS), $html);
        $this->assertStringContainsString('Av. España 123, Asunción', $html);
        $this->assertStringNotContainsString(CheckoutFixtures::BANK_DETAILS['account_number'], $html);
    }

    public function test_store_mail_links_to_the_panel_never_to_the_customer_token(): void
    {
        $order = CheckoutFixtures::order([], ['notes' => 'Tocar timbre']);
        $token = $order->plainAccessToken();

        OrderPlaced::dispatch($order);

        $html = $this->queued(OrderPlacedStore::class)->render();
        $this->assertStringContainsString(route('admin.orders.show', ['order' => $order->id]), $html);
        $this->assertStringContainsString('0981 123 456', $html);
        $this->assertStringContainsString('Tocar timbre', $html);
        $this->assertStringContainsString('Gs. 150.000', $html);
        $this->assertStringNotContainsString($token, $html);
    }

    public function test_customer_mails_follow_the_order_language_and_store_mails_the_panel_language(): void
    {
        $order = CheckoutFixtures::order([], ['locale' => 'en']);
        $token = $order->plainAccessToken();

        OrderPlaced::dispatch($order);

        $customer = $this->queued(OrderPlacedCustomer::class);
        $this->assertSame('en', $customer->locale);
        $html = $customer->render();
        $this->assertStringContainsString('Hello Ana Pérez,', $html);
        $this->assertStringContainsString('Bank transfer', $html);
        $this->assertStringContainsString('Gs. 150.000', $html);
        $this->assertStringContainsString(route('localized.shop.order.show', ['locale' => 'en', 'token' => $token]), $html);
        $this->assertSame('We received your order '.$order->number, $customer->withLocale('en', fn () => $customer->envelope()->subject));

        $store = $this->queued(OrderPlacedStore::class);
        $this->assertSame('es', $store->locale);
        $this->assertStringContainsString('Nuevo pedido '.$order->number, $store->render());
    }

    public function test_paid_mail_shows_the_authorization_but_no_restricted_bancard_data(): void
    {
        $order = CheckoutFixtures::order([], ['internal_notes' => 'Cliente difícil', 'ip' => '203.0.113.9', 'user_agent' => 'AgenteSecreto/1.0']);
        $payment = $order->payments->first();
        $payment->forceFill([
            'status' => Payment::STATUS_CONFIRMED,
            'authorization_number' => '123456',
            'ticket_number' => '987654321',
            'response_code' => '00',
            'response_description' => 'Transaccion aprobada',
            'payload_json' => ['extended_response_description' => 'DetalleExtendidoSecreto', 'security_information' => ['card_source' => 'L', 'customer_ip' => '198.51.100.7']],
            'confirmed_at' => now(),
        ])->save();
        $order->forceFill(['status' => Order::STATUS_PAID, 'paid_at' => now()])->save();

        OrderPaid::dispatch($order, $payment);

        $mail = $this->queued(OrderPaidCustomer::class);
        $this->assertTrue($mail->hasTo('ana@example.com'));
        $html = $mail->render();
        $this->assertStringContainsString('123456', $html);
        $this->assertStringContainsString('Gs. 150.000', $html);
        $this->assertStringContainsString('Confirmamos el pago', $html);
        foreach (['DetalleExtendidoSecreto', '198.51.100.7', 'card_source', 'Cliente difícil', '203.0.113.9', 'AgenteSecreto', 'response_code'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
        // Paid: no payment instructions any more.
        $placed = new OrderPlacedCustomer($order->fresh());
        $this->assertStringNotContainsString(CheckoutFixtures::BANK_DETAILS['account_number'], $placed->render());
    }

    public function test_bancard_order_mail_offers_to_pay_by_card_without_secrets(): void
    {
        $method = PaymentMethod::query()->where('key', 'bancard')->firstOrFail();
        $method->forceFill(['enabled' => true, 'environment' => PaymentMethod::ENVIRONMENT_STAGING, 'secrets' => ['staging' => ['public_key' => str_repeat('a', 32), 'private_key' => str_repeat('p', 40)]]])->save();
        $order = CheckoutFixtures::order([], ['method' => 'bancard']);

        OrderPlaced::dispatch($order);

        $html = $this->queued(OrderPlacedCustomer::class)->render();
        $this->assertStringContainsString('Pagar con tarjeta', $html);
        $this->assertStringContainsString(route('shop.order.show', ['token' => $order->plainAccessToken()]), $html);
        $this->assertStringNotContainsString(str_repeat('p', 40), $html);
        $this->assertStringNotContainsString(str_repeat('a', 32), $html);
    }

    public function test_cancelled_mail_explains_an_unpaid_cancellation(): void
    {
        $order = CheckoutFixtures::order([], ['status' => Order::STATUS_CANCELLED, 'cancelled_at' => now()]);

        OrderCancelled::dispatch($order, OrderCancelled::REASON_UNPAID);

        $mail = $this->queued(OrderCancelledCustomer::class);
        $this->assertTrue($mail->hasTo('ana@example.com'));
        $html = $mail->render();
        $this->assertStringContainsString('Tu pedido fue cancelado', $html);
        $this->assertStringContainsString('el pago no se completó a tiempo', $html);
        $this->assertStringNotContainsString(CheckoutFixtures::BANK_DETAILS['account_number'], $html);

        $byAdmin = new OrderCancelledCustomer($order, OrderCancelled::REASON_ADMIN);
        $this->assertStringNotContainsString('el pago no se completó a tiempo', $byAdmin->render());
    }

    public function test_fulfilled_mail_says_on_its_way_for_delivery_and_ready_with_the_address_for_pickup(): void
    {
        $delivery = CheckoutFixtures::order([[CheckoutFixtures::product(['title' => 'Remera azul']), 1]], ['zone' => CheckoutFixtures::shippingZone('Central', 25000), 'status' => Order::STATUS_FULFILLED]);
        $delivery->forceFill(['internal_notes' => 'Nota interna secreta'])->save();

        OrderFulfilled::dispatch($delivery);

        Mail::assertQueuedCount(1);
        $mail = $this->queued(OrderFulfilledCustomer::class);
        $this->assertTrue($mail->hasTo('ana@example.com'));
        $this->assertSame('database', $mail->connection);
        $this->assertTrue($mail->afterCommit);
        $this->assertSame('Tu pedido '.$delivery->number.' está en camino', $mail->withLocale('es', fn () => $mail->envelope()->subject));
        $html = $mail->render();
        $this->assertStringContainsString('Tu pedido ya salió para la dirección que nos indicaste', $html);
        $this->assertStringContainsString('Remera azul', $html);
        $this->assertStringContainsString(route('shop.order.show', ['token' => $delivery->plainAccessToken()]), $html);
        $this->assertStringNotContainsString('Nota interna secreta', $html);
        $this->assertStringNotContainsString('listo para retirar', $html);

        $pickup = CheckoutFixtures::order([], ['status' => Order::STATUS_FULFILLED]);
        OrderFulfilled::dispatch($pickup);
        $mail = Mail::queued(OrderFulfilledCustomer::class, fn (OrderFulfilledCustomer $mail) => $mail->order->is($pickup))->sole();
        $this->assertSame('Tu pedido '.$pickup->number.' está listo', $mail->withLocale('es', fn () => $mail->envelope()->subject));
        $html = $mail->render();
        $this->assertStringContainsString('Tu pedido está listo para retirar', $html);
        $this->assertStringContainsString('Av. España 123, Asunción', $html);
        $this->assertStringNotContainsString('está en camino', $html);
    }

    public function test_paid_mail_promises_the_next_email_coherently(): void
    {
        $order = CheckoutFixtures::order();
        OrderPaid::dispatch($order, $order->payments->first());

        $html = $this->queued(OrderPaidCustomer::class)->render();
        $this->assertStringContainsString('Te escribimos de nuevo cuando salga para tu dirección o esté listo para retirar.', $html);
    }

    public function test_stock_shortfall_warns_the_store(): void
    {
        $order = CheckoutFixtures::order();
        $shortfall = [['product_id' => 1, 'variant_id' => null, 'title' => 'Remera azul', 'requested' => 3, 'available' => 1]];

        StockShortfall::dispatch($order, $shortfall);

        Mail::assertQueuedCount(1);
        $mail = $this->queued(StockShortfallStore::class);
        $this->assertTrue($mail->hasTo('pedidos@example.com'));
        $html = $mail->render();
        $this->assertStringContainsString('Remera azul: pedido 3, disponible 1', $html);
        $this->assertStringContainsString(route('admin.orders.show', ['order' => $order->id]), $html);
        $this->assertStringNotContainsString((string) $order->plainAccessToken(), $html);
    }

    public function test_an_event_arriving_twice_sends_each_mail_once(): void
    {
        $order = CheckoutFixtures::order();
        $payment = $order->payments->first();

        OrderPlaced::dispatch($order);
        OrderPlaced::dispatch($order->fresh());
        OrderPaid::dispatch($order, $payment);
        OrderPaid::dispatch($order, $payment);
        OrderFulfilled::dispatch($order);
        OrderFulfilled::dispatch($order->fresh());
        OrderCancelled::dispatch($order);
        OrderCancelled::dispatch($order, OrderCancelled::REASON_UNPAID);
        StockShortfall::dispatch($order, []);
        StockShortfall::dispatch($order, []);

        Mail::assertQueued(OrderPlacedCustomer::class, 1);
        Mail::assertQueued(OrderPlacedStore::class, 1);
        Mail::assertQueued(OrderPaidCustomer::class, 1);
        Mail::assertQueued(OrderFulfilledCustomer::class, 1);
        Mail::assertQueued(OrderCancelledCustomer::class, 1);
        Mail::assertQueued(StockShortfallStore::class, 1);

        // Another order is not affected; the panel's "resend" skips the guard.
        OrderPlaced::dispatch(CheckoutFixtures::order());
        Mail::assertQueued(OrderPlacedCustomer::class, 2);
        $this->assertTrue(app(SendOrderMails::class)->resend($order));
        Mail::assertQueued(OrderPlacedCustomer::class, 3);
    }

    public function test_nothing_is_queued_while_mail_is_off_and_it_is_not_marked_as_sent(): void
    {
        $this->setting('mail_enabled', '0');
        $order = CheckoutFixtures::order();

        OrderPlaced::dispatch($order);
        Mail::assertNothingQueued();
        $this->assertFalse(app(SendOrderMails::class)->resend($order));

        $this->setting('mail_enabled', '1');
        OrderPlaced::dispatch($order);
        Mail::assertQueued(OrderPlacedCustomer::class, 1);
    }

    public function test_orders_email_falls_back_to_the_contact_email(): void
    {
        $this->setting('shop_orders_email', '');
        $this->setting('contact_email', 'contacto@example.com');

        OrderPlaced::dispatch(CheckoutFixtures::order());

        Mail::assertQueued(OrderPlacedStore::class, fn (OrderPlacedStore $mail) => $mail->hasTo('contacto@example.com'));
    }

    public function test_the_queue_payload_never_carries_the_plain_token(): void
    {
        Queue::fake();
        // The real mail manager, so queueing goes through the (faked) queue as in production.
        Mail::swap(new MailManager($this->app));
        $order = CheckoutFixtures::order();
        $token = $order->plainAccessToken();

        OrderPlaced::dispatch($order);

        $jobs = Queue::pushed(SendQueuedMailable::class);
        $this->assertCount(2, $jobs);
        foreach ($jobs as $job) {
            $this->assertSame('database', $job->connection);
            $this->assertStringNotContainsString($token, serialize($job));
        }
    }

    /** Setting::set() only updates existing rows: create the row from its defaults first. */
    private function setting(string $key, string $value): void
    {
        Setting::query()->firstOrCreate(['key' => $key], SettingDefaults::for($key) ?? ['value' => null, 'type' => 'string', 'group' => 'general']);
        Setting::set($key, $value);
    }

    /**
     * @template T of \Illuminate\Mail\Mailable
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function queued(string $class): object
    {
        $mails = Mail::queued($class);
        $this->assertCount(1, $mails, $class);

        return $mails->first();
    }
}
