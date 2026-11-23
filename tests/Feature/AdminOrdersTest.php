<?php

namespace Tests\Feature;

use App\Mail\Shop\OrderPlacedCustomer;
use App\Models\Locale;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Shop\Payments\Bancard\BancardProvider;
use App\Shop\Payments\CancelResult;
use App\Shop\Payments\PaymentNotification;
use App\Support\SettingDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CheckoutFixtures;
use Tests\Support\FakePaymentProvider;
use Tests\TestCase;

/** Orders panel: list, detail and the hand-driven moves, Full and Essential, admin vs editor (PLAN.md E3.6, track 3D1). */
class AdminOrdersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $editor;

    private FakePaymentProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Locale::query()->updateOrCreate(['code' => 'es'], ['name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
        $this->useCheckout();
        $this->provider = $this->useFakePaymentProvider();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->editor = User::factory()->create(['role' => 'editor']);
        Mail::fake();
    }

    public function test_list_filters_by_status_method_and_date_in_full_and_essential(): void
    {
        $transfer = CheckoutFixtures::order([[CheckoutFixtures::product(), 2]], ['customer_name' => 'Ana Transfer']);
        $cod = CheckoutFixtures::order([], ['method' => 'cod', 'customer_name' => 'Beto Efectivo', 'status' => Order::STATUS_PAID]);
        $old = CheckoutFixtures::order([], ['customer_name' => 'Carla Vieja', 'placed_at' => now()->subDays(40)]);

        foreach (['full', 'essential'] as $profile) {
            $this->useAdminProfile($profile);
            foreach ([$this->admin, $this->editor] as $user) {
                $this->actingAs($user)->get('/admin/orders')->assertOk()
                    ->assertSee($transfer->number)->assertSee($cod->number)->assertSee($old->number)
                    ->assertSee('Gs. 300.000')->assertSee(__('checkout.method_bank_transfer'))->assertSee(__('checkout.status_paid'))
                    ->assertSee(route('admin.orders.show', $transfer));
            }
        }

        $this->actingAs($this->admin);
        $this->get('/admin/orders?status=paid')->assertOk()->assertSee('Beto Efectivo')->assertDontSee('Ana Transfer');
        $this->get('/admin/orders?method=bank_transfer')->assertOk()->assertSee('Ana Transfer')->assertSee('Carla Vieja')->assertDontSee('Beto Efectivo');
        $this->get('/admin/orders?from='.now()->subDays(2)->format('Y-m-d'))->assertOk()->assertSee('Ana Transfer')->assertDontSee('Carla Vieja');
        $this->get('/admin/orders?to='.now()->subDays(30)->format('Y-m-d'))->assertOk()->assertSee('Carla Vieja')->assertDontSee('Ana Transfer');
        $this->get('/admin/orders?status=refunded')->assertOk()->assertSee(__('checkout.orders_none_found'));
        // Junk filters are ignored, not errors.
        $this->get('/admin/orders?status=nope&method=nope&from=ayer&to=2026-99-99')->assertOk()->assertSee('Ana Transfer');
    }

    public function test_empty_list_guest_redirect_and_404_with_the_catalog_off(): void
    {
        $this->actingAs($this->admin)->get('/admin/orders')->assertOk()->assertSee(__('checkout.orders_empty'));

        $order = CheckoutFixtures::order();
        $this->useShop('off');
        $this->get('/admin/orders')->assertNotFound();
        $this->get('/admin/orders/'.$order->id)->assertNotFound();

        $this->useShop('catalog');
        $this->get('/admin/orders/'.$order->id)->assertOk(); // orders stay manageable after leaving checkout

        auth()->logout();
        $this->get('/admin/orders')->assertNotFound(); // guests never learn the panel exists
        $this->get('/admin/orders/'.$order->id)->assertNotFound();
    }

    public function test_detail_shows_items_delivery_and_payments_without_secrets(): void
    {
        $zone = CheckoutFixtures::shippingZone('Gran Asunción', 30000);
        $order = CheckoutFixtures::order([[CheckoutFixtures::product(['title' => 'Mate de calabaza']), 2]], ['zone' => $zone, 'notes' => 'Tocar timbre']);
        $token = $order->plainAccessToken();
        $payment = $order->payments->first();
        PaymentEvent::create(['payment_id' => $payment->id, 'provider' => 'bancard', 'type' => PaymentEvent::TYPE_NOTIFICATION, 'token_valid' => true, 'payload_json' => [
            'operation' => ['token' => 'md5-token-que-no-se-muestra', 'response' => 'N', 'response_code' => '51', 'response_description' => 'Fondos insuficientes', 'security_information' => ['card_source' => 'L']],
            'status_before' => 'pending',
        ]]);
        PaymentMethod::query()->where('key', 'bancard')->firstOrFail()->forceFill(['secrets' => ['staging' => ['public_key' => 'pub', 'private_key' => 'clave-privada-secreta']]])->save();

        foreach (['full', 'essential'] as $profile) {
            $this->useAdminProfile($profile);
            $response = $this->actingAs($this->admin)->get('/admin/orders/'.$order->id)->assertOk()
                ->assertSee($order->number)->assertSee('Mate de calabaza')->assertSee('Gs. 300.000')->assertSee('Gs. 30.000')->assertSee('Gs. 330.000')
                ->assertSee('Ana Pérez')->assertSee('ana@example.com')->assertSee('0981 123 456')->assertSee('Tocar timbre')
                ->assertSee('Gran Asunción')->assertSee('Av. Mariscal López 1234')->assertSee('Portón verde')
                ->assertSee(__('checkout.payment_attempts'))->assertSee(__('checkout.payment_status_pending'))
                ->assertSee('Fondos insuficientes')->assertSee(__('checkout.event_rejected'))
                ->assertSee(route('admin.payments.confirm', $payment));
            $html = $response->getContent();
            foreach ([$token, $order->access_token_hash, 'md5-token-que-no-se-muestra', 'clave-privada-secreta', 'card_source'] as $secret) {
                $this->assertStringNotContainsString($secret, $html);
            }
        }
    }

    public function test_admin_confirms_a_manual_payment_and_editor_cannot_touch_money(): void
    {
        $product = CheckoutFixtures::product(['stock' => 10]);
        $order = CheckoutFixtures::order([[$product, 3]]);
        $payment = $order->payments->first();

        $this->actingAs($this->editor)->get('/admin/orders/'.$order->id)->assertOk()
            ->assertDontSee(route('admin.payments.confirm', $payment))->assertDontSee(__('checkout.cancel_order'));
        $this->post(route('admin.payments.confirm', $payment))->assertForbidden();
        $this->post(route('admin.payments.fail', $payment))->assertForbidden();
        $this->post(route('admin.orders.transition', $order), ['status' => 'cancelled'])->assertForbidden();
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);

        $this->actingAs($this->admin)->post(route('admin.payments.confirm', $payment))
            ->assertRedirect(route('admin.orders.show', $order))->assertSessionHas('success', __('checkout.payment_confirmed_flash'));
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->fresh()->status);
        $this->assertSame(7, $product->fresh()->stock);

        // Repeating is harmless; a confirmed manual payment is only "marked as refunded" by hand.
        $this->post(route('admin.payments.confirm', $payment))->assertRedirect();
        $this->assertSame(7, $product->fresh()->stock);
        $this->get('/admin/orders/'.$order->id)->assertSee(route('admin.payments.refund-manual', $payment))->assertDontSee(route('admin.payments.refund', $payment).'"', false);
    }

    public function test_admin_fails_a_manual_payment(): void
    {
        $order = CheckoutFixtures::order();
        $payment = $order->payments->first();

        $this->actingAs($this->admin)->post(route('admin.payments.fail', $payment))->assertSessionHas('success', __('checkout.payment_failed_flash'));
        $this->assertSame(Payment::STATUS_FAILED, $payment->fresh()->status);
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertSame([], $this->provider->calls);
    }

    public function test_editor_marks_delivered_keeps_notes_and_resends_but_cannot_cancel(): void
    {
        Setting::query()->firstOrCreate(['key' => 'mail_enabled'], SettingDefaults::for('mail_enabled'));
        Setting::set('mail_enabled', '1');
        $order = CheckoutFixtures::order([], ['status' => Order::STATUS_PAID]);
        $pending = CheckoutFixtures::order();

        foreach (['full', 'essential'] as $profile) {
            $this->useAdminProfile($profile);
            $this->actingAs($this->editor)->get('/admin/orders/'.$order->id)->assertOk()
                ->assertSee(__('checkout.mark_fulfilled'))->assertSee(__('checkout.resend_email'))->assertSee(__('checkout.internal_notes'))
                ->assertDontSee(__('checkout.cancel_order'));
        }

        $this->post(route('admin.orders.note', $order), ['internal_notes' => '  Llamar antes de ir  '])->assertSessionHas('success', __('checkout.note_saved'));
        $this->assertSame('Llamar antes de ir', $order->fresh()->internal_notes);
        $this->post(route('admin.orders.note', $order), ['internal_notes' => str_repeat('a', 10001)])->assertSessionHasErrors('internal_notes');

        $this->post(route('admin.orders.resend', $order))->assertSessionHas('success', __('checkout.email_resent'));
        Mail::assertQueued(OrderPlacedCustomer::class, fn ($mail) => $mail->hasTo('ana@example.com'));
        Setting::set('mail_enabled', '0');
        $this->post(route('admin.orders.resend', $order))->assertSessionHas('error', __('checkout.email_resend_failed'));

        $this->post(route('admin.orders.transition', $order), ['status' => 'cancelled'])->assertForbidden();
        $this->post(route('admin.orders.transition', $order), ['status' => 'paid'])->assertSessionHasErrors('status');
        $this->post(route('admin.orders.transition', $order), ['status' => 'fulfilled'])
            ->assertSessionHas('success', __('checkout.transition_done', ['status' => __('checkout.status_fulfilled')]));
        $this->assertSame(Order::STATUS_FULFILLED, $order->fresh()->status);

        // Not deliverable again: the button is gone and the move is refused with an explanation.
        $this->get('/admin/orders/'.$order->id)->assertDontSee(__('checkout.mark_fulfilled'));
        $this->from('/admin/orders/'.$order->id)->post(route('admin.orders.transition', $pending), ['status' => 'fulfilled'])->assertSessionHasErrors('status');
    }

    public function test_admin_cancels_a_manual_order_and_a_paid_one_gets_its_stock_back(): void
    {
        $product = CheckoutFixtures::product(['stock' => 10]);
        $pending = CheckoutFixtures::order([[$product, 1]]);
        $this->actingAs($this->admin)->post(route('admin.orders.transition', $pending), ['status' => 'cancelled'])->assertSessionHas('success');
        $this->assertSame(Order::STATUS_CANCELLED, $pending->fresh()->status);
        $this->assertSame(Payment::STATUS_EXPIRED, $pending->payments()->first()->status);

        $paid = CheckoutFixtures::order([[$product, 4]]);
        $this->post(route('admin.payments.confirm', $paid->payments->first()));
        $this->assertSame(6, $product->fresh()->stock);
        $this->post(route('admin.orders.transition', $paid), ['status' => 'cancelled'])->assertSessionHas('success');
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame([], $this->provider->calls, 'Manual methods never call a provider.');
    }

    public function test_verify_payment_asks_the_provider_and_applies_only_a_verified_answer(): void
    {
        $product = CheckoutFixtures::product(['stock' => 10]);
        $order = CheckoutFixtures::order([[$product, 2]], ['method' => FakePaymentProvider::KEY]);
        $payment = $order->payments->first();
        $payment->forceFill(['process_id' => 'fake-'.$payment->id])->save();
        $this->actingAs($this->admin);

        $this->get('/admin/orders/'.$order->id)->assertSee(__('checkout.verify_payment'))->assertDontSee(__('checkout.confirm_payment').'</button>', false);

        // Not paid yet: nothing changes.
        $this->post(route('admin.payments.confirm', $payment))->assertSessionHas('error', __('checkout.payment_still_pending'));
        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);

        // An answer that does not verify is not applied.
        $forged = $this->provider->notificationFor($payment);
        $this->provider->queryResult = new PaymentNotification(FakePaymentProvider::KEY, (string) $payment->id, true, $payment->amount, '00', payload: ['signature' => 'forged'] + $forged->payload);
        $this->post(route('admin.payments.confirm', $payment))->assertSessionHasErrors('payment');
        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);

        $this->provider->queryResult = $this->provider->notificationFor($payment);
        $this->post(route('admin.payments.confirm', $payment))->assertSessionHas('success', __('checkout.payment_confirmed_flash'));
        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->fresh()->status);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertSame(['query', 'query', 'verify', 'query', 'verify'], $this->provider->calledMethods());
        $this->assertNotContains('cancel', $this->provider->calledMethods());
    }

    public function test_cancelling_with_an_online_payment_in_progress_reverses_it_or_refuses_when_already_paid(): void
    {
        $product = CheckoutFixtures::product(['stock' => 10]);
        $this->actingAs($this->admin);

        // Not paid at the provider: reversed there, then cancelled.
        $unpaid = $this->onlineOrder($product);
        $this->post(route('admin.orders.transition', $unpaid), ['status' => 'cancelled'])->assertSessionHas('success');
        $this->assertSame(Order::STATUS_CANCELLED, $unpaid->fresh()->status);
        $this->assertSame(['query', 'cancel'], $this->provider->calledMethods());

        // The reversal fails: nothing is cancelled and the reason is shown.
        $this->provider->calls = [];
        $this->provider->cancelResult = CancelResult::failed('PosCommunicationError');
        $stuck = $this->onlineOrder($product);
        $this->from('/admin/orders/'.$stuck->id)->post(route('admin.orders.transition', $stuck), ['status' => 'cancelled'])
            ->assertSessionHasErrors(['payment' => __('checkout.provider_error', ['reason' => __('checkout.bancard_errors.PosCommunicationError')])]);
        $this->assertSame(Order::STATUS_PENDING, $stuck->fresh()->status);
        $this->assertSame(Payment::STATUS_PENDING, $stuck->payments()->first()->status);

        // The customer had paid: the payment is applied, the order is not cancelled silently and refund is offered.
        $this->provider->calls = [];
        $this->provider->cancelResult = CancelResult::success('fake_cancelled');
        $paid = $this->onlineOrder($product);
        $payment = $paid->payments()->first();
        $this->provider->queryResult = $this->provider->notificationFor($payment);
        $this->post(route('admin.orders.transition', $paid), ['status' => 'cancelled'])
            ->assertRedirect(route('admin.orders.show', $paid))->assertSessionHas('error', __('checkout.cancel_blocked_paid'));
        $this->assertSame(Order::STATUS_PAID, $paid->fresh()->status);
        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->fresh()->status);
        $this->assertNotContains('cancel', $this->provider->calledMethods());
        $this->get('/admin/orders/'.$paid->id)->assertSee(route('admin.payments.refund', $payment))->assertSee(__('checkout.refund_payment'));
    }

    public function test_refund_through_the_provider_or_by_hand_when_it_cannot(): void
    {
        $product = CheckoutFixtures::product(['stock' => 10]);
        $this->actingAs($this->admin);

        $order = $this->onlineOrder($product, 2);
        $payment = $order->payments()->first();
        $this->provider->queryResult = $this->provider->notificationFor($payment);
        $this->post(route('admin.payments.confirm', $payment));
        $this->assertSame(8, $product->fresh()->stock);

        $this->provider->cancelResult = CancelResult::manual('TransactionAlreadyConfirmed');
        $this->post(route('admin.payments.refund', $payment))->assertSessionHas('error', __('checkout.refund_manual_required'));
        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->fresh()->status);
        $this->assertSame(8, $product->fresh()->stock);

        $this->actingAs($this->editor)->post(route('admin.payments.refund-manual', $payment))->assertForbidden();
        $this->actingAs($this->admin)->post(route('admin.payments.refund-manual', $payment))->assertSessionHas('success', __('checkout.payment_refunded_flash'));
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->fresh()->status);
        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
        $this->assertSame(10, $product->fresh()->stock);

        $other = $this->onlineOrder($product);
        $otherPayment = $other->payments()->first();
        $this->provider->queryResult = $this->provider->notificationFor($otherPayment);
        $this->post(route('admin.payments.confirm', $otherPayment));
        $this->provider->cancelResult = CancelResult::success('RollbackSuccessful');
        $this->post(route('admin.payments.refund', $otherPayment))->assertSessionHas('success', __('checkout.payment_refunded_flash'));
        $this->assertSame(Order::STATUS_REFUNDED, $other->fresh()->status);
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_payment_charged_late_is_flagged_for_review(): void
    {
        $order = CheckoutFixtures::order([], ['customer_name' => 'Dora Tarde']);
        $payment = $order->payments->first();
        $payment->forceFill(['status' => Payment::STATUS_EXPIRED, 'expired_at' => now()])->save();
        // What PaymentOperations::applyNotification() records for an approval that arrives after the attempt expired.
        PaymentEvent::create(['payment_id' => $payment->id, 'provider' => 'bancard', 'type' => PaymentEvent::TYPE_NOTIFICATION, 'token_valid' => true, 'payload_json' => [
            'operation' => ['response' => 'S', 'response_code' => '00', 'response_description' => 'Transacción aprobada', 'token' => 'x'],
            'status_before' => Payment::STATUS_EXPIRED,
        ]]);
        $onTime = CheckoutFixtures::order([], ['customer_name' => 'Eva Normal']);
        PaymentEvent::create(['payment_id' => $onTime->payments->first()->id, 'provider' => 'bancard', 'type' => PaymentEvent::TYPE_NOTIFICATION, 'token_valid' => true, 'payload_json' => [
            'operation' => ['response' => 'S', 'response_code' => '00'], 'status_before' => Payment::STATUS_PENDING,
        ]]);

        // Bancard (track 3C2) records what it did about it: reversed → nothing to review; could not → review.
        $reversed = CheckoutFixtures::order([], ['customer_name' => 'Fede Devuelto']);
        $reversedPayment = $reversed->payments->first();
        $reversedPayment->forceFill(['status' => Payment::STATUS_FAILED, 'failed_at' => now()])->save();
        $review = CheckoutFixtures::order([], ['customer_name' => 'Gabi Revisar']);
        $reviewPayment = $review->payments->first();
        $reviewPayment->forceFill(['status' => Payment::STATUS_EXPIRED, 'expired_at' => now()])->save();
        foreach ([$reversedPayment, $reviewPayment] as $late) {
            PaymentEvent::create(['payment_id' => $late->id, 'provider' => 'bancard', 'type' => PaymentEvent::TYPE_NOTIFICATION, 'token_valid' => true, 'payload_json' => [
                'operation' => ['response' => 'S', 'response_code' => '00'], 'status_before' => $late->status,
            ]]);
        }
        PaymentEvent::create(['payment_id' => $reversedPayment->id, 'provider' => 'bancard', 'type' => BancardProvider::EVENT_LATE_PAYMENT_REVERSED, 'token_valid' => true, 'payload_json' => ['status' => 'failed', 'code' => 'RollbackSuccessful', 'manual_required' => false]]);
        PaymentEvent::create(['payment_id' => $reviewPayment->id, 'provider' => 'bancard', 'type' => BancardProvider::EVENT_LATE_PAYMENT_REVIEW, 'token_valid' => true, 'payload_json' => ['status' => 'expired', 'code' => 'TransactionAlreadyConfirmed', 'manual_required' => true]]);

        $index = $this->actingAs($this->editor)->get('/admin/orders')->assertOk()->assertSee(__('checkout.late_payment_badge'))->getContent();
        $this->assertSame(2, substr_count($index, 'data-late-payment'));
        $this->get('/admin/orders/'.$review->id)->assertOk()->assertSee(__('checkout.late_payment_alert'))->assertSee('TransactionAlreadyConfirmed');
        $this->get('/admin/orders/'.$reversed->id)->assertOk()->assertDontSee(__('checkout.late_payment_alert'))->assertSee('RollbackSuccessful');
        $this->get('/admin/orders/'.$order->id)->assertOk()->assertSee(__('checkout.late_payment_alert'))->assertSee(__('checkout.event_approved'));
        $this->get('/admin/orders/'.$onTime->id)->assertOk()->assertDontSee(__('checkout.late_payment_alert'));
    }

    public function test_admin_marks_a_charge_left_for_review_as_resolved_with_a_note(): void
    {
        $review = CheckoutFixtures::order([], ['customer_name' => 'Gabi Revisar']);
        $payment = $review->payments->first();
        $payment->forceFill(['status' => Payment::STATUS_FAILED, 'failed_at' => now()])->save();
        PaymentEvent::create(['payment_id' => $payment->id, 'provider' => 'bancard', 'type' => BancardProvider::EVENT_LATE_PAYMENT_REVIEW, 'token_valid' => true, 'payload_json' => ['status' => 'failed', 'code' => 'TransactionAlreadyConfirmed', 'manual_required' => true, 'reason' => 'amount_mismatch']]);
        // A provider that records no reversal: the late approval alone flags it, and it can be resolved too.
        $plain = CheckoutFixtures::order([], ['customer_name' => 'Hugo Tarde']);
        $plainPayment = $plain->payments->first();
        $plainPayment->forceFill(['status' => Payment::STATUS_EXPIRED, 'expired_at' => now()])->save();
        PaymentEvent::create(['payment_id' => $plainPayment->id, 'provider' => 'fake', 'type' => PaymentEvent::TYPE_NOTIFICATION, 'token_valid' => true, 'payload_json' => ['approved' => true, 'status_before' => Payment::STATUS_EXPIRED]]);
        $url = route('admin.payments.resolve', $payment);

        // Editors see the alert but not the action, and cannot post it.
        $this->actingAs($this->editor)->get('/admin/orders/'.$review->id)->assertOk()
            ->assertSee(__('checkout.late_payment_alert'))->assertDontSee('data-late-payment-resolve', false);
        $this->post($url, ['note' => 'x'])->assertForbidden();

        $this->actingAs($this->admin)->get('/admin/orders/'.$review->id)->assertOk()
            ->assertSee('data-late-payment-resolve', false)
            ->assertSee($url, false)
            ->assertSee(e(__('checkout.late_payment_resolve_confirm')), false);

        // A note is required.
        $this->from('/admin/orders/'.$review->id)->post($url, ['note' => '   '])->assertSessionHasErrors('note');
        $this->assertTrue(BancardProvider::needsLateReview($payment));

        $this->post($url, ['note' => 'Devuelto desde el portal de Bancard, ticket 4512'])
            ->assertRedirect(route('admin.orders.show', $review))
            ->assertSessionHas('success', __('checkout.late_payment_resolved_flash'));
        $event = PaymentEvent::query()->where('type', BancardProvider::EVENT_LATE_PAYMENT_RESOLVED)->sole();
        $this->assertSame(['note' => 'Devuelto desde el portal de Bancard, ticket 4512', 'user_id' => $this->admin->id, 'user' => $this->admin->name], $event->payload_json);
        $this->assertFalse(BancardProvider::needsLateReview($payment));
        $this->assertSame([Payment::STATUS_FAILED, Order::STATUS_PENDING], [$payment->fresh()->status, $review->fresh()->status]);

        $this->get('/admin/orders/'.$review->id)->assertOk()
            ->assertDontSee(__('checkout.late_payment_alert'))
            ->assertDontSee('data-late-payment-resolve', false)
            ->assertSee(__('checkout.event_type_late_payment_resolved'))
            ->assertSee('Devuelto desde el portal de Bancard, ticket 4512');

        // Twice is refused; an attempt with nothing to review too.
        $this->from('/admin/orders/'.$review->id)->post($url, ['note' => 'otra vez'])->assertSessionHasErrors('payment');
        $onTime = CheckoutFixtures::order();
        $this->post(route('admin.payments.resolve', $onTime->payments->first()), ['note' => 'nada'])->assertSessionHasErrors('payment');
        $this->assertSame(1, PaymentEvent::query()->where('type', BancardProvider::EVENT_LATE_PAYMENT_RESOLVED)->count());

        $index = $this->get('/admin/orders')->assertOk()->getContent();
        $this->assertSame(1, substr_count($index, 'data-late-payment'), 'Only the provider without reversals is still flagged.');
        $this->post(route('admin.payments.resolve', $plainPayment), ['note' => 'Revisado con el proveedor'])->assertSessionHasNoErrors();
        $this->get('/admin/orders')->assertOk()->assertDontSee('data-late-payment', false);
    }

    private function onlineOrder(Product $product, int $quantity = 1): Order
    {
        $order = CheckoutFixtures::order([[$product, $quantity]], ['method' => FakePaymentProvider::KEY]);
        $payment = $order->payments->first();
        $payment->forceFill(['process_id' => 'fake-'.$payment->id])->save();
        $this->provider->queryResult = null;

        return $order;
    }
}
