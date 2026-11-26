<?php

namespace App\Http\Controllers\Admin;

use App\Connectors\Bancard\BancardConnector;
use App\Http\Controllers\Controller;
use App\Models\Locale;
use App\Models\Page;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\ShippingZone;
use App\Services\Settings\SettingOperations;
use App\Services\Shop\CheckoutReadiness;
use App\Services\Shop\PaymentMethodOperations;
use App\Shop\Payments\Bancard\BancardClient;
use App\Shop\Payments\Bancard\BancardCredentials;
use App\Shop\Payments\Bancard\BancardException;
use App\Shop\Payments\Bancard\BancardResponse;
use App\Shop\Payments\ManualPaymentProvider;
use App\Shop\Payments\PaymentProviderRegistry;
use App\Support\AdminCapabilities;
use App\Support\ShopModule;
use App\Support\SiteUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin > Payments and shipping (PLAN.md E3.4), administrators only (routes/shop-checkout-admin.php, `role:admin`,
 * behind EnsureShopCatalog so everything can be prepared before turning selling on in Settings > Shop):
 *
 * - update(): manual methods (bank details, cash-on-delivery text), turning methods on and their order, store pickup,
 *   the orders email and the purchase policies page. A method only turns on when it is complete.
 * - bancard(): one environment's keys, saved encrypted (payment_methods.secrets) and bound to this site's origin.
 *   An empty field keeps the stored key, "Clear" removes the pair. Keys are never sent back to the browser: the
 *   screen says "configured" and the last 4 characters of the public key.
 * - environment(): staging or production; production needs its keys and an explicit confirmation.
 * - bancardTest(): "Test connection", a get_confirmation for a buy that cannot exist: Bancard answering that it does
 *   not know the buy means it accepted the keys (BancardResponse::rejectsCredentials() otherwise).
 *
 * Every write goes through CheckoutReadiness::guard(): a selling site cannot lose its last payment method or delivery
 * option from here. The method rules (settings, keys, "enabled means complete", the `bancard` connector row) are
 * App\Services\Shop\PaymentMethodOperations, shared with MCP `payment_methods_update`.
 */
class ShopPaymentsController extends Controller
{
    /** shop_process_id of the connection test: the largest Bancard accepts, never given to a real attempt. */
    public const TEST_SHOP_PROCESS_ID = Payment::MAX_SHOP_PROCESS_ID;

    /** payment_events.payload_json `operation` of the connection test. */
    public const TEST_OPERATION = 'connection_test';

    /** Longest single-line value of a manual method field; `instructions` takes INSTRUCTIONS_MAX. */
    public const FIELD_MAX = PaymentMethodOperations::FIELD_MAX;

    public const INSTRUCTIONS_MAX = PaymentMethodOperations::INSTRUCTIONS_MAX;

    public function __construct(
        private readonly PaymentProviderRegistry $payments,
        private readonly CheckoutReadiness $readiness,
        private readonly PaymentMethodOperations $methods,
    ) {}

    public function edit(ShopModule $shop): View
    {
        $methods = PaymentMethod::query()->orderBy('sort_order')->orderBy('id')->get();
        $bancard = $methods->firstWhere('key', BancardConnector::KEY);

        return view('admin.shop-payments.edit', [
            'methods' => $methods->map(fn (PaymentMethod $method): array => [
                'method' => $method,
                'manual' => $method->provider === ManualPaymentProvider::KEY,
                'fields' => $method->provider === ManualPaymentProvider::KEY ? ManualPaymentProvider::fieldsFor($method->key) : [],
                'required' => $method->provider === ManualPaymentProvider::KEY ? ManualPaymentProvider::requiredFor($method->key) : [],
                'missing' => $this->missingLabels($method),
            ])->all(),
            'bancard' => $bancard === null ? null : $this->bancardState($bancard),
            'zones' => ShippingZone::query()->orderBy('sort_order')->orderBy('id')->get(),
            'pickupEnabled' => (bool) Setting::get('shop_pickup_enabled', false),
            'pickupAddress' => (string) Setting::get('shop_pickup_address', ''),
            'ordersEmail' => (string) Setting::get('shop_orders_email', ''),
            'contactEmail' => (string) Setting::get('contact_email', ''),
            'termsPageId' => (string) Setting::get('shop_terms_page_id', ''),
            'pages' => Page::published()->where('locale', Locale::baseCode())->orderBy('title')->get(['id', 'title', 'slug']),
            'readiness' => $this->readiness->items(),
            'selling' => $shop->checkout(),
            'siteRequirements' => $this->siteRequirements(),
            'currency' => (string) config('cms.shop.currency', 'PYG'),
        ]);
    }

