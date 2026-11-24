<?php

namespace App\Services\Marketing;

use App\Connectors\ConnectorRegistry;
use App\Connectors\MetaPixel\MetaPixelConnector;
use App\Jobs\SendMetaPixelEvent;
use App\Models\Connector;
use App\Models\Order;
use App\Services\Shop\CartService;
use App\Support\SiteUrl;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runtime of the Meta Pixel connector (App\Connectors\MetaPixel\MetaPixelConnector, docs/operations/meta-pixel.md).
 * Every event goes to the browser (fbq, rendered by resources/views/partials/meta-pixel.blade.php) and, when an
 * access token is saved, to the Conversions API (SendMetaPixelEvent, queued after the transaction commits), both with
 * the same event_id and event name so Meta keeps one of them (Meta, "Handling Duplicate Pixel and Conversions API
 * Events", updated 2026-06-28, consulted 2026-11-24).
 *
 * Where each event starts:
 * - PageView: every public page that uses layouts.public (pageView(), from the partial).
 * - ViewContent: the product page (view composer on `shop.show`, MetaPixelServiceProvider).
 * - AddToCart: Shop\CartController::add() after the product is in the cart (the browser event shows on the cart page).
 * - InitiateCheckout: the checkout page (view composer on `shop.checkout`).
 * - Purchase: server, App\Listeners\Marketing\SendMetaPixelPurchase on OrderPaid; browser, the order page once the
 *   order is paid, once per order (purchaseInBrowser()). event_id `purchase-{order number}` on both sides.
 * - Lead: Public\ContactFormController::store() after a real submission (the browser event shows on the page it
 *   redirects to).
 *
 * Nothing is sent while the connector is off or has no pixel ID, inside the panel's previews, for a signed-in panel
 * user (their visits would muddy the numbers) or when the consent hook says no (resolveConsentUsing()). A failure is
 * logged without personal data or token, never thrown: a page or the checkout never breaks because of Meta.
 */
final class MetaPixel
{
    /** Name of the encrypted secret holding the Conversions API access token (Connector::secret()). */
    public const ACCESS_TOKEN = 'capi_access_token';

    /** Session key with the browser events of a POST that redirects (AddToCart, Lead), shown on the next page. */
    public const FLASH_KEY = 'meta_pixel.events';

    /** How long the browser ids of a placed order wait for its payment, and how long the "Purchase shown" mark lasts. */
    private const ORDER_DAYS = 30;

    private static ?Closure $consentResolver = null;

    /** @var list<array{name: string, data: array<string, mixed>, id: string}> */
    private array $pending = [];

    public function __construct(private readonly ConnectorRegistry $registry) {}

    /**
     * Hook for a cookie or privacy banner: a closure receiving the Request that returns whether the visitor accepted
     * advertising measurement. Null (the default) means no banner: the pixel loads while the connector is active and the
     * site owner is responsible for the privacy notice. With false, neither the browser pixel nor server events run.
     */
    public static function resolveConsentUsing(?Closure $resolver): void
    {
        self::$consentResolver = $resolver;
    }

    /** The pixel ID while the connector is on and configured; null otherwise. */
    public function pixelId(): ?string
    {
        if (! $this->registry->has(MetaPixelConnector::KEY) || ! $this->record()?->enabled) {
            return null;
        }
        $pixelId = $this->registry->settings(MetaPixelConnector::KEY)['pixel_id'] ?? null;

        return MetaPixelConnector::validPixelId($pixelId) ? $pixelId : null;
    }

    /** The connector is measuring: the Admin > Analytics pixel snippet steps aside so PageView is not counted twice. */
    public function active(): bool
    {
        return $this->pixelId() !== null;
    }

    /** The decrypted access token, or null when none is saved or it cannot be used on this site. Never show it. */
    public function accessToken(): ?string
    {
        return $this->record()?->secret(self::ACCESS_TOKEN);
    }

    public function testEventCode(): ?string
    {
        $code = $this->registry->has(MetaPixelConnector::KEY) ? ($this->registry->settings(MetaPixelConnector::KEY)['test_event_code'] ?? null) : null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * Whether this visit is measured: connector active, not a preview, nobody signed in to the panel and consent given.
     */
    public function tracks(?Request $request = null, bool $editorPreview = false): bool
    {
        $request ??= request();
        if ($editorPreview || $request->user() !== null || ! $this->active()) {
            return false;
        }

        if (self::$consentResolver === null) {
            return true;
        }
        try {
            return (bool) (self::$consentResolver)($request);
        } catch (Throwable $e) {
            // A broken banner hook means no consent, never a broken page.
            $this->logFailure('consent', $e);

            return false;
        }
    }

    /**
     * Record an event of the current visit: queued for the browser (on this page, or on the next one when $redirect)
     * and, with an access token, sent to the Conversions API. Returns the event_id, or null when the visit is not
     * measured.
     *
     * @param  array<string, mixed>|Closure(): array<string, mixed>  $customData  a closure is only run for a measured visit
     */
    public function track(string $name, array|Closure $customData = [], bool $redirect = false, ?string $eventId = null): ?string
    {
        try {
            $request = request();
            if (! $this->tracks($request)) {
                return null;
            }
            if ($customData instanceof Closure) {
                $customData = $customData();
            }
            $eventId ??= (string) Str::uuid();
            $browserEvent = ['name' => $name, 'data' => $customData, 'id' => $eventId];
            if ($redirect && $request->hasSession()) {
                $request->session()->flash(self::FLASH_KEY, [...$this->flashed($request), $browserEvent]);
            } else {
                $this->pending[] = $browserEvent;
            }
            if ($this->accessToken() !== null) {
                $this->dispatch($this->serverEvent($name, $eventId, $customData, MetaPixelUserData::fromRequest($request), $this->sourceUrl($request)));
            }

            return $eventId;
        } catch (Throwable $e) {
            $this->logFailure($name, $e);

            return null;
        }
    }

    /** PageView of the page being rendered (the partial calls it once per page). */
    public function pageView(): ?string
    {
        return $this->track('PageView');
    }

    /**
     * The browser events to render on this page, each once: the ones of this request and those flashed by the POST
     * that redirected here.
     *
     * @return list<array{name: string, data: array<string, mixed>, id: string}>
     */
    public function takeBrowserEvents(?Request $request = null): array
    {
        $request ??= request();
        $events = [...$this->flashed($request), ...$this->pending];
        $this->pending = [];
        if ($request->hasSession()) {
            $request->session()->forget(self::FLASH_KEY);
        }
        $unique = [];
        foreach ($events as $event) {
            $unique[$event['name'].'|'.$event['id']] = $event;
        }
        // PageView first, as in Meta's base code; the rest in the order they happened.
        $events = array_values($unique);
        usort($events, fn (array $a, array $b): int => ($a['name'] !== 'PageView') <=> ($b['name'] !== 'PageView'));

        return $events;
    }

    /** event_id shared by the server and browser Purchase of an order. */
    public static function purchaseEventId(Order $order): string
    {
        return 'purchase-'.$order->number;
    }

    /**
     * Keep the browser ids of the customer placing $order (OrderPlaced), for the server Purchase that the payment
     * confirmation sends later from another request (the Bancard webhook). Only for a measured visit: an order placed
     * by a panel user, or without consent, is marked so that its Purchase is not sent.
     */
    public function rememberOrderContext(Order $order, ?Request $request = null): void
    {
        if (! $this->active()) {
            return;
        }
        $request ??= request();
        Cache::put($this->contextKey($order), [
            'track' => $this->tracks($request),
            ...MetaPixelUserData::browserIds($request),
        ], now()->addDays(self::ORDER_DAYS));
    }

    /**
     * Server Purchase of a paid order (OrderPaid), with the customer hashed. Skipped while no token is saved or when the
     * order was placed by a visit that is not measured.
     */
    public function sendPurchase(Order $order): void
    {
        try {
            if (! $this->active() || $this->accessToken() === null) {
                return;
            }
            $context = Cache::get($this->contextKey($order));
            $context = is_array($context) ? $context : ['track' => true];
            if (! ($context['track'] ?? false)) {
                return;
            }
            $eventTime = $order->paid_at?->getTimestamp() ?? time();
            $this->dispatch($this->serverEvent(
                'Purchase',
                self::purchaseEventId($order),
                MetaPixelContent::forOrder($order),
                MetaPixelUserData::fromOrder($order, $context),
                $this->checkoutUrl(),
                $eventTime,
            ));
        } catch (Throwable $e) {
            $this->logFailure('Purchase', $e);
        }
    }

    /**
     * Browser Purchase on the order page, once per order and only once it is paid. No server event: SendMetaPixelPurchase
     * sent it when the payment was confirmed, with the same event_id.
     */
    public function purchaseInBrowser(Order $order): void
    {
        try {
            if (! in_array($order->status, [Order::STATUS_PAID, Order::STATUS_FULFILLED], true) || ! $this->tracks()) {
                return;
            }
            if (! Cache::add('meta_pixel.purchase_shown.'.$order->id, true, now()->addDays(self::ORDER_DAYS))) {
                return;
            }
            $this->pending[] = ['name' => 'Purchase', 'data' => MetaPixelContent::forOrder($order), 'id' => self::purchaseEventId($order)];
        } catch (Throwable $e) {
            $this->logFailure('Purchase', $e);
        }
    }

    /**
     * One Conversions API event (Meta, "Server Event Parameters", consulted 2026-11-24): action_source `website`,
     * event_source_url and client_user_agent are required for website events.
     *
     * @param  array<string, mixed>  $customData
     * @param  array<string, string>  $userData
     * @return array<string, mixed>
     */
    public function serverEvent(string $name, string $eventId, array $customData, array $userData, string $sourceUrl, ?int $eventTime = null): array
    {
        return array_filter([
            'event_name' => $name,
            'event_time' => $eventTime ?? time(),
            'event_id' => $eventId,
            'action_source' => 'website',
            'event_source_url' => $sourceUrl,
            'user_data' => $userData,
            'custom_data' => $customData === [] ? null : $customData,
        ], fn ($value): bool => $value !== null);
    }

    /**
     * Queue a server event for after the commit; on the `sync` connection, after the response, so the visitor never
     * waits for Meta.
     *
     * @param  array<string, mixed>  $event
     */
    public function dispatch(array $event): void
    {
        if (config('queue.default') === 'sync') {
            SendMetaPixelEvent::dispatchAfterResponse($event);

            return;
        }
        SendMetaPixelEvent::dispatch($event)->afterCommit();
    }

    /**
     * The page URL for Meta, without the query string. The order page URL carries the customer's private token, so
     * events there name the checkout instead.
     */
    public function sourceUrl(Request $request): string
    {
        $route = (string) $request->route()?->getName();
        if (str_ends_with($route, 'shop.order.show') || str_ends_with($route, 'shop.order.pay')) {
            return $this->checkoutUrl();
        }

        return $request->url();
    }

    /** Log a failure without personal data or token: the event name and the error class only. */
    public function logFailure(string $event, Throwable $e): void
    {
        Log::warning('Meta Pixel event skipped after an error.', ['event' => $event, 'error' => $e::class]);
    }

    private function checkoutUrl(): string
    {
        return Route::has('shop.checkout.show') ? CartService::route('checkout.show') : SiteUrl::origin().'/';
    }

    private function contextKey(Order $order): string
    {
        return 'meta_pixel.order_context.'.$order->id;
    }

    /** @return list<array{name: string, data: array<string, mixed>, id: string}> */
    private function flashed(Request $request): array
    {
        $flashed = $request->hasSession() ? $request->session()->get(self::FLASH_KEY, []) : [];

        return is_array($flashed) ? array_values($flashed) : [];
    }

    private function record(): ?Connector
    {
        return $this->registry->record(MetaPixelConnector::KEY);
    }
}
