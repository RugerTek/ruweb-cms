<?php

namespace App\Http\Controllers;

use App\Exceptions\RevisionConflict;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\ShippingZone;
use App\Models\User;
use App\Services\Settings\SettingOperations;
use App\Services\Shop\CheckoutReadiness;
use App\Services\Shop\OrderOperations;
use App\Services\Shop\PaymentMethodOperations;
use App\Services\Shop\PaymentOperations;
use App\Services\Shop\StrayChargeOperations;
use App\Shop\Payments\ManualPaymentProvider;
use App\Shop\Payments\PaymentProviderRegistry;
use App\Support\ShopModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * MCP tools and REST mirror of selling online (PLAN.md E3.6, track 3F; docs/operations/mcp.md#orders-and-payments):
 * `orders_*`, `payments_*`, `payment_methods_*` and `shipping_zones_*`. They follow the panel: orders exist while
 * the catalog is on and the shop sells or has orders (ordersAvailable(), like the "Orders" sidebar entry); payment
 * methods and shipping can be prepared with the catalog on before selling (settingsAvailable()). Order and payment
 * writes go through OrderOperations / PaymentOperations, whose OrderPolicy decides the role (editors see orders,
 * keep notes and mark them delivered; administrators move money); payment methods and shipping are administrator
 * only, like Admin > Payments and shipping.
 *
 * Never returned: the order access token (or its hash, or the order page URL), payment method secrets (keys are
 * accepted by payment_methods_update and reported only as configured or not) and provider payloads (a Bancard
 * notification carries its md5 token and security_information).
 */
class McpOrderController extends Controller
{
    private const REVISION = ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/'];

    /** Provider key whose methods take API keys through payment_methods_update `credentials`. */
    public const CREDENTIALS_PROVIDER = 'bancard';

    public function __construct(
        private readonly OrderOperations $orders,
        private readonly PaymentOperations $payments,
        private readonly PaymentProviderRegistry $providers,
    ) {}

    /** orders_* and payments_* exist with the catalog on while the shop sells or keeps orders (sidebar "Orders"). */
    public static function ordersAvailable(): bool
    {
        $shop = app(ShopModule::class);

        return $shop->catalog() && ($shop->checkout() || Order::query()->exists());
    }

    /** payment_methods_* and shipping_zones_* exist with the catalog on, to prepare selling before turning it on. */
    public static function settingsAvailable(): bool
    {
        return app(ShopModule::class)->catalog();
    }

    // -------------------------------------------------------------------------
    // Orders
    // -------------------------------------------------------------------------

    public function ordersList(Request $request): JsonResponse
    {
        $this->ensureOrders();
        Gate::forUser($request->user())->authorize('viewAny', Order::class);
        $validated = Validator::make($request->all(), [
            'status' => ['nullable', 'string', Rule::in(Order::STATUSES)],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'search' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ])->validate();

        $query = Order::query()->with('payments')->orderByDesc('placed_at')->orderByDesc('id');
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['payment_method'])) {
            $query->where('payment_method_key', $validated['payment_method']);
        }
        if (! empty($validated['from'])) {
            $query->where('placed_at', '>=', $validated['from'].' 00:00:00');
        }
        if (! empty($validated['to'])) {
            $query->where('placed_at', '<=', $validated['to'].' 23:59:59');
        }
        if (! empty($validated['search'])) {
            $search = '%'.$validated['search'].'%';
            $query->where(fn ($q) => $q->where('number', 'like', $search)
                ->orWhere('customer_name', 'like', $search)
                ->orWhere('customer_email', 'like', $search)
                ->orWhere('customer_phone', 'like', $search));
        }
        $orders = $query->paginate($validated['per_page'] ?? config('cms.mcp.per_page', 20), ['*'], 'page', $validated['page'] ?? 1);

        return response()->json([
            'data' => $orders->getCollection()->map(fn (Order $order) => $this->serializeOrder($order, summary: true))->values(),
            'meta' => ['total' => $orders->total(), 'current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage(), 'per_page' => $orders->perPage()],
        ]);
    }

    public function ordersGet(Request $request, Order $order): JsonResponse
    {
        $this->ensureOrders();
        Gate::forUser($request->user())->authorize('view', $order);

        return response()->json(['data' => $this->serializeOrder($order)]);
    }

    /**
     * Move the order through OrderOperations::transition() (fulfilled, cancelled, paid, refunded). Cancelling a
     * pending order whose online attempt already reached the provider asks the provider first: paid there → the
     * payment is applied and the cancellation refused (refund instead); not paid → the attempt is cancelled there.
     */
    public function ordersTransition(Request $request, Order $order): JsonResponse
    {
        $this->ensureOrders();
        $user = $request->user();
        $validated = Validator::make($request->all(), [
            'expected_revision' => self::REVISION,
            'status' => ['required', 'string', Rule::in(array_keys(OrderOperations::TRANSITION_ABILITIES))],
        ])->validate();
        Gate::forUser($user)->authorize(OrderOperations::TRANSITION_ABILITIES[$validated['status']], $order);
        $this->checkOrderRevision($order, $validated['expected_revision']);

        $current = $order->fresh();
        if ($validated['status'] === Order::STATUS_CANCELLED && $current->status === Order::STATUS_PENDING) {
            $this->settleAtProvider($current);
        }
        $this->orders->transition($user, $current, $validated['status']);

        return response()->json(['data' => $this->serializeOrder($order->fresh())]);
    }

    /** Replace the internal notes (team only; never shown to the customer). null or blank clears them. */
    public function ordersNote(Request $request, Order $order): JsonResponse
    {
        $this->ensureOrders();
        $user = $request->user();
        Gate::forUser($user)->authorize('note', $order);
        $validated = Validator::make($request->all(), [
            'expected_revision' => self::REVISION,
            'internal_notes' => ['present', 'nullable', 'string', 'max:10000'],
        ])->validate();

        DB::transaction(function () use ($user, $order, $validated) {
            $this->checkOrderRevision($order, $validated['expected_revision'], lock: true);
            $this->orders->note($user, $order, $validated['internal_notes']);
        }, 3);

        return response()->json(['data' => $this->serializeOrder($order->fresh())]);
    }

    // -------------------------------------------------------------------------
    // Payments
    // -------------------------------------------------------------------------

    /** Confirm a manual attempt by hand (the money arrived). Online attempts are settled by their provider. */
    public function paymentsConfirm(Request $request, Payment $payment): JsonResponse
    {
        return $this->settleManual($request, $payment, 'confirmPayment', fn (User $user, Payment $current) => $this->payments->confirm($user, $current));
    }

    /** Mark a manual attempt as failed (the money never arrived); the order stays pending. */
    public function paymentsFail(Request $request, Payment $payment): JsonResponse
    {
        return $this->settleManual($request, $payment, 'failPayment', fn (User $user, Payment $current) => $this->payments->fail($user, $current));
    }

    /**
     * Refund a confirmed attempt: through the provider (Bancard rollback), or `manual: true` when the money was
     * already returned by hand. A provider that cannot reverse it answers a -32602 that says so; nothing changes.
     */
    public function paymentsRefund(Request $request, Payment $payment): JsonResponse
    {
        $this->ensureOrders();
        $user = $request->user();
        $order = $payment->order()->firstOrFail();
        Gate::forUser($user)->authorize('refund', $order);
        $validated = Validator::make($request->all(), [
            'expected_revision' => self::REVISION,
            'manual' => ['sometimes', 'boolean'],
        ])->validate();
        $this->checkOrderRevision($order, $validated['expected_revision']);

        $result = $this->payments->refund($user, $payment, filter_var($validated['manual'] ?? false, FILTER_VALIDATE_BOOLEAN));
        if (! $result->ok) {
            throw ValidationException::withMessages(['payment' => $result->manualRequired
                ? __('checkout.refund_manual_required').' Once returned, call payments_refund again with manual: true.'
                : 'The payment provider could not refund this payment'.($result->code !== null ? ' ('.$result->code.')' : '').'. Nothing changed.']);
        }

        return response()->json(['data' => $this->serializeOrder($order->fresh())]);
    }

    // -------------------------------------------------------------------------
    // Payment methods
    // -------------------------------------------------------------------------

    public function paymentMethodsList(Request $request): JsonResponse
    {
        $this->ensureSettings();
        $this->authorizeAdmin($request->user());

        return response()->json(['data' => PaymentMethod::query()->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (PaymentMethod $method) => $this->serializeMethod($method))->values()]);
    }

    /**
     * Patch one payment method: `enabled`, `sort_order`, public `settings` (bank details, instructions; null on a
     * key removes it) and, for Bancard, `credentials` of one environment. Keys are stored encrypted and bound to this
     * site's origin; no response ever contains them. Enabling a method that is not complete fails with nothing saved.
     */
    public function paymentMethodsUpdate(Request $request, string $method): JsonResponse
    {
        $this->ensureSettings();
        $this->authorizeAdmin($request->user());
        $record = PaymentMethod::query()->where('key', $method)->firstOrFail();
        $credentials = $record->provider === self::CREDENTIALS_PROVIDER;
        $validated = Validator::make($request->all(), [
            'expected_revision' => self::REVISION,
            'enabled' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'settings' => ['sometimes', 'array'],
            'settings.*' => ['nullable', 'string', 'max:2000'],
            'credentials' => [$credentials ? 'sometimes' : 'prohibited', 'array'],
            'credentials.environment' => ['required_with:credentials', 'string', Rule::in(PaymentMethod::ENVIRONMENTS)],
            'credentials.public_key' => ['required_with:credentials', 'string', 'regex:/^[A-Za-z0-9]{32}$/'],
            'credentials.private_key' => ['required_with:credentials', 'string', 'min:1', 'max:255'],
        ], [
            'credentials.prohibited' => 'Only methods of the '.self::CREDENTIALS_PROVIDER.' provider take credentials.',
            'credentials.public_key.regex' => 'The public key is 32 letters and digits.',
        ])->after(function (ValidatorInstance $validator) use ($request) {
            if (! $request->hasAny(['enabled', 'sort_order', 'settings', 'credentials'])) {
                $validator->errors()->add('enabled', 'Send enabled, sort_order, settings, credentials or a combination.');
            }
        })->validate();

        $operations = app(PaymentMethodOperations::class);
        $allowed = $operations->publicFields($record);
        $settings = $validated['settings'] ?? [];
        $ignored = $operations->ignoredSettings($record, $settings);

        // Same rules as Admin > Payments and shipping (PaymentMethodOperations).
        app(CheckoutReadiness::class)->guard(function () use ($record, $validated, $settings, $operations) {
            $locked = PaymentMethod::query()->lockForUpdate()->findOrFail($record->id);
            $this->checkRevision($this->methodRevision($locked), $validated['expected_revision']);

            if (($errors = $operations->fillSettings($locked, $settings)) !== []) {
                throw ValidationException::withMessages($errors);
            }
            if (array_key_exists('sort_order', $validated)) {
                $locked->sort_order = (int) $validated['sort_order'];
            }
            if (isset($validated['credentials'])) {
                $operations->storeCredentials($locked, $validated['credentials']['environment'], $validated['credentials']['public_key'], $validated['credentials']['private_key']);
            }
            if (array_key_exists('enabled', $validated)) {
                $locked->enabled = filter_var($validated['enabled'], FILTER_VALIDATE_BOOLEAN);
            }
            if (($missing = $operations->incomplete($locked)) !== []) {
                throw ValidationException::withMessages(['enabled' => 'An enabled method must be complete; nothing was saved. Missing: '.implode(', ', $missing).'.']);
            }
            $locked->save();
        }, 'enabled');

        $fresh = $record->fresh();
        if ($fresh->provider === self::CREDENTIALS_PROVIDER) {
            $operations->syncConnector($request->user());
        }
        $warnings = array_map(fn (string $key) => ['code' => 'unknown_setting', 'field' => 'settings.'.$key, 'message' => 'Not a public setting of this method; it was ignored.'.($allowed === [] ? ' Keys go in credentials.' : ' Allowed: '.implode(', ', $allowed).'.')], $ignored);

        return response()->json(['data' => $this->serializeMethod($fresh)] + ($warnings === [] ? [] : ['warnings' => $warnings]));
    }

    // -------------------------------------------------------------------------
    // Shipping
    // -------------------------------------------------------------------------

    public function shippingZonesList(Request $request): JsonResponse
    {
        $this->ensureSettings();
        $this->authorizeAdmin($request->user());

        return response()->json(['data' => $this->serializeShipping()]);
    }

    /**
     * `zones` is the WHOLE list: rows with id are updated, rows without id created, stored zones missing from it
     * deleted (orders keep the name and price they copied). List order is the display order. `pickup_enabled` and
     * `pickup_address` are store pickup (Settings `shop_pickup_*`).
     */
    public function shippingZonesUpdate(Request $request): JsonResponse
    {
        $this->ensureSettings();
        $user = $request->user();
        $this->authorizeAdmin($user);
        $validated = Validator::make($request->all(), [
            'expected_revision' => self::REVISION,
            'zones' => ['sometimes', 'array', 'max:100'],
            'zones.*' => ['array'],
            'zones.*.id' => ['sometimes', 'nullable', 'integer', 'distinct', Rule::exists('shipping_zones', 'id')],
            'zones.*.name' => ['required', 'string', 'max:255'],
            'zones.*.price_amount' => ['required', 'integer', 'min:0'],
            'zones.*.enabled' => ['sometimes', 'boolean'],
            'pickup_enabled' => ['sometimes', 'boolean'],
            'pickup_address' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->after(function (ValidatorInstance $validator) use ($request) {
            if (! $request->hasAny(['zones', 'pickup_enabled', 'pickup_address'])) {
                $validator->errors()->add('zones', 'Send zones, pickup_enabled, pickup_address or a combination.');
            }
        })->validate();

        $deleted = app(CheckoutReadiness::class)->guard(function () use ($user, $validated) {
            ShippingZone::query()->lockForUpdate()->get();
            $this->checkRevision($this->shippingRevision(), $validated['expected_revision']);

            $deleted = [];
            if (array_key_exists('zones', $validated)) {
                $keep = array_values(array_filter(array_map(fn (array $zone) => $zone['id'] ?? null, $validated['zones'])));
                $deleted = ShippingZone::query()->whereNotIn('id', $keep)->orderBy('id')->get(['id', 'name'])->toArray();
                ShippingZone::query()->whereNotIn('id', $keep)->delete();
                // validate() rebuilds nested data in rule order (zones.1.id before zones.0.name): restore list order.
                $zones = $validated['zones'];
                ksort($zones);
                foreach (array_values($zones) as $position => $input) {
                    $zone = isset($input['id']) ? ShippingZone::query()->findOrFail($input['id']) : new ShippingZone;
                    $zone->fill([
                        'name' => trim($input['name']),
                        'price_amount' => (int) $input['price_amount'],
                        'enabled' => array_key_exists('enabled', $input) ? filter_var($input['enabled'], FILTER_VALIDATE_BOOLEAN) : ($zone->exists ? $zone->enabled : true),
                        'sort_order' => $position + 1,
                    ])->save();
                }
            }

            $pickup = array_intersect_key($validated, array_flip(['pickup_enabled', 'pickup_address']));
            if ($pickup !== []) {
                $enabled = array_key_exists('pickup_enabled', $pickup) ? filter_var($pickup['pickup_enabled'], FILTER_VALIDATE_BOOLEAN) : (bool) Setting::get('shop_pickup_enabled', false);
                $address = array_key_exists('pickup_address', $pickup) ? trim((string) $pickup['pickup_address']) : trim((string) Setting::get('shop_pickup_address'));
                if ($enabled && $address === '') {
                    throw ValidationException::withMessages(['pickup_address' => 'Store pickup needs the address customers go to.']);
                }
                $persist = [];
                if (array_key_exists('pickup_enabled', $pickup)) {
                    $persist['shop_pickup_enabled'] = $enabled;
                }
                if (array_key_exists('pickup_address', $pickup)) {
                    $persist['shop_pickup_address'] = $address === '' ? null : $address;
                }
                app(SettingOperations::class)->persist($user, $persist);
            }

            return $deleted;
        }, 'zones');

        $warnings = array_map(fn (array $zone) => ['code' => 'zone_deleted', 'field' => 'zones', 'message' => 'Zone "'.$zone['name'].'" (id '.$zone['id'].') was deleted; existing orders keep its name and price.'], $deleted);

        return response()->json(['data' => $this->serializeShipping()] + ($warnings === [] ? [] : ['warnings' => $warnings]));
    }

    // -------------------------------------------------------------------------
    // Revisions
    // -------------------------------------------------------------------------

    /** Status, internal notes, shortfall and every attempt's status: what the writes of these tools change. */
    public function orderRevision(Order $order): string
    {
        $payments = Payment::query()->where('order_id', $order->id)->orderBy('id')->get(['id', 'status'])
            ->map(fn (Payment $payment) => [$payment->id, $payment->status])->all();

        return hash('sha256', json_encode([$order->id, $order->status, $order->internal_notes, $order->stock_shortfall, $payments]));
    }

    /** Public fields and a hash of the stored ciphertext, so new keys change it without exposing them. */
    public function methodRevision(PaymentMethod $method): string
    {
        $secrets = $method->getAttributes()['secrets'] ?? null;

        return hash('sha256', json_encode([$method->key, (bool) $method->enabled, (int) $method->sort_order, $method->settings_json, $method->environment, $method->bound_origin, $secrets === null ? null : hash('sha256', (string) $secrets)]));
    }

    public function shippingRevision(): string
    {
        $zones = ShippingZone::query()->orderBy('id')->get()
            ->map(fn (ShippingZone $zone) => [$zone->id, $zone->name, $zone->price_amount->amount, (bool) $zone->enabled, (int) $zone->sort_order])->all();

        return hash('sha256', json_encode([$zones, (bool) Setting::get('shop_pickup_enabled', false), Setting::get('shop_pickup_address')]));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function ensureOrders(): void
    {
        abort_unless(self::ordersAvailable(), 404);
    }

    private function ensureSettings(): void
    {
        abort_unless(self::settingsAvailable(), 404);
    }

    private function authorizeAdmin(?User $user): void
    {
        abort_unless($user?->isAdmin() === true, 403, 'Administrator only: payment methods and shipping are managed by an administrator.');
    }

    private function checkOrderRevision(Order $order, string $expected, bool $lock = false): void
    {
        $query = Order::query();
        $current = ($lock ? $query->lockForUpdate() : $query)->findOrFail($order->id);
        $this->checkRevision($this->orderRevision($current), $expected);
    }

    private function checkRevision(string $current, string $expected): void
    {
        if (! hash_equals($current, $expected)) {
            throw new RevisionConflict($expected, $current);
        }
    }

    /** Shared by payments_confirm / payments_fail: role, manual provider, pending attempt, order revision. */
    private function settleManual(Request $request, Payment $payment, string $ability, \Closure $operation): JsonResponse
    {
        $this->ensureOrders();
        $user = $request->user();
        $order = $payment->order()->firstOrFail();
        Gate::forUser($user)->authorize($ability, $order);
        $validated = Validator::make($request->all(), ['expected_revision' => self::REVISION])->validate();
        if ($payment->provider !== ManualPaymentProvider::KEY) {
            throw ValidationException::withMessages(['payment' => 'Only manual payments (bank transfer, cash on delivery) are confirmed or failed by hand; online payments are settled by their provider.']);
        }
        $this->checkOrderRevision($order, $validated['expected_revision']);
        $operation($user, $payment);

        return response()->json(['data' => $this->serializeOrder($order->fresh())]);
    }

    /**
     * Before cancelling a pending order: every pending online attempt that reached its provider is asked there. Paid
     * → applied (the order becomes paid) and the cancellation refused; otherwise cancelled at the provider.
     */
    private function settleAtProvider(Order $order): void
    {
        $attempts = $order->payments()->where('status', Payment::STATUS_PENDING)->whereNotNull('process_id')
            ->where('provider', '!=', ManualPaymentProvider::KEY)->get();
        foreach ($attempts as $payment) {
            if (! $this->providers->has($payment->provider)) {
                throw ValidationException::withMessages(['status' => 'Payment #'.$payment->id.' is in progress with a provider this site cannot reach now ('.$payment->provider.'); nothing changed.']);
            }
            $provider = $this->providers->get($payment->provider);
            try {
                $notification = $provider->query($payment);
                if ($notification !== null && ! $provider->verify($notification, $payment)) {
                    throw ValidationException::withMessages(['status' => 'The payment provider answered something that does not verify for payment #'.$payment->id.'; nothing changed.']);
                }
                if ($notification !== null && $notification->approved) {
                    $applied = app(StrayChargeOperations::class)->apply($notification, $payment);
                    if ($applied->status !== Payment::STATUS_CONFIRMED) {
                        throw ValidationException::withMessages(['status' => 'The provider approved payment #'.$payment->id.' for another amount: it was failed and reversed at the provider (see payment events). Review the order before cancelling.']);
                    }

                    throw ValidationException::withMessages(['status' => 'The customer already paid payment #'.$payment->id.' at the provider: it was applied and the order is now paid. Refund it with payments_refund instead of cancelling.']);
                }
                $result = $provider->cancel($payment);
            } catch (\RuntimeException $e) {
                throw ValidationException::withMessages(['status' => 'The payment provider could not be reached to settle payment #'.$payment->id.'; nothing changed. Try again later.']);
            }
            if (! $result->ok) {
                throw ValidationException::withMessages(['status' => $result->manualRequired
                    ? __('checkout.refund_manual_required')
                    : 'The payment provider could not cancel payment #'.$payment->id.($result->code !== null ? ' ('.$result->code.')' : '').'; nothing changed.']);
            }
        }
    }

    /** Never includes access_token, access_token_hash, the order page URL, ip, user agent or provider payloads. */
    private function serializeOrder(Order $order, bool $summary = false): array
    {
        $order->loadMissing('payments');
        $latest = $order->payments->last();
        $data = [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status,
            'available_transitions' => $order->availableTransitions(),
            'customer_name' => $order->customer_name,
            'customer_email' => $order->customer_email,
            'payment_method' => $order->payment_method_key,
            'payment_status' => $latest?->status,
            'delivery_method' => $order->delivery_method,
            'currency' => $order->currency,
            'total_amount' => $order->total_amount->amount,
            'placed_at' => $order->placed_at?->toISOString(),
            'revision' => $this->orderRevision($order),
        ];
        if ($summary) {
            return $data;
        }
        $order->loadMissing(['items', 'shippingAddress', 'payments.events']);
        $address = $order->shippingAddress;

        return $data + [
            'locale' => $order->locale,
            'customer_phone' => $order->customer_phone,
            'shipping_zone_name' => $order->shipping_zone_name,
            'shipping_address' => $address === null ? null : [
                'address' => $address->address, 'city' => $address->city, 'department' => $address->department, 'reference' => $address->reference,
            ],
            'subtotal_amount' => $order->subtotal_amount->amount,
            'shipping_amount' => $order->shipping_amount->amount,
            'notes' => $order->notes,
            'internal_notes' => $order->internal_notes,
            'stock_applied' => $order->stock_applied_at !== null,
            'stock_shortfall' => $order->stock_shortfall,
            'items' => $order->items->map(fn (OrderItem $item) => [
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'title' => $item->title,
                'variant_name' => $item->variant_name,
                'sku' => $item->sku,
                'quantity' => (int) $item->quantity,
                'unit_price_amount' => $item->unit_price_amount->amount,
                'line_total_amount' => $item->line_total_amount->amount,
            ])->values()->all(),
            'payments' => $order->payments->map(fn (Payment $payment) => $this->serializePayment($payment))->values()->all(),
            'paid_at' => $order->paid_at?->toISOString(),
            'fulfilled_at' => $order->fulfilled_at?->toISOString(),
            'cancelled_at' => $order->cancelled_at?->toISOString(),
            'refunded_at' => $order->refunded_at?->toISOString(),
        ];
    }

    /** Attempt fields the panel shows; payload_json (tokens, security_information) never leaves. */
    private function serializePayment(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'method' => $payment->method_key,
            'provider' => $payment->provider,
            'status' => $payment->status,
            'available_transitions' => $payment->availableTransitions(),
            'amount' => $payment->amount->amount,
            'currency' => $payment->currency,
            'environment' => $payment->environment,
            'shop_process_id' => $payment->shop_process_id,
            'reached_provider' => $payment->process_id !== null,
            'authorization_number' => $payment->authorization_number,
            'ticket_number' => $payment->ticket_number,
            'response_code' => $payment->response_code,
            'response_description' => $payment->response_description,
            'created_at' => $payment->created_at?->toISOString(),
            'confirmed_at' => $payment->confirmed_at?->toISOString(),
            'failed_at' => $payment->getAttribute('failed_at')?->toISOString(),
            'expired_at' => $payment->getAttribute('expired_at')?->toISOString(),
            'refunded_at' => $payment->getAttribute('refunded_at')?->toISOString(),
            'events' => $payment->events->map(fn (PaymentEvent $event) => [
                'type' => $event->type,
                'token_valid' => $event->token_valid,
                'status_before' => is_array($event->payload_json) && is_string($event->payload_json['status_before'] ?? null) ? $event->payload_json['status_before'] : null,
                'created_at' => $event->created_at?->toISOString(),
            ])->values()->all(),
        ];
    }

    /** Public settings only; credentials as configured or not, never their value. */
    private function serializeMethod(PaymentMethod $method): array
    {
        $manual = $method->provider === ManualPaymentProvider::KEY;
        $fields = $manual ? ManualPaymentProvider::fieldsFor($method->key) : [];
        $missing = $this->providers->missing($method);
        $data = [
            'key' => $method->key,
            'label' => __('checkout.method_'.$method->key) !== 'checkout.method_'.$method->key ? __('checkout.method_'.$method->key) : $method->key,
            'provider' => $method->provider,
            'enabled' => (bool) $method->enabled,
            'sort_order' => (int) $method->sort_order,
            'ready' => $missing === [],
            'missing' => $missing,
            'offered' => (bool) $method->enabled && $missing === [],
            'settings' => (object) array_filter(array_intersect_key($method->settings_json ?? [], array_flip($fields)), fn ($value) => $value !== null),
            'setting_keys' => $fields,
            'revision' => $this->methodRevision($method),
        ];
        if ($method->provider !== self::CREDENTIALS_PROVIDER) {
            return $data;
        }
        $bound = $method->isBoundToThisSite();

        return $data + [
            'environment' => $method->environment,
            'credentials' => array_combine(PaymentMethod::ENVIRONMENTS, array_map(
                fn (string $environment) => ['configured' => $bound && $method->credentials($environment) !== null],
                PaymentMethod::ENVIRONMENTS,
            )),
            'credentials_bound_to_this_site' => $bound,
            'credentials_unreadable' => $method->secretsUnreadable(),
            'credentials_note' => 'Keys are write-only: send them in credentials; they are never returned.',
        ];
    }

    private function serializeShipping(): array
    {
        return [
            'zones' => ShippingZone::query()->orderBy('sort_order')->orderBy('id')->get()->map(fn (ShippingZone $zone) => [
                'id' => $zone->id,
                'name' => $zone->name,
                'price_amount' => $zone->price_amount->amount,
                'enabled' => (bool) $zone->enabled,
                'sort_order' => (int) $zone->sort_order,
            ])->values()->all(),
            'pickup_enabled' => (bool) Setting::get('shop_pickup_enabled', false),
            'pickup_address' => Setting::get('shop_pickup_address'),
            'currency' => config('cms.shop.currency', 'PYG'),
            'revision' => $this->shippingRevision(),
        ];
    }
}
