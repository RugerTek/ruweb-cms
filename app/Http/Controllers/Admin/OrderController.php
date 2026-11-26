<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Listeners\Shop\SendOrderMails;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentMethod;
use App\Services\Shop\OrderOperations;
use App\Shop\Payments\Bancard\BancardProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Orders panel (PLAN.md E3.6, track 3D1), the same in Full and Essential: list with filters, the order with its
 * items, delivery, payment attempts and what the provider reported (summarised, never raw payloads, tokens or
 * keys), and the hand-driven moves. Thin over OrderOperations (and PaymentController for money), which MCP shares;
 * every action authorizes through OrderPolicy: editors see orders, mark them delivered, keep notes and resend the
 * customer email; only administrators cancel or touch payments.
 */
class OrderController extends Controller
{
    /** Order statuses the panel moves through this route; payments go through PaymentController. */
    private const PANEL_TRANSITIONS = [Order::STATUS_FULFILLED, Order::STATUS_CANCELLED];

    /** What was done about a stray charge: reversed, left for a person to review, or marked resolved by one. */
    private const LATE_EVENTS = BancardProvider::LATE_PAYMENT_EVENTS;

    public function __construct(private readonly OrderOperations $orders) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Order::class);

        $methods = PaymentMethod::query()->orderBy('sort_order')->orderBy('id')->get();
        $filters = [
            'status' => in_array($request->input('status'), Order::STATUSES, true) ? $request->input('status') : null,
            'method' => $methods->contains('key', $request->input('method')) ? $request->input('method') : null,
            'from' => $this->date($request->input('from')),
            'to' => $this->date($request->input('to')),
        ];

        $query = Order::query()->with('latestPayment')->latest('placed_at')->latest('id');
        if ($filters['method'] !== null) {
            $query->where('payment_method_key', $filters['method']);
        }
        if ($filters['from'] !== null) {
            $query->where('placed_at', '>=', $filters['from']->copy()->startOfDay());
        }
        if ($filters['to'] !== null) {
            $query->where('placed_at', '<=', $filters['to']->copy()->endOfDay());
        }
        $counts = (clone $query)->reorder()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        if ($filters['status'] !== null) {
            $query->where('status', $filters['status']);
        }
        $orders = $query->paginate(20)->withQueryString();

        return view('admin.orders.index', [
            'orders' => $orders,
            'counts' => $counts,
            'totalCount' => (int) $counts->sum(),
            'hasOrders' => Order::query()->exists(),
            'filters' => $filters,
            'methods' => $methods,
            'methodLabels' => $this->methodLabels($methods),
            'lateOrderIds' => $this->latePayments($orders->getCollection()->pluck('id')->all())->keys()->all(),
        ]);
    }

    public function show(Order $order): View
    {
        $this->authorize('view', $order);

        $order->load(['items', 'shippingAddress', 'payments.events']);
        $methods = PaymentMethod::query()->get();

        return view('admin.orders.show', [
            'order' => $order,
            'methodLabels' => $this->methodLabels($methods),
            'latePaymentIds' => $this->latePayments([$order->id])->flatten()->all(),
        ]);
    }

    /**
     * Mark delivered or cancel. Cancelling an order whose online attempt already reached the provider first asks it
     * and reverses it there (PaymentController::settle()); when the customer had paid, the order is left paid and
     * the administrator is offered the refund instead of a silent cancellation.
     */
    public function transition(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('view', $order);
        $status = $request->validate(['status' => ['required', 'string', Rule::in(self::PANEL_TRANSITIONS)]])['status'];

        if ($status === Order::STATUS_CANCELLED) {
            $this->authorize('cancel', $order);
            $settle = app(PaymentController::class);
            $pending = $order->payments()->where('status', Payment::STATUS_PENDING)->whereNotNull('process_id')->get();
            foreach ($pending as $payment) {
                if ($settle->settle($payment, rollbackUnpaid: true) === PaymentController::SETTLED_PAID) {
                    return redirect()->route('admin.orders.show', $order)->with('error', __('checkout.cancel_blocked_paid'));
                }
            }
        }

        $order = $this->orders->transition($request->user(), $order, $status);

        return redirect()->route('admin.orders.show', $order)
            ->with('success', __('checkout.transition_done', ['status' => __('checkout.status_'.$order->status)]));
    }

    public function note(Request $request, Order $order): RedirectResponse
    {
        $this->orders->note($request->user(), $order, $request->input('internal_notes'));

        return redirect()->route('admin.orders.show', $order)->with('success', __('checkout.note_saved'));
    }

    /** The order confirmation, with its link, to the customer again. */
    public function resend(Order $order): RedirectResponse
    {
        $this->authorize('resend', $order);

        $sent = app(SendOrderMails::class)->resend($order);

        return redirect()->route('admin.orders.show', $order)
            ->with($sent ? 'success' : 'error', $sent ? __('checkout.email_resent') : __('checkout.email_resend_failed'));
    }

    /**
     * Payments charged late, to review (track 3C2 decision): an approval the provider reported for an attempt that
     * had already failed or expired. When the provider recorded what it did about it (Bancard:
     * BancardProvider::LATE_PAYMENT_EVENTS: reversed, review or resolved, see needsLateReview()), the latest of those
     * decides: only "review" (it could not be reversed and nobody marked it resolved yet) is flagged. Without such a record, any approval
     * recorded over a failed or expired attempt (PaymentOperations::applyNotification() keeps `status_before`) is
     * flagged. Keyed by order id, with the ids of those attempts.
     *
     * @param  list<int>  $orderIds
     * @return Collection<int, list<int>>
     */
    private function latePayments(array $orderIds): Collection
    {
        if ($orderIds === []) {
            return collect();
        }

        $payments = Payment::query()
            ->whereIn('order_id', $orderIds)
            ->whereIn('status', [Payment::STATUS_FAILED, Payment::STATUS_EXPIRED])
            ->pluck('order_id', 'id');
        if ($payments->isEmpty()) {
            return collect();
        }

        return PaymentEvent::query()
            ->whereIn('payment_id', $payments->keys())
            ->whereIn('type', [PaymentEvent::TYPE_NOTIFICATION, ...self::LATE_EVENTS])
            ->orderBy('id')
            ->get()
            ->groupBy('payment_id')
            ->filter(function (Collection $events): bool {
                $handled = $events->whereIn('type', self::LATE_EVENTS)->last();
                if ($handled !== null) {
                    return $handled->type === BancardProvider::EVENT_LATE_PAYMENT_REVIEW;
                }

                return $events->contains(fn (PaymentEvent $event): bool => self::isLateApproval($event));
            })
            ->keys()
            ->groupBy(fn ($paymentId) => $payments[$paymentId])
            ->map(fn (Collection $ids) => $ids->map(fn ($id) => (int) $id)->values()->all());
    }

    /** A verified notification saying "approved" for an attempt that was already failed or expired. */
    public static function isLateApproval(PaymentEvent $event): bool
    {
        $payload = $event->payload_json ?? [];
        if ($event->type !== PaymentEvent::TYPE_NOTIFICATION
            || ! in_array($payload['status_before'] ?? null, [Payment::STATUS_FAILED, Payment::STATUS_EXPIRED], true)) {
            return false;
        }

        return self::isApproval($payload) === true;
    }

    /**
     * Whether a provider payload reports an approved payment: Bancard `operation.response` S with code 00, or a
     * provider that says `approved`. Null when the payload does not say.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function isApproval(array $payload): ?bool
    {
        $operation = $payload['operation'] ?? $payload['confirmation'] ?? null;
        if (is_array($operation) && array_key_exists('response', $operation)) {
            return $operation['response'] === 'S' && (string) ($operation['response_code'] ?? '') === '00';
        }
        if (array_key_exists('approved', $payload)) {
            return (bool) $payload['approved'];
        }

        return null;
    }

    /**
     * @param  Collection<int, PaymentMethod>  $methods
     * @return array<string, string>
     */
    private function methodLabels(Collection $methods): array
    {
        return $methods->mapWithKeys(fn (PaymentMethod $method) => [
            $method->key => Lang::has('checkout.method_'.$method->key) ? __('checkout.method_'.$method->key) : $method->key,
        ])->all();
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