    public function update(Request $request, SettingOperations $settings): RedirectResponse
    {
        $data = $request->validate([
            'methods' => ['sometimes', 'array'],
            'methods.*.enabled' => ['sometimes', 'boolean'],
            'methods.*.sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'methods.*.settings' => ['sometimes', 'array'],
            'methods.*.settings.*' => ['nullable', 'string', 'max:'.self::INSTRUCTIONS_MAX],
            'shop_pickup_enabled' => ['sometimes', 'boolean'],
            'shop_pickup_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'shop_orders_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'shop_terms_page_id' => ['sometimes', 'nullable', 'integer', Rule::exists('pages', 'id')->where('locale', Locale::baseCode())->where('status', 'published')],
        ]);

        $methods = PaymentMethod::query()->whereIn('key', array_keys($data['methods'] ?? []))->get()->keyBy('key');
        $errors = [];
        foreach ($data['methods'] ?? [] as $key => $input) {
            $method = $methods->get($key);
            if ($method === null) {
                continue;
            }
            if (isset($input['settings'])) {
                $errors += $this->methods->fillSettings($method, $input['settings'], 'methods.'.$key.'.settings');
            }
            if (array_key_exists('sort_order', $input)) {
                $method->sort_order = (int) $input['sort_order'];
            }
            if (array_key_exists('enabled', $input)) {
                $method->enabled = (bool) $input['enabled'];
            }
            // An enabled method stays complete: the checkout would silently leave out an incomplete one.
            if ($this->methods->incomplete($method) !== []) {
                $errors['methods.'.$key.'.enabled'] = __('checkout.method_incomplete', ['fields' => implode(', ', $this->missingLabels($method))]);
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $bancardToggled = $methods->get(BancardConnector::KEY)?->isDirty('enabled') ?? false;
        $values = array_intersect_key($data, array_flip(['shop_pickup_enabled', 'shop_pickup_address', 'shop_orders_email', 'shop_terms_page_id']));
        $this->readiness->guard(function () use ($methods, $values, $settings, $request) {
            $methods->each(fn (PaymentMethod $method) => $method->save());
            if ($values !== []) {
                $settings->persist($request->user(), array_map(fn ($value) => is_string($value) ? trim($value) : $value, $values));
            }
        });

        if ($bancardToggled) {
            $this->methods->syncConnector($request->user());
        }

        return redirect()->route('admin.shop-payments.edit')->with('success', __('checkout.saved'));
    }

    public function bancard(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'environment' => ['required', 'string', Rule::in(PaymentMethod::ENVIRONMENTS)],
            'clear' => ['sometimes', 'boolean'],
            'public_key' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{32}$/'],
            'private_key' => ['nullable', 'string', 'max:255'],
        ], [
            'public_key.regex' => __('checkout.bancard_public_key_invalid'),
        ]);
        $environment = $data['environment'];
        $method = $this->bancardMethod();
        // Keys saved on another site (restored snapshot, other APP_KEY) are never carried over: load them again (R20).
        if ($request->boolean('clear')) {
            $this->methods->clearCredentials($method, $environment);
            $message = __('checkout.bancard_keys_cleared');
        } else {
            $this->methods->storeCredentials($method, $environment, $data['public_key'] ?? null, $data['private_key'] ?? null);
            $message = __('checkout.bancard_keys_saved');
        }

        $this->readiness->guard(fn () => $method->save());
        $this->methods->syncConnector($request->user());

        return redirect()->route('admin.shop-payments.edit')->withFragment('bancard')->with('success', $message);
    }

