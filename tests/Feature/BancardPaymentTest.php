<?php

namespace Tests\Feature;

use App\Mail\Shop\LatePaymentStore;
use App\Mail\Shop\OrderPaidCustomer;
use App\Mail\Shop\PaymentAmountMismatchStore;
use App\Models\Locale;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Shop\PaymentOperations;
use App\Services\Shop\StrayChargeOperations;
use App\Shop\Payments\Bancard\BancardClient;
use App\Shop\Payments\Bancard\BancardProvider;
use App\Shop\Payments\PaymentProviderRegistry;
use App\Support\SettingDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\CheckoutFixtures;
use Tests\Support\FakeBancard;
use Tests\TestCase;

/**
 * Bancard as a payment provider: iframe start, confirmation webhook, return to the order page, abandoned-payment
 * sweep and late charges (PLAN.md E3.2 steps 5–9, stage 3 track 3C2). Bancard is always FakeBancard.
 */
class BancardPaymentTest extends TestCase
{
    use RefreshDatabase;

    private FakeBancard $bancard;

    protected function setUp(): void
    {
        parent::setUp();
        Locale::updateOrCreate(['code' => 'es'], ['name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
        $this->useCheckout();
        config(['cms.shop.payment_providers.bancard' => BancardProvider::class]);
        $this->bancard = $this->useFakeBancard();
        Setting::query()->firstOrCreate(['key' => 'mail_enabled'], SettingDefaults::for('mail_enabled') ?? ['value' => null, 'type' => 'boolean', 'group' => 'mail']);
        Setting::set('mail_enabled', '1');
        RateLimiter::clear('forms');
        Mail::fake();
    }

    public function test_the_order_page_starts_one_buy_and_reuses_its_process_id(): void
    {
        [$order, $payment] = $this->bancardOrder();
        $url = route('shop.order.show', $order->plainAccessToken());

        $this->get($url)->assertOk()->assertSee('data-payment-iframe', false);
        $payment->refresh();
        $this->assertNotNull($payment->process_id);
        $this->bancard->assertCalled(FakeBancard::OPERATION_SINGLE_BUY, 1);
        $call = $this->bancard->calls(FakeBancard::OPERATION_SINGLE_BUY)[0];
        $this->assertSame(PaymentMethod::ENVIRONMENT_STAGING, $call['environment']);
        $this->assertSame($payment->id, $call['body']['operation']['shop_process_id']);
        $this->assertSame($url, $call['body']['operation']['return_url']);
        $this->assertSame('300000.00', $call['body']['operation']['amount']);
        $this->assertSame(__('checkout.order_title', ['number' => $order->number]), $call['body']['operation']['description']);

        $this->get($url)
            ->assertOk()
            ->assertSee('data-process-id="'.$payment->process_id.'"', false)
            ->assertSee('<script src="'.BancardClient::checkoutScriptUrl(PaymentMethod::ENVIRONMENT_STAGING).'"></script>', false);
        $this->bancard->assertCalled(FakeBancard::OPERATION_SINGLE_BUY, 1);
        $this->assertSame(1, PaymentEvent::query()->where('type', PaymentEvent::TYPE_API_CALL)->count());
    }

    public function test_a_single_buy_that_timed_out_after_bancard_created_it_is_closed_and_retried_with_a_new_id(): void
    {
        [$order, $payment, $product] = $this->bancardOrder();
        $url = route('shop.order.show', $order->plainAccessToken());
        $this->bancard->scenario(FakeBancard::OPERATION_SINGLE_BUY, FakeBancard::TIMEOUT_AFTER_CREATE)
            ->scenario(FakeBancard::OPERATION_CONFIRMATIONS, FakeBancard::NOT_PAID);

        // Bancard created the buy but the answer was lost: no process_id here, nobody can pay that buy.
        $this->get($url)->assertOk()->assertSee('data-payment-start-failed', false);
        $this->assertNull($payment->fresh()->process_id);

        // Next visit: Bancard refuses the same shop_process_id; it is asked, was not paid, and the attempt expires.
        $this->get($url)->assertOk()->assertSee('data-order-payment="expired"', false)->assertSee('data-payment-retry', false)
            ->assertDontSee('data-payment-start-failed', false);
        $this->bancard->assertCalled(FakeBancard::OPERATION_CONFIRMATIONS, 1);
        $this->assertSame([Payment::STATUS_EXPIRED, Order::STATUS_PENDING, 10], [$payment->fresh()->status, $order->fresh()->status, $product->fresh()->stock]);

        // "Try again" opens a new attempt with a new shop_process_id, and its iframe starts.
        $this->post(route('shop.order.pay', $order->plainAccessToken()))->assertRedirect($url);
        $retry = $order->latestPayment()->first();
        $this->assertNotSame($payment->id, $retry->id);
        $this->get($url)->assertOk()->assertSee('data-payment-iframe', false);
        $ids = array_map(fn (array $call) => $call['body']['operation']['shop_process_id'], $this->bancard->calls(FakeBancard::OPERATION_SINGLE_BUY));
        $this->assertSame([$payment->id, $payment->id, $retry->id], $ids);
    }

    public function test_a_timed_out_single_buy_that_bancard_reports_paid_is_applied(): void
    {
        [$order, $payment, $product] = $this->bancardOrder();
        $url = route('shop.order.show', $order->plainAccessToken());
        $this->bancard->scenario(FakeBancard::OPERATION_SINGLE_BUY, FakeBancard::TIMEOUT_AFTER_CREATE);
        $this->get($url)->assertOk()->assertSee('data-payment-start-failed', false);

        $this->get($url)->assertOk()->assertSee('data-order-payment="confirmed"', false);
        $this->assertSame([Payment::STATUS_CONFIRMED, Order::STATUS_PAID, 8], [$payment->fresh()->status, $order->fresh()->status, $product->fresh()->stock]);
    }

    public function test_a_refused_single_buy_without_an_earlier_timeout_only_says_the_start_failed(): void
    {
        [$order, $payment] = $this->bancardOrder();
        $this->bancard->registerBuy($payment);

        $this->get(route('shop.order.show', $order->plainAccessToken()))->assertOk()->assertSee('data-payment-start-failed', false);
        $this->bancard->assertNotCalled(FakeBancard::OPERATION_CONFIRMATIONS);
        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_start_needs_readable_keys_bound_to_this_site(): void
    {
        [$order] = $this->bancardOrder();
        $method = PaymentMethod::query()->where('key', 'bancard')->firstOrFail();
        $method->forceFill(['bound_origin' => 'https://otro-sitio.example'])->save();
        $this->assertSame([BancardProvider::MISSING_CREDENTIALS_UNBOUND], app(BancardProvider::class)->missing($method->fresh()));

        $this->get(route('shop.order.show', $order->plainAccessToken()))->assertOk()->assertSee('data-payment-start-failed', false);
        $this->bancard->assertNotCalled(FakeBancard::OPERATION_SINGLE_BUY);
    }

    public function test_monitoring_ping_answers_200_and_records_nothing(): void
    {
        $this->postJson(route('webhooks.bancard'), $this->bancard->monitoringPayload())->assertOk()->assertExactJson(['status' => 'success']);
        $this->call('POST', route('webhooks.bancard'), [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertOk();
        $this->call('POST', route('webhooks.bancard'), [], [], [], ['CONTENT_TYPE' => 'application/json'], '')->assertOk();

        $this->assertSame(0, PaymentEvent::query()->count());
    }

    public function test_unknown_attempt_answers_200_and_is_recorded_without_its_token(): void
    {
        [, $payment] = $this->bancardOrder();
        $payload = $this->bancard->webhookPayload($payment);
        $payload['operation']['shop_process_id'] = 987654;

        $this->postJson(route('webhooks.bancard'), $payload)->assertOk();

        $event = PaymentEvent::query()->sole();
        $this->assertSame([PaymentEvent::TYPE_UNKNOWN_PAYMENT, null], [$event->type, $event->payment_id]);
        $this->assertArrayNotHasKey('token', $event->payload_json['operation']);
        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_invalid_token_answers_403_and_changes_nothing(): void
    {
        [$order, $payment, $product] = $this->bancardOrder();
        $forged = $this->bancard->webhookPayload($payment);
        $forged['operation']['token'] = str_repeat('a', 32);
        // The official SDK compares the token without md5: the raw concatenation must not pass either.
        $raw = $this->bancard->webhookPayload($payment);
        $raw['operation']['token'] = FakeBancard::STAGING_PRIVATE_KEY.$payment->shop_process_id.'confirm'.FakeBancard::amount($payment).'PYG';

        $this->postJson(route('webhooks.bancard'), $forged)->assertForbidden();
        $this->postJson(route('webhooks.bancard'), $raw)->assertForbidden();

        $this->assertSame([Payment::STATUS_PENDING, Order::STATUS_PENDING, 10], [$payment->fresh()->status, $order->fresh()->status, $product->fresh()->stock]);
        $events = PaymentEvent::query()->get();
        $this->assertSame([PaymentEvent::TYPE_INVALID_TOKEN, PaymentEvent::TYPE_INVALID_TOKEN], $events->pluck('type')->all());
        $this->assertFalse($events->first()->token_valid);
        $this->assertSame($payment->id, $events->first()->payment_id);
        Mail::assertNothingQueued();
    }

    public function test_approved_notification_pays_the_order_takes_stock_and_is_idempotent(): void
    {
        [$order, $payment, $product] = $this->bancardOrder();
        $payload = $this->bancard->webhookPayload($payment);

        $started = hrtime(true);
        $this->postJson(route('webhooks.bancard'), $payload)->assertOk()->assertExactJson(['status' => 'success']);
        $this->assertLessThan(60, (hrtime(true) - $started) / 1e9);
        $this->postJson(route('webhooks.bancard'), $payload)->assertOk();

        $payment->refresh();
        $this->assertSame([Payment::STATUS_CONFIRMED, '123456', '00'], [$payment->status, $payment->authorization_number, $payment->response_code]);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(8, $product->fresh()->stock, 'Stock is taken once.');
        $this->assertSame(2, PaymentEvent::query()->where('type', PaymentEvent::TYPE_NOTIFICATION)->count());
        // Nothing slow inside the webhook: no call to Bancard, mails queued (once).
        $this->bancard->assertNotCalled();
        Mail::assertNothingSent();
        Mail::assertQueued(OrderPaidCustomer::class, 1);
    }

    public function test_a_staging_attempt_verifies_with_the_staging_key_after_switching_to_production(): void
    {
        [$order, $payment] = $this->bancardOrder();
        PaymentMethod::query()->where('key', 'bancard')->update(['environment' => PaymentMethod::ENVIRONMENT_PRODUCTION]);

        $withProductionKey = $this->bancard->webhookPayload($payment);
        $withProductionKey['operation']['token'] = $this->bancard->confirmToken(PaymentMethod::ENVIRONMENT_PRODUCTION, (string) $payment->shop_process_id, FakeBancard::amount($payment), 'PYG');
        $this->postJson(route('webhooks.bancard'), $withProductionKey)->assertForbidden();

        $this->postJson(route('webhooks.bancard'), $this->bancard->webhookPayload($payment))->assertOk();
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_rejected_notification_fails_the_attempt_and_a_retry_gets_a_new_shop_process_id(): void
    {
        [$order, $payment, $product] = $this->bancardOrder();
        $url = route('shop.order.show', $order->plainAccessToken());
        $this->get($url)->assertOk();

        $this->postJson(route('webhooks.bancard'), $this->bancard->webhookPayload($payment, false))->assertOk();

        $this->assertSame([Payment::STATUS_FAILED, '51', Order::STATUS_PENDING, 10], [$payment->fresh()->status, $payment->fresh()->response_code, $order->fresh()->status, $product->fresh()->stock]);
        $this->get($url)
            ->assertOk()
            ->assertSee('data-order-payment="failed"', false)
            ->assertSee('data-payment-retry', false)
            ->assertSee('Fondos insuficientes')
            ->assertDontSee('IMPORTE DE LA TRN SUPERIOR AL SALDO');

        $this->post(route('shop.order.pay', $order->plainAccessToken()))->assertRedirect($url);
        $retry = $order->latestPayment()->first();
        $this->assertNotSame($payment->id, $retry->id);
        $this->assertSame($retry->id, $retry->shop_process_id);
        $this->get($url)->assertOk()->assertSee('data-payment-iframe', false);
        $calls = $this->bancard->calls(FakeBancard::OPERATION_SINGLE_BUY);
        $this->assertSame([$payment->id, $retry->id], array_map(fn (array $call) => $call['body']['operation']['shop_process_id'], $calls));
    }

    public function test_another_amount_fails_the_attempt_is_rolled_back_and_warns_the_store_once(): void
    {
        [$order, $payment, $product] = $this->bancardOrder();
        $payment = $this->started($payment);
        $payload = $this->bancard->webhookPayload($payment);
        $payload['operation']['amount'] = '1000.00';
        $payload['operation']['token'] = $this->bancard->confirmToken(PaymentMethod::ENVIRONMENT_STAGING, (string) $payment->shop_process_id, '1000.00', 'PYG');

        $this->postJson(route('webhooks.bancard'), $payload)->assertOk();

        $this->postJson(route('webhooks.bancard'), $payload)->assertOk();

        $this->assertSame([Payment::STATUS_FAILED, Order::STATUS_PENDING, 10], [$payment->fresh()->status, $order->fresh()->status, $product->fresh()->stock]);
        // Each notification is recorded, but the store is warned once.
        $this->assertSame(2, PaymentEvent::query()->where('type', PaymentEvent::TYPE_AMOUNT_MISMATCH)->count());
        Mail::assertQueued(PaymentAmountMismatchStore::class, 1);
        // The charge for another amount is reversed at Bancard once, so the customer is not left charged.
        $this->bancard->assertCalled(FakeBancard::OPERATION_ROLLBACK, 1);
        $reversal = PaymentEvent::query()->where('type', BancardProvider::EVENT_LATE_PAYMENT_REVERSED)->sole();
        $this->assertSame(StrayChargeOperations::REASON_AMOUNT_MISMATCH, $reversal->payload_json['reason']);
        $this->assertFalse(BancardProvider::needsLateReview($payment));
        // PaymentAmountMismatchStore already warned the store and the reversal worked: no second mail.
        Mail::assertNotQueued(LatePaymentStore::class);
        Mail::assertQueued(PaymentAmountMismatchStore::class, function (PaymentAmountMismatchStore $mail) use ($order) {
            return $mail->hasTo('pedidos@example.com') && str_contains($mail->render(), route('admin.orders.show', ['order' => $order->id]));
        });
        $this->assertSame('Gs. 1.000', PaymentAmountMismatchStore::reportedAmount($payment->fresh()));
        Mail::assertNotQueued(OrderPaidCustomer::class);
    }

    public function test_another_amount_that_cannot_be_rolled_back_is_left_for_review_and_mailed(): void
    {
        [, $payment] = $this->bancardOrder();
        $payment = $this->started($payment);
        $this->bancard->scenario(FakeBancard::OPERATION_ROLLBACK, FakeBancard::ALREADY_CONFIRMED);
        $payload = $this->bancard->webhookPayload($payment);
        $payload['operation']['amount'] = '1000.00';
        $payload['operation']['token'] = $this->bancard->confirmToken(PaymentMethod::ENVIRONMENT_STAGING, (string) $payment->shop_process_id, '1000.00', 'PYG');

        $this->postJson(route('webhooks.bancard'), $payload)->assertOk();

        $this->assertTrue(BancardProvider::needsLateReview($payment));
        $review = PaymentEvent::query()->where('type', BancardProvider::EVENT_LATE_PAYMENT_REVIEW)->sole();
        $this->assertSame(['status' => Payment::STATUS_FAILED, 'code' => 'TransactionAlreadyConfirmed', 'manual_required' => true, 'reason' => StrayChargeOperations::REASON_AMOUNT_MISMATCH], $review->payload_json);
        Mail::assertQueued(PaymentAmountMismatchStore::class, 1);
        Mail::assertQueued(LatePaymentStore::class, function (LatePaymentStore $mail) {
            return ! $mail->reversed && $mail->reason === StrayChargeOperations::REASON_AMOUNT_MISMATCH && str_contains($mail->render(), 'Gs. 1.000');
        });
    }

    public function test_the_return_status_is_not_trusted_and_the_page_queries_bancard(): void
    {
        [$order, $payment] = $this->bancardOrder();
        $url = route('shop.order.show', $order->plainAccessToken());
        $this->get($url)->assertOk();

        // Back with "payment_success" but Bancard has no payment: still confirming.
        $this->bancard->scenario(FakeBancard::OPERATION_CONFIRMATIONS, FakeBancard::NOT_PAID);
        $this->get($url.'?status=payment_success')
            ->assertOk()
            ->assertSee('data-order-payment="confirming"', false);
        $this->assertSame([Payment::STATUS_PENDING, Order::STATUS_PENDING], [$payment->fresh()->status, $order->fresh()->status]);
        $this->bancard->assertCalled(FakeBancard::OPERATION_CONFIRMATIONS, 1);

        // The page reloads itself: no second query within the interval.
        $this->get($url.'?status=payment_success')->assertOk()->assertSee('data-order-payment="confirming"', false);
        $this->bancard->assertCalled(FakeBancard::OPERATION_CONFIRMATIONS, 1);
    }

    public function test_return_without_webhook_confirms_through_get_confirmation_and_shows_only_the_allowed_data(): void
    {
        [$order, $payment, $product] = $this->bancardOrder();
        $url = route('shop.order.show', $order->plainAccessToken());
        $this->get($url)->assertOk();

        $html = $this->get($url.'?status=payment_success')
            ->assertOk()
            ->assertSee('data-order-payment="confirmed"', false)
            ->assertSee($order->number)
            ->assertSee('Gs. 300.000')
            ->assertSee('Transaccion aprobada')
            ->assertSee('123456')
            ->assertDontSee('PARAGUAY')
            ->getContent();
        $this->bancard->assertCalled(FakeBancard::OPERATION_CONFIRMATIONS, 1);
        $this->assertSame([Payment::STATUS_CONFIRMED, Order::STATUS_PAID, 8], [$payment->fresh()->status, $order->fresh()->status, $product->fresh()->stock]);

        $receipt = $this->receipt($html);
        $this->assertSame(5, substr_count($receipt, '<dt'), 'Date, order number, amount, description and authorization only.');
        $this->assertStringContainsString($payment->fresh()->confirmed_at->timezone(config('app.timezone'))->format('d/m/Y H:i'), $receipt);
        $this->assertStringNotContainsString('>00<', $receipt);
    }

    public function test_the_page_never_shows_restricted_bancard_fields(): void
    {
        [$order, $payment] = $this->bancardOrder();
        $payload = $this->bancard->webhookPayload($payment);
        $payload['operation']['extended_response_description'] = 'DETALLE-EXTENDIDO-RESERVADO';
        $payload['operation']['security_information']['card_country'] = 'PAIS-RESERVADO';
        $this->postJson(route('webhooks.bancard'), $payload)->assertOk();

        $html = $this->get(route('shop.order.show', $order->plainAccessToken()))
            ->assertOk()
            ->assertSee('data-payment-receipt', false)
            ->assertSee('123456')
            ->assertDontSee('DETALLE-EXTENDIDO-RESERVADO')
            ->assertDontSee('PAIS-RESERVADO')
            ->assertDontSee('security_information')
            ->getContent();
        $this->assertStringNotContainsString('>00<', $this->receipt($html));
    }

    public function test_the_sweep_rolls_back_abandoned_attempts_after_ten_minutes(): void
    {
        [$order, $payment] = $this->bancardOrder();
        $this->started($payment);
        $this->bancard->scenario(FakeBancard::OPERATION_CONFIRMATIONS, FakeBancard::NOT_PAID);

        $this->travel(9)->minutes();
        $this->artisan('shop:expire-payments')->assertSuccessful();
        $this->bancard->assertNotCalled();
        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);

        $this->travel(2)->minutes();
        $this->artisan('shop:expire-payments')->assertSuccessful();
        $this->bancard->assertCalled(FakeBancard::OPERATION_CONFIRMATIONS, 1);
        $this->bancard->assertCalled(FakeBancard::OPERATION_ROLLBACK, 1);
        $this->assertSame([Payment::STATUS_EXPIRED, Order::STATUS_PENDING], [$payment->fresh()->status, $order->fresh()->status]);

        // The customer can try again from the order page.
        $this->get(route('shop.order.show', $order->plainAccessToken()))->assertOk()->assertSee('data-payment-retry', false);
    }

    public function test_the_sweep_keeps_an_attempt_whose_rollback_fails(): void
    {
        [, $payment] = $this->bancardOrder();
        $this->started($payment);
        $this->bancard->scenario(FakeBancard::OPERATION_CONFIRMATIONS, FakeBancard::NOT_PAID)
            ->scenario(FakeBancard::OPERATION_ROLLBACK, FakeBancard::TIMEOUT);

        $this->travel(11)->minutes();
        $this->artisan('shop:expire-payments')->assertSuccessful();

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_a_payment_made_after_ten_minutes_is_applied_by_the_sweep(): void
    {
        [$order, $payment, $product] = $this->bancardOrder();
        $this->started($payment);

        $this->travel(11)->minutes();
        $this->artisan('shop:expire-payments')->assertSuccessful();

        $this->bancard->assertNotCalled(FakeBancard::OPERATION_ROLLBACK);
        $this->assertSame([Payment::STATUS_CONFIRMED, Order::STATUS_PAID, 8], [$payment->fresh()->status, $order->fresh()->status, $product->fresh()->stock]);
    }

    public function test_the_sweep_cancels_unpaid_orders_past_their_deadline(): void
    {
        [$order] = $this->bancardOrder();

        $this->travel((int) config('cms.shop.checkout.unpaid_order_hours') + 1)->hours();
        $this->artisan('shop:expire-payments')->assertSuccessful();

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->bancard->assertNotCalled();
    }

    public function test_an_approval_for_an_expired_attempt_is_rolled_back_and_the_store_is_warned_once(): void
    {
        [$order, $payment, $product] = $this->bancardOrder();
        $this->started($payment);
        app(PaymentOperations::class)->expire($payment);
        $payload = $this->bancard->webhookPayload($payment->fresh());

        $this->postJson(route('webhooks.bancard'), $payload)->assertOk();
        $this->postJson(route('webhooks.bancard'), $payload)->assertOk();

        $this->bancard->assertCalled(FakeBancard::OPERATION_ROLLBACK, 1);
        $this->assertSame([Payment::STATUS_EXPIRED, Order::STATUS_PENDING, 10], [$payment->fresh()->status, $order->fresh()->status, $product->fresh()->stock]);
        $this->assertSame(1, PaymentEvent::query()->where('type', BancardProvider::EVENT_LATE_PAYMENT_REVERSED)->count());
        $this->assertFalse(BancardProvider::needsLateReview($payment));
        Mail::assertQueued(LatePaymentStore::class, fn (LatePaymentStore $mail) => $mail->reversed && $mail->hasTo('pedidos@example.com'));
        Mail::assertQueuedCount(1);
    }

    public function test_a_late_charge_bancard_already_settled_is_left_for_review(): void
    {
        [, $payment] = $this->bancardOrder();
        $this->started($payment);
        $this->postJson(route('webhooks.bancard'), $this->bancard->webhookPayload($payment, false))->assertOk();
        $this->bancard->scenario(FakeBancard::OPERATION_ROLLBACK, FakeBancard::ALREADY_CONFIRMED);

        $this->postJson(route('webhooks.bancard'), $this->bancard->webhookPayload($payment->fresh()))->assertOk();
        $this->postJson(route('webhooks.bancard'), $this->bancard->webhookPayload($payment->fresh()))->assertOk();

        $this->bancard->assertCalled(FakeBancard::OPERATION_ROLLBACK, 1);
        $this->assertSame(Payment::STATUS_FAILED, $payment->fresh()->status);
        $this->assertTrue(BancardProvider::needsLateReview($payment));
        $this->assertSame([$payment->id], BancardProvider::needingLateReview()->pluck('id')->all());
        $review = PaymentEvent::query()->where('type', BancardProvider::EVENT_LATE_PAYMENT_REVIEW)->sole();
        $this->assertSame(['status' => Payment::STATUS_FAILED, 'code' => 'TransactionAlreadyConfirmed', 'manual_required' => true, 'reason' => StrayChargeOperations::REASON_LATE], $review->payload_json);
        Mail::assertQueued(LatePaymentStore::class, fn (LatePaymentStore $mail) => ! $mail->reversed && $mail->code === 'TransactionAlreadyConfirmed');
    }

    public function test_refund_of_a_settled_transaction_requires_a_manual_return(): void
    {
        [$order, $payment, $product] = $this->bancardOrder();
        $this->started($payment);
        $this->postJson(route('webhooks.bancard'), $this->bancard->webhookPayload($payment))->assertOk();
        $this->bancard->scenario(FakeBancard::OPERATION_ROLLBACK, FakeBancard::ALREADY_CONFIRMED);
        $admin = User::factory()->create(['role' => 'admin']);

        $result = app(PaymentOperations::class)->refund($admin, $payment->fresh());

        $this->assertFalse($result->ok);
        $this->assertTrue($result->manualRequired);
        $this->assertSame('TransactionAlreadyConfirmed', $result->code);
        $this->assertSame([Payment::STATUS_CONFIRMED, Order::STATUS_PAID, 8], [$payment->fresh()->status, $order->fresh()->status, $product->fresh()->stock]);

        // Rolled back while Bancard still can: refunded and stock back.
        $this->bancard->scenario(FakeBancard::OPERATION_ROLLBACK, FakeBancard::APPROVED);
        $this->assertTrue(app(PaymentOperations::class)->refund($admin, $payment->fresh())->ok);
        $this->assertSame([Payment::STATUS_REFUNDED, Order::STATUS_REFUNDED, 10], [$payment->fresh()->status, $order->fresh()->status, $product->fresh()->stock]);
    }

    public function test_the_provider_is_registered_and_ready_with_bound_keys(): void
    {
        $method = PaymentMethod::query()->where('key', 'bancard')->firstOrFail();
        $registry = app(PaymentProviderRegistry::class);

        $this->assertInstanceOf(BancardProvider::class, $registry->for($method));
        $this->assertTrue($registry->isReady($method));
        $method->forceFill(['environment' => PaymentMethod::ENVIRONMENT_PRODUCTION, 'secrets' => ['staging' => $this->bancard->keys()]])->save();
        $this->assertSame([BancardProvider::MISSING_CREDENTIALS], $registry->missing($method->fresh()));
    }

    /**
     * A placed Bancard order for 2 units of a product with stock 10 (total Gs. 300.000).
     *
     * @return array{0: Order, 1: Payment, 2: Product}
     */
    private function bancardOrder(): array
    {
        $product = CheckoutFixtures::product(['price_amount' => 150000, 'stock' => 10]);
        $order = CheckoutFixtures::order([[$product, 2]], ['method' => 'bancard']);

        return [$order, $order->payments->first(), $product];
    }

    /** An attempt whose iframe was opened (process_id stored and known to the fake). */
    private function started(Payment $payment): Payment
    {
        $payment->forceFill(['process_id' => 'proc'.str_pad((string) $payment->id, 16, '0', STR_PAD_LEFT)])->save();
        $this->bancard->registerBuy($payment);

        return $payment->fresh();
    }

    /** The receipt block (data-payment-receipt) of an order page. */
    private function receipt(string $html): string
    {
        $start = strpos($html, 'data-payment-receipt');
        $this->assertNotFalse($start);

        return substr($html, $start, strpos($html, '</dl>', $start) - $start);
    }
}
