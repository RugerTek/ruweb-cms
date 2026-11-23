<?php

namespace Tests\Feature;

use App\Events\Shop\OrderPaid;
use App\Events\Shop\PaymentFailed;
use App\Events\Shop\StockShortfall;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Services\Shop\PaymentOperations;
use App\Shop\Payments\CancelResult;
use App\Shop\Payments\ManualPaymentProvider;
use App\Shop\Payments\PaymentNotification;
use App\ValueObjects\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Support\CheckoutFixtures;
use Tests\Support\FakePaymentProvider;
use Tests\TestCase;

/** Payment attempts, stock taken at payment and given back (PLAN.md E3.2, stage 3 track 3B). */
class PaymentOperationsTest extends TestCase
{
    use RefreshDatabase;

    private PaymentOperations $operations;

    private User $admin;

    private User $editor;

    private FakePaymentProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useCheckout();
        $this->provider = $this->useFakePaymentProvider();
        $this->operations = app(PaymentOperations::class);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->editor = User::factory()->create(['role' => 'editor']);
        Event::fake([OrderPaid::class, PaymentFailed::class, StockShortfall::class]);
    }

    public function test_admin_confirms_a_manual_payment_and_stock_of_products_and_variants_is_taken_once(): void
    {
        $simple = CheckoutFixtures::product(['stock' => 10]);
        $shirt = CheckoutFixtures::productWithVariants();
        $small = $shirt->variants->firstWhere('name', 'Talle S');
        $order = CheckoutFixtures::order([[$simple, 3], [$shirt, 2, $small], [$simple, 1]]);
        $payment = $order->payments->first();
        $this->assertSame(10, $simple->fresh()->stock, 'Placing the order does not touch stock (P3).');

        $confirmed = $this->operations->confirm($this->admin, $payment);
        $this->operations->confirm($this->admin, $payment);

        $this->assertSame(Payment::STATUS_CONFIRMED, $confirmed->status);
        $this->assertNotNull($confirmed->confirmed_at);
        $order->refresh();
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertNotNull($order->stock_applied_at);
        $this->assertNull($order->stock_shortfall);
        $this->assertSame(6, $simple->fresh()->stock, 'Two lines of the same product are taken together, once.');
        $this->assertSame(3, $small->fresh()->stock);
        $this->assertSame(5, $shirt->variants->firstWhere('name', 'Talle L')->fresh()->stock);
        Event::assertDispatchedTimes(OrderPaid::class, 1);
        Event::assertDispatched(OrderPaid::class, fn (OrderPaid $event) => $event->order->is($order) && $event->payment->is($payment));
        Event::assertNotDispatched(StockShortfall::class);
    }

    public function test_only_administrators_confirm_fail_or_refund_payments(): void
    {
        $product = CheckoutFixtures::product(['stock' => 4]);
        $order = CheckoutFixtures::order([[$product, 1]]);
        $payment = $order->payments->first();

        foreach ([
            fn () => $this->operations->confirm($this->editor, $payment),
            fn () => $this->operations->fail($this->editor, $payment),
            fn () => $this->operations->refund($this->editor, $payment),
        ] as $call) {
            try {
                $call();
                $this->fail('An editor must not move payments.');
            } catch (AuthorizationException) {
            }
        }

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertSame(4, $product->fresh()->stock);
        Event::assertNothingDispatched();
    }

    public function test_a_paid_order_without_enough_stock_is_accepted_with_a_shortfall(): void
    {
        $product = CheckoutFixtures::product(['title' => 'Termo', 'stock' => 1]);
        $other = CheckoutFixtures::product(['stock' => 5]);
        $order = CheckoutFixtures::order([[$product, 3], [$other, 2]], ['method' => FakePaymentProvider::KEY]);
        $payment = $order->payments->first();

        $this->operations->applyNotification($this->provider->notificationFor($payment), $payment);

        $order->refresh();
        $this->assertSame(Order::STATUS_PAID, $order->status, 'A charged payment is never rejected (decision 6.2).');
        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->fresh()->status);
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(3, $other->fresh()->stock);
        $expected = [['product_id' => $product->id, 'variant_id' => null, 'title' => 'Termo', 'requested' => 3, 'available' => 1]];
        $this->assertSame($expected, $order->stock_shortfall);
        Event::assertDispatchedTimes(StockShortfall::class, 1);
        Event::assertDispatched(StockShortfall::class, fn (StockShortfall $event) => $event->order->is($order) && $event->shortfall === $expected);
        Event::assertDispatchedTimes(OrderPaid::class, 1);

        // Refunding gives back what was taken (1), not what was requested (3).
        $this->operations->refund($this->admin, $payment->fresh());
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame(5, $other->fresh()->stock);
        $this->assertNull($order->fresh()->stock_applied_at);
    }

    public function test_variant_shortfall_names_the_variant_and_backorders_and_untracked_products_behave(): void
    {
        $shirt = CheckoutFixtures::productWithVariants([['name' => 'Talle M', 'price_amount' => 90000, 'stock' => 2]], ['title' => 'Remera']);
        $variant = $shirt->variants->first();
        $backorder = CheckoutFixtures::product(['stock' => 1, 'allow_backorder' => true]);
        $untracked = CheckoutFixtures::product(['stock' => 7, 'track_stock' => false]);
        $order = CheckoutFixtures::order([[$shirt, 5, $variant], [$backorder, 3], [$untracked, 2]]);

        $this->operations->confirm($this->admin, $order->payments->first());

        $this->assertSame(0, $variant->fresh()->stock);
        $this->assertSame(-2, $backorder->fresh()->stock, 'Backorders go negative (units owed) and are not a shortfall.');
        $this->assertSame(7, $untracked->fresh()->stock);
        $this->assertSame(
            [['product_id' => $shirt->id, 'variant_id' => $variant->id, 'title' => 'Remera (Talle M)', 'requested' => 5, 'available' => 2]],
            $order->fresh()->stock_shortfall,
        );
    }

    public function test_confirming_two_attempts_take_stock_and_mark_the_order_paid_once(): void
    {
        $product = CheckoutFixtures::product(['stock' => 10]);
        $order = CheckoutFixtures::order([[$product, 2]], ['method' => FakePaymentProvider::KEY]);
        $first = $order->payments->first();
        $second = CheckoutFixtures::payment($order);

        $this->operations->confirm(null, $first);
        $this->operations->confirm(null, $second);

        $this->assertSame(8, $product->fresh()->stock);
        $this->assertSame(Payment::STATUS_CONFIRMED, $second->fresh()->status);
        Event::assertDispatchedTimes(OrderPaid::class, 1);

        // Refunding one of two confirmed attempts keeps the order paid and its stock taken.
        $this->operations->refund($this->admin, $second->fresh());
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_fail_and_expire_never_touch_stock_and_fail_dispatches_once(): void
    {
        $product = CheckoutFixtures::product(['stock' => 5]);
        $order = CheckoutFixtures::order([[$product, 2]], ['method' => FakePaymentProvider::KEY]);
        $payment = $order->payments->first();

        $failed = $this->operations->fail($this->admin, $payment);
        $this->operations->fail($this->admin, $payment);
        $this->assertSame(Payment::STATUS_FAILED, $failed->status);
        $this->assertNotNull($failed->failed_at);
        Event::assertDispatchedTimes(PaymentFailed::class, 1);
        Event::assertDispatched(PaymentFailed::class, fn (PaymentFailed $event) => $event->reason === PaymentFailed::REASON_ADMIN);

        $retry = CheckoutFixtures::payment($order->fresh());
        $expired = $this->operations->expire($retry);
        $this->assertSame(Payment::STATUS_EXPIRED, $expired->status);
        $this->assertNotNull($expired->expired_at);

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertNull($order->fresh()->stock_applied_at);
        $this->assertSame(5, $product->fresh()->stock);
        Event::assertNotDispatched(OrderPaid::class);
    }

    public function test_invalid_payment_transitions_are_rejected_and_expire_leaves_settled_attempts_alone(): void
    {
        $order = CheckoutFixtures::order([[CheckoutFixtures::product(['stock' => 5]), 1]]);
        $payment = $this->operations->confirm($this->admin, $order->payments->first());

        $this->assertSame(Payment::STATUS_CONFIRMED, $this->operations->expire($payment)->status);
        try {
            $this->operations->fail($this->admin, $payment);
            $this->fail('A confirmed payment cannot fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment', $e->errors());
            $this->assertStringContainsString(__('checkout.payment_status_confirmed'), $e->errors()['payment'][0]);
        }

        $failed = $this->operations->fail($this->admin, CheckoutFixtures::payment($order->fresh()));
        $this->expectException(ValidationException::class);
        $this->operations->confirm($this->admin, $failed);
    }

    public function test_refund_through_the_provider_returns_stock_once_and_refunds_the_order(): void
    {
        $product = CheckoutFixtures::product(['stock' => 6]);
        $order = CheckoutFixtures::order([[$product, 2]], ['method' => FakePaymentProvider::KEY]);
        $payment = $this->operations->confirm(null, $order->payments->first());
        $this->assertSame(4, $product->fresh()->stock);

        $result = $this->operations->refund($this->admin, $payment);
        $again = $this->operations->refund($this->admin, $payment->fresh());

        $this->assertTrue($result->ok);
        $this->assertSame('fake_cancelled', $result->code);
        $this->assertTrue($again->ok);
        $this->assertSame(['cancel'], array_values(array_filter($this->provider->calledMethods(), fn ($m) => $m === 'cancel')), 'The provider is asked once.');
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->refunded_at);
        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->refunded_at);
        $this->assertSame(6, $product->fresh()->stock);
    }

    public function test_refund_that_the_provider_cannot_do_changes_nothing_until_marked_by_hand(): void
    {
        $product = CheckoutFixtures::product(['stock' => 6]);
        $order = CheckoutFixtures::order([[$product, 2]]);
        $payment = $this->operations->confirm($this->admin, $order->payments->first());

        $result = $this->operations->refund($this->admin, $payment);
        $this->assertFalse($result->ok);
        $this->assertTrue($result->manualRequired);
        $this->assertSame(ManualPaymentProvider::CODE_MANUAL_REFUND, $result->code);
        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->fresh()->status);
        $this->assertSame(4, $product->fresh()->stock);

        $this->assertTrue($this->operations->refund($this->admin, $payment, manual: true)->ok);
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->fresh()->status);
        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
        $this->assertSame(6, $product->fresh()->stock);
    }

    public function test_failed_provider_refund_keeps_everything_and_a_fulfilled_order_can_be_refunded(): void
    {
        $product = CheckoutFixtures::product(['stock' => 3]);
        $order = CheckoutFixtures::order([[$product, 1]], ['method' => FakePaymentProvider::KEY]);
        $payment = $this->operations->confirm(null, $order->payments->first());
        $order->forceFill(['status' => Order::STATUS_FULFILLED, 'fulfilled_at' => now()])->save();

        $this->provider->cancelResult = CancelResult::failed('PosCommunicationError');
        $this->assertFalse($this->operations->refund($this->admin, $payment)->ok);
        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->fresh()->status);
        $this->assertSame(Order::STATUS_FULFILLED, $order->fresh()->status);

        $this->provider->cancelResult = CancelResult::success('RollbackSuccessful');
        $this->assertTrue($this->operations->refund($this->admin, $payment)->ok);
        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
        $this->assertSame(3, $product->fresh()->stock);

        $pending = CheckoutFixtures::order([[$product, 1]]);
        $this->expectException(ValidationException::class);
        $this->operations->refund($this->admin, $pending->payments->first());
    }

    public function test_repeated_approved_notification_is_recorded_but_applied_once(): void
    {
        $product = CheckoutFixtures::product(['stock' => 5]);
        $order = CheckoutFixtures::order([[$product, 2]], ['method' => FakePaymentProvider::KEY]);
        $payment = $order->payments->first();
        $notification = new PaymentNotification(FakePaymentProvider::KEY, (string) $payment->id, true, $payment->amount, '00', 'Transaccion aprobada', '123456', 'T-9', ['reference' => (string) $payment->id]);

        $this->operations->applyNotification($notification, $payment);
        $this->operations->applyNotification($notification, $payment);

        $payment->refresh();
        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->status);
        $this->assertSame('123456', $payment->authorization_number);
        $this->assertSame('T-9', $payment->ticket_number);
        $this->assertSame('00', $payment->response_code);
        $this->assertSame('Transaccion aprobada', $payment->response_description);
        $this->assertSame(3, $product->fresh()->stock);
        Event::assertDispatchedTimes(OrderPaid::class, 1);
        $events = PaymentEvent::query()->where('payment_id', $payment->id)->orderBy('id')->get();
        $this->assertSame([PaymentEvent::TYPE_NOTIFICATION, PaymentEvent::TYPE_NOTIFICATION], $events->pluck('type')->all());
        $this->assertSame([Payment::STATUS_PENDING, Payment::STATUS_CONFIRMED], $events->pluck('payload_json.status_before')->all());
        $this->assertTrue($events->every(fn (PaymentEvent $event) => $event->token_valid === true));
    }

    public function test_notification_with_another_amount_fails_the_attempt_and_warns(): void
    {
        $product = CheckoutFixtures::product(['stock' => 5]);
        $order = CheckoutFixtures::order([[$product, 1]], ['method' => FakePaymentProvider::KEY]);
        $payment = $order->payments->first();
        $notification = new PaymentNotification(FakePaymentProvider::KEY, (string) $payment->id, true, Money::fromInt(1000, $payment->currency), '00');

        $result = $this->operations->applyNotification($notification, $payment);

        $this->assertSame(Payment::STATUS_FAILED, $result->status);
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertSame(PaymentEvent::TYPE_AMOUNT_MISMATCH, PaymentEvent::query()->where('payment_id', $payment->id)->value('type'));
        Event::assertDispatchedTimes(PaymentFailed::class, 1);
        Event::assertDispatched(PaymentFailed::class, fn (PaymentFailed $event) => $event->reason === PaymentFailed::REASON_AMOUNT_MISMATCH && $event->payment->is($payment));
        Event::assertNotDispatched(OrderPaid::class);

        // A notification without an amount is a mismatch too.
        $retry = CheckoutFixtures::payment($order->fresh());
        $this->operations->applyNotification(new PaymentNotification(FakePaymentProvider::KEY, (string) $retry->id, true), $retry);
        $this->assertSame(Payment::STATUS_FAILED, $retry->fresh()->status);
    }

    public function test_rejected_notification_fails_and_late_notifications_change_nothing(): void
    {
        $product = CheckoutFixtures::product(['stock' => 5]);
        $order = CheckoutFixtures::order([[$product, 1]], ['method' => FakePaymentProvider::KEY]);
        $payment = $order->payments->first();

        $failed = $this->operations->applyNotification($this->provider->notificationFor($payment, approved: false), $payment);
        $this->assertSame(Payment::STATUS_FAILED, $failed->status);
        $this->assertSame('51', $failed->response_code);
        Event::assertDispatched(PaymentFailed::class, fn (PaymentFailed $event) => $event->reason === PaymentFailed::REASON_REJECTED);

        $expired = $this->operations->expire(CheckoutFixtures::payment($order->fresh()));
        $late = $this->operations->applyNotification($this->provider->notificationFor($expired), $expired);
        $this->assertSame(Payment::STATUS_EXPIRED, $late->status);
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertSame(1, PaymentEvent::query()->where('payment_id', $expired->id)->count());
        Event::assertNotDispatched(OrderPaid::class);
    }

    public function test_events_wait_for_the_commit_and_a_rolled_back_confirmation_leaves_no_trace(): void
    {
        $product = CheckoutFixtures::product(['stock' => 5]);
        $order = CheckoutFixtures::order([[$product, 2]]);

        try {
            DB::transaction(function () use ($order) {
                $this->operations->confirm($this->admin, $order->payments->first());
                Event::assertNotDispatched(OrderPaid::class);

                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        Event::assertNotDispatched(OrderPaid::class);
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertSame(5, $product->fresh()->stock);
    }
}
