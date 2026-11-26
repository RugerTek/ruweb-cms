<?php

namespace Tests\Feature;

use App\Events\Shop\OrderCancelled;
use App\Events\Shop\OrderFulfilled;
use App\Events\Shop\OrderPaid;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Policies\OrderPolicy;
use App\Services\Shop\OrderOperations;
use App\Services\Shop\PaymentOperations;
use App\Shop\Payments\CancelResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Support\CheckoutFixtures;
use Tests\Support\FakePaymentProvider;
use Tests\TestCase;

/** Order status changes with an explicit actor (PLAN.md E3.2, stage 3 track 3B). */
class OrderOperationsTest extends TestCase
{
    use RefreshDatabase;

    private OrderOperations $operations;

    private User $admin;

    private User $editor;

    private FakePaymentProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useCheckout();
        $this->provider = $this->useFakePaymentProvider();
        $this->operations = app(OrderOperations::class);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->editor = User::factory()->create(['role' => 'editor']);
        Event::fake([OrderCancelled::class, OrderPaid::class, OrderFulfilled::class]);
    }

    public function test_policy_is_discovered_editors_see_and_fulfil_and_only_admins_move_money(): void
    {
        $order = CheckoutFixtures::order();
        $this->assertInstanceOf(OrderPolicy::class, Gate::getPolicyFor(Order::class));

        foreach (['viewAny' => Order::class, 'view' => $order, 'fulfill' => $order, 'note' => $order, 'resend' => $order] as $ability => $subject) {
            $this->assertTrue(Gate::forUser($this->editor)->allows($ability, $subject), $ability);
            $this->assertTrue(Gate::forUser($this->admin)->allows($ability, $subject), $ability);
        }
        foreach (['cancel', 'confirmPayment', 'failPayment', 'refund'] as $ability) {
            $this->assertFalse(Gate::forUser($this->editor)->allows($ability, $order), $ability);
            $this->assertTrue(Gate::forUser($this->admin)->allows($ability, $order), $ability);
        }
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->assertFalse(Gate::forUser($viewer)->allows('viewAny', Order::class));
        $this->assertFalse(Gate::forUser($viewer)->allows('fulfill', $order));
    }

    public function test_editor_marks_a_paid_order_fulfilled_idempotently(): void
    {
        $order = CheckoutFixtures::order();
        app(PaymentOperations::class)->confirm($this->admin, $order->payments->first());

        $fulfilled = $this->operations->transition($this->editor, $order, Order::STATUS_FULFILLED);
        $at = $fulfilled->fulfilled_at;
        $this->travel(5)->minutes();
        $again = $this->operations->fulfill($this->editor, $order);

        $this->assertSame(Order::STATUS_FULFILLED, $fulfilled->status);
        $this->assertSame(Order::STATUS_FULFILLED, $again->status);
        $this->assertEquals($at, $again->fulfilled_at);
        // The customer email goes out once: repeating the transition dispatches nothing.
        $this->operations->transition($this->editor, $order, Order::STATUS_FULFILLED);
        Event::assertDispatchedTimes(OrderFulfilled::class, 1);
        Event::assertDispatched(OrderFulfilled::class, fn (OrderFulfilled $event) => $event->order->is($order) && $event->order->status === Order::STATUS_FULFILLED);
    }

    public function test_fulfilled_event_waits_for_the_commit(): void
    {
        $order = CheckoutFixtures::order();
        app(PaymentOperations::class)->confirm($this->admin, $order->payments->first());

        try {
            DB::transaction(function () use ($order) {
                $this->operations->fulfill($this->editor, $order);
                Event::assertNotDispatched(OrderFulfilled::class);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        Event::assertNotDispatched(OrderFulfilled::class);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_editor_cannot_cancel_confirm_or_refund_through_transition(): void
    {
        $order = CheckoutFixtures::order();

        foreach ([Order::STATUS_CANCELLED, Order::STATUS_PAID, Order::STATUS_REFUNDED] as $status) {
            try {
                $this->operations->transition($this->editor, $order, $status);
                $this->fail('An editor must not move an order to '.$status);
            } catch (AuthorizationException) {
            }
        }
        try {
            $this->operations->cancel($this->editor, $order);
            $this->fail('An editor must not cancel.');
        } catch (AuthorizationException) {
        }

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertSame(Payment::STATUS_PENDING, $order->payments->first()->fresh()->status);
        Event::assertNothingDispatched();
    }

    public function test_invalid_transitions_are_rejected_with_the_current_status(): void
    {
        $order = CheckoutFixtures::order();

        foreach ([Order::STATUS_FULFILLED, Order::STATUS_REFUNDED, Order::STATUS_PENDING, 'shipped'] as $status) {
            try {
                $this->operations->transition($this->admin, $order, $status);
                $this->fail('pending → '.$status.' must be rejected');
            } catch (ValidationException $e) {
                $this->assertSame(__('checkout.transition_invalid', ['status' => __('checkout.status_pending')]), $e->errors()['status'][0]);
            }
        }

        $this->operations->cancel($this->admin, $order);
        foreach ([Order::STATUS_PAID, Order::STATUS_FULFILLED, Order::STATUS_REFUNDED] as $status) {
            try {
                $this->operations->transition($this->admin, $order, $status);
                $this->fail('cancelled → '.$status.' must be rejected');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
            }
        }
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
    }

    public function test_cancelling_a_pending_order_expires_its_attempt_keeps_stock_and_dispatches_once(): void
    {
        $product = CheckoutFixtures::product(['stock' => 4]);
        $order = CheckoutFixtures::order([[$product, 2]]);

        $cancelled = $this->operations->transition($this->admin, $order, Order::STATUS_CANCELLED);
        $this->operations->cancel($this->admin, $order);

        $this->assertSame(Order::STATUS_CANCELLED, $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame(Payment::STATUS_EXPIRED, $order->payments->first()->fresh()->status);
        $this->assertSame(4, $product->fresh()->stock);
        Event::assertDispatchedTimes(OrderCancelled::class, 1);
        Event::assertDispatched(OrderCancelled::class, fn (OrderCancelled $event) => $event->order->is($order) && $event->reason === OrderCancelled::REASON_ADMIN);
    }

    public function test_cancelling_a_paid_order_gives_its_stock_back_once(): void
    {
        $shirt = CheckoutFixtures::productWithVariants();
        $variant = $shirt->variants->first();
        $order = CheckoutFixtures::order([[$shirt, 2, $variant]]);
        $payment = app(PaymentOperations::class)->confirm($this->admin, $order->payments->first());
        $this->assertSame(3, $variant->fresh()->stock);

        $this->operations->cancel($this->admin, $order);
        $this->operations->cancel($this->admin, $order);

        $this->assertSame(5, $variant->fresh()->stock);
        $this->assertNull($order->fresh()->stock_applied_at);
        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->fresh()->status, 'The money is refunded separately.');
        Event::assertDispatchedTimes(OrderCancelled::class, 1);

        // Refunding the payment afterwards does not give the stock back twice.
        app(PaymentOperations::class)->refund($this->admin, $payment, manual: true);
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(5, $variant->fresh()->stock);
    }

    public function test_transition_to_paid_and_refunded_goes_through_the_payment(): void
    {
        $product = CheckoutFixtures::product(['stock' => 5]);
        $order = CheckoutFixtures::order([[$product, 1]], ['method' => FakePaymentProvider::KEY]);

        $paid = $this->operations->transition($this->admin, $order, Order::STATUS_PAID);
        $this->assertSame(Order::STATUS_PAID, $paid->status);
        $this->assertSame(Payment::STATUS_CONFIRMED, $order->payments->first()->fresh()->status);
        $this->assertSame(4, $product->fresh()->stock);
        Event::assertDispatchedTimes(OrderPaid::class, 1);
        $this->assertSame(Order::STATUS_PAID, $this->operations->transition($this->admin, $order, Order::STATUS_PAID)->status);

        $this->provider->cancelResult = CancelResult::failed('PosCommunicationError');
        try {
            $this->operations->transition($this->admin, $order, Order::STATUS_REFUNDED);
            $this->fail('A failed provider refund must be reported.');
        } catch (ValidationException $e) {
            $this->assertSame(__('checkout.bancard_errors.PosCommunicationError'), $e->errors()['status'][0]);
        }
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);

        $this->provider->cancelResult = CancelResult::success('RollbackSuccessful');
        $refunded = $this->operations->transition($this->admin, $order, Order::STATUS_REFUNDED);
        $this->assertSame(Order::STATUS_REFUNDED, $refunded->status);
        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_refund_of_a_manual_payment_through_transition_explains_it_is_done_by_hand(): void
    {
        $order = CheckoutFixtures::order();
        $this->operations->transition($this->admin, $order, Order::STATUS_PAID);

        try {
            $this->operations->transition($this->admin, $order, Order::STATUS_REFUNDED);
            $this->fail('A manual refund must be reported.');
        } catch (ValidationException $e) {
            $this->assertSame(__('checkout.refund_manual_required'), $e->errors()['status'][0]);
        }
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_internal_notes_are_kept_by_any_role_and_cleared_when_blank(): void
    {
        $order = CheckoutFixtures::order();

        $this->assertSame('Llamar antes de entregar', $this->operations->note($this->editor, $order, '  Llamar antes de entregar ')->internal_notes);
        $this->assertNull($this->operations->note($this->admin, $order, '   ')->internal_notes);

        $this->expectException(ValidationException::class);
        $this->operations->note($this->admin, $order, str_repeat('a', 10001));
    }

    public function test_unpaid_automatic_orders_are_cancelled_after_the_configured_hours(): void
    {
        config(['cms.shop.checkout.unpaid_order_hours' => 48]);
        $product = CheckoutFixtures::product(['stock' => 9]);
        $old = now()->subHours(49);
        $abandoned = CheckoutFixtures::order([[$product, 1]], ['method' => FakePaymentProvider::KEY, 'placed_at' => $old]);
        $inFlight = CheckoutFixtures::order([[$product, 1]], ['method' => FakePaymentProvider::KEY, 'placed_at' => $old]);
        $inFlight->payments->first()->forceFill(['process_id' => 'fake-started'])->save();
        $recent = CheckoutFixtures::order([[$product, 1]], ['method' => FakePaymentProvider::KEY, 'placed_at' => now()->subHours(47)]);
        $manual = CheckoutFixtures::order([[$product, 1]], ['placed_at' => $old]);
        $paid = CheckoutFixtures::order([[$product, 1]], ['method' => FakePaymentProvider::KEY, 'placed_at' => $old]);
        app(PaymentOperations::class)->confirm(null, $paid->payments->first());

        $this->assertSame(1, $this->operations->cancelUnpaid());
        $this->assertSame(0, $this->operations->cancelUnpaid());

        $this->assertSame(Order::STATUS_CANCELLED, $abandoned->fresh()->status);
        $this->assertSame(Payment::STATUS_EXPIRED, $abandoned->payments->first()->fresh()->status);
        $this->assertSame(Order::STATUS_PENDING, $inFlight->fresh()->status, 'Left for the provider sweep.');
        $this->assertSame(Order::STATUS_PENDING, $recent->fresh()->status);
        $this->assertSame(Order::STATUS_PENDING, $manual->fresh()->status, 'Manual methods are cancelled by the administrator.');
        $this->assertSame(Order::STATUS_PAID, $paid->fresh()->status);
        $this->assertSame(8, $product->fresh()->stock);
        Event::assertDispatchedTimes(OrderCancelled::class, 1);
        Event::assertDispatched(OrderCancelled::class, fn (OrderCancelled $event) => $event->order->is($abandoned) && $event->reason === OrderCancelled::REASON_UNPAID);
    }
}