    public function environment(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'environment' => ['required', 'string', Rule::in(PaymentMethod::ENVIRONMENTS)],
            'confirm' => [Rule::requiredIf($request->input('environment') === PaymentMethod::ENVIRONMENT_PRODUCTION), 'accepted_if:environment,production'],
        ], [
            'confirm.required' => __('checkout.bancard_production_confirm'),
            'confirm.accepted_if' => __('checkout.bancard_production_confirm'),
        ]);
        $method = $this->bancardMethod();
        if ($data['environment'] === PaymentMethod::ENVIRONMENT_PRODUCTION && $this->credentials($method, PaymentMethod::ENVIRONMENT_PRODUCTION) === null) {
            throw ValidationException::withMessages(['environment' => __('checkout.bancard_production_requires_keys')]);
        }

        $this->readiness->guard(fn () => $method->forceFill(['environment' => $data['environment']])->save());
        $this->methods->syncConnector($request->user());

        return redirect()->route('admin.shop-payments.edit')->withFragment('bancard')
            ->with('success', __('checkout.bancard_environment_saved', ['environment' => __('checkout.environment_'.$data['environment'])]));
    }

    public function bancardTest(Request $request, BancardClient $client): RedirectResponse
    {
        $data = $request->validate(['environment' => ['required', 'string', Rule::in(PaymentMethod::ENVIRONMENTS)]]);
        $environment = $data['environment'];
        $method = $this->bancardMethod();
        $back = redirect()->route('admin.shop-payments.edit')->withFragment('bancard');

        $credentials = $this->credentials($method, $environment);
        if ($credentials === null) {
            return $back->withErrors(['bancard' => $this->foreignSecrets($method) ? __('checkout.bancard_reload_keys') : __('checkout.bancard_test_failed', ['reason' => __('checkout.bancard_status_unconfigured')])]);
        }

        try {
            $response = $client->getConfirmation($credentials, self::TEST_SHOP_PROCESS_ID);
        } catch (BancardException $exception) {
            $this->recordTest($environment, 'failed', $exception->reason);

            return $back->withErrors(['bancard' => __('checkout.bancard_test_failed', ['reason' => $exception->userMessage()])]);
        }

        if ($response->rejectsCredentials()) {
            $this->recordTest($environment, 'rejected', $response->key());

            return $back->withErrors(['bancard' => __('checkout.bancard_test_rejected', ['reason' => $response->errorMessage()])]);
        }
        if (! $response->successful() && ! $response->hasKey(BancardResponse::KEY_BUY_NOT_FOUND, BancardResponse::KEY_PAYMENT_NOT_FOUND)) {
            $this->recordTest($environment, 'failed', $response->key());

            return $back->withErrors(['bancard' => __('checkout.bancard_test_failed', ['reason' => $response->errorMessage()])]);
        }

        $this->recordTest($environment, 'accepted', $response->key());

        return $back->with('success', __('checkout.bancard_test_ok'));
    }

    /**
     * What a method lacks, in words: manual fields by their label; for Bancard, "not configured" (its provider
     * reports keys, environment and site binding; the Bancard card explains which).
     *
     * @return list<string>
     */
    private function missingLabels(PaymentMethod $method): array
    {
        $missing = $this->payments->missing($method);
        if ($method->provider !== ManualPaymentProvider::KEY) {
            return $missing === [] ? [] : [__('checkout.bancard_status_unconfigured')];
        }

        return array_map(fn (string $field): string => __('checkout.'.$field), $missing);
    }

    /**
     * Everything the Bancard card shows. Never a key: only whether each environment has one and the last 4
     * characters of its public key.
     *
     * @return array<string, mixed>
     */
    private function bancardState(PaymentMethod $method): array
    {
        $foreign = $this->foreignSecrets($method);
        $environment = in_array($method->environment, PaymentMethod::ENVIRONMENTS, true) ? $method->environment : PaymentMethod::ENVIRONMENT_STAGING;
        $keys = [];
        foreach (PaymentMethod::ENVIRONMENTS as $env) {
            $credentials = $this->credentials($method, $env);
            $keys[$env] = ['configured' => $credentials !== null, 'suffix' => $credentials?->publicKeySuffix()];
        }
        $other = $environment === PaymentMethod::ENVIRONMENT_PRODUCTION ? PaymentMethod::ENVIRONMENT_STAGING : PaymentMethod::ENVIRONMENT_PRODUCTION;
        $webhookUrl = SiteUrl::origin().route('webhooks.bancard', [], false);
        $host = strtolower((string) parse_url($webhookUrl, PHP_URL_HOST));

        return [
            'method' => $method,
            'foreign' => $foreign,
            'environment' => $environment,
            'status' => $keys[$environment]['configured'] ? $environment : 'unconfigured',
            'keys' => $keys,
            'missing' => $this->missingLabels($method),
            'providerRegistered' => $this->payments->has($method->provider),
            'webhookUrl' => $webhookUrl,
            'webhookUnreachable' => parse_url($webhookUrl, PHP_URL_SCHEME) !== 'https'
                || in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)
                || str_ends_with($host, '.localhost') || str_ends_with($host, '.test'),
            'lastEvent' => PaymentEvent::query()->latestFor(BancardConnector::KEY)->where('type', '!=', PaymentEvent::TYPE_API_CALL)->first(),
            'lastCall' => PaymentEvent::query()->latestFor(BancardConnector::KEY)->where('type', PaymentEvent::TYPE_API_CALL)->first(),
            'otherEnvironment' => $other,
            'pendingOther' => Payment::query()->where('provider', BancardConnector::KEY)->where('status', Payment::STATUS_PENDING)->where('environment', $other)->count(),
        ];
    }

    /** Stored secrets that cannot be used here: unreadable (other APP_KEY) or saved under another origin. */
    private function foreignSecrets(PaymentMethod $method): bool
    {
        return $this->methods->foreignSecrets($method);
    }

    /** One environment's usable keys: saved, readable and bound to this site. */
    private function credentials(PaymentMethod $method, string $environment): ?BancardCredentials
    {
        return $this->foreignSecrets($method) ? null : BancardCredentials::fromMethod($method, $environment);
    }

    private function bancardMethod(): PaymentMethod
    {
        return PaymentMethod::query()->where('key', BancardConnector::KEY)->firstOrFail();
    }

    /** Log the connection test like any other call to Bancard (no key, no token, no body). */
    private function recordTest(string $environment, string $result, ?string $key): void
    {
        PaymentEvent::query()->create([
            'provider' => BancardConnector::KEY,
            'type' => PaymentEvent::TYPE_API_CALL,
            'payload_json' => ['operation' => self::TEST_OPERATION, 'environment' => $environment, 'result' => $result, 'key' => $key],
        ]);
    }

    /**
     * What Bancard asks of the site before certifying it (informative, never blocks), each with where to solve it.
     *
     * @return list<array{key: string, links: array<string, string>}>
     */
    private function siteRequirements(): array
    {
        $pageLinks = [];
        if (Route::has('admin.pages.index')) {
            $pageLinks['checkout.requirement_choose_page'] = route('admin.pages.index');
        }
        if (Route::has('admin.pages.create') && app(AdminCapabilities::class)->liveEditor()) {
            $pageLinks['checkout.requirement_create_page'] = route('admin.pages.create');
        }

        return [
            ['key' => 'contact', 'links' => $pageLinks],
            ['key' => 'address', 'links' => ['admin.settings' => route('admin.settings.index', ['tab' => 'contact'])]],
            ['key' => 'privacy', 'links' => $pageLinks],
            ['key' => 'returns', 'links' => $pageLinks],
            ['key' => 'shipping', 'links' => $pageLinks],
            ['key' => 'about', 'links' => $pageLinks],
            ['key' => 'shipping_costs', 'links' => ['checkout.shipping_title' => '#shipping']],
        ];
    }
}
