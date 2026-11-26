<?php

namespace App\Http\Controllers\Admin;

use App\Connectors\ConnectorRegistry;
use App\Connectors\Meta\Connect\MetaConnection;
use App\Connectors\Meta\Connect\MetaConnectionStore;
use App\Connectors\Meta\Connect\RuwebConnectException;
use App\Connectors\Meta\Graph\MetaCatalog;
use App\Connectors\Meta\Graph\MetaGraphException;
use App\Connectors\Meta\MetaCatalogConnector;
use App\Contracts\Connectors\MetaGraphClient;
use App\Contracts\Connectors\RuwebConnectClient;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Services\Connectors\ConnectorOperations;
use App\Services\Connectors\Meta\MetaCatalogSync;
use App\Services\Connectors\Meta\MetaCatalogSyncException;
use App\Support\SiteUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * Meta v2 «Conectar con Facebook» in Admin > Connectors (plan-meta-v2, track 2b), routes in routes/connectors-admin.php
 * under `role:admin` (every action also checks the `manage-connectors` ability):
 *
 * - connect (GET): registers the site with the RugerTek Connect broker the first time (register → the broker reads
 *   /.well-known/ruweb-connect/{token} → verify → MetaConnectionStore::saveConnectSite()), keeps a single-use nonce in
 *   the session and in MetaConnectionStore::startFlow() (10 minutes, bound to the administrator) and sends the browser
 *   to RuwebConnectClient::startUrl().
 * - (contract v2) before sending the browser back, the broker POSTs the token to /_ruweb/connect/meta/handoff
 *   (RuwebConnectHandoffReceiver), which keeps it for the administrator whose nonce it carries. The broker stores
 *   nothing.
 * - connected (GET): the broker sends the browser back here with the nonce. Checks it, takes the delivery kept for
 *   this administrator (MetaConnectionStore::pullDelivery()), stores the connection and picks the catalog: the only
 *   `commerce` one at once (or the one already used when the same business reconnects), a choice screen when there
 *   are several, an explanation when there is none (then nothing is kept: an unusable token is not stored). Choosing the catalog activates the feed if
 *   needed and calls MetaCatalogSync::provision() (track 2c).
 * - catalog (GET/POST): the choice screen and the choice.
 * - disconnect (POST): MetaCatalogSync::teardown() (optionally deleting the feed Ruweb created in Meta), then the token
 *   and the Meta ids are always dropped, even when Meta fails. The client's catalog is never deleted.
 *
 * Nothing here logs or shows the token or the site secret; the log keeps who did what (actor id).
 */
class MetaConnectController extends Controller
{
    /** Session entry of a connection in progress: nonce, expiry and administrator. */
    public const SESSION_FLOW = 'meta_connect.flow';

    /** Session entry with the catalogs the client shared, while choosing one. */
    public const SESSION_CATALOGS = 'meta_connect.catalogs';

    /** Seconds a nonce (and the catalog list) stays valid: the broker's state lives 10 minutes too. */
    public const FLOW_TTL = 600;

    /** Where the client creates the «Productos online» catalog before connecting. */
    public const COMMERCE_MANAGER_URL = MetaCatalogConnector::COMMERCE_MANAGER_URL;

    /** Business settings (Integraciones › Aplicaciones conectadas), to remove the app after disconnecting. */
    public const BUSINESS_SETTINGS_URL = 'https://business.facebook.com/settings/';

    public function __construct(
        private readonly MetaConnectionStore $store,
        private readonly RuwebConnectClient $broker,
        private readonly ConnectorRegistry $registry,
    ) {}

    /** Whether «Conectar con Facebook» can work on this site (RUWEB_CONNECT_URL set). */
    public static function available(): bool
    {
        return trim((string) config('cms.meta.connect_url')) !== '';
    }

    public function connect(Request $request): RedirectResponse
    {
        $user = $this->administrator($request);
        if (! self::available()) {
            return $this->back()->withErrors(['meta_connect' => __('connectors.meta_connect_unavailable')]);
        }
        if ($this->registry->unmet(MetaCatalogConnector::KEY) !== []) {
            return $this->back()->withErrors(['meta_connect' => __('connectors.meta_needs_catalog')]);
        }

        try {
            [$siteId, $siteSecret] = $this->site();
        } catch (RuwebConnectException $exception) {
            $this->store->clearRegistration();
            Log::warning('Meta connect: site registration failed.', ['actor' => $user->id, 'error' => $exception->errorCode, 'status' => $exception->httpStatus]);

            return $this->back()->withErrors(['meta_connect' => $this->registrationError($exception)]);
        }

        $nonce = Str::random(48);
        $request->session()->put(self::SESSION_FLOW, ['nonce' => $nonce, 'expires_at' => now()->getTimestamp() + self::FLOW_TTL, 'user_id' => $user->id]);
        // The broker's server-to-server delivery has no session: it is matched to this administrator by the nonce.
        $this->store->startFlow($nonce, $user->id, self::FLOW_TTL);
        $request->session()->forget(self::SESSION_CATALOGS);
        Log::info('Meta connect: sent to Meta.', ['actor' => $user->id]);

        return redirect()->away($this->broker->startUrl($siteId, $siteSecret, $nonce));
    }

    public function connected(Request $request): RedirectResponse
    {
        $user = $this->administrator($request);
        $flow = $request->session()->pull(self::SESSION_FLOW);
        $nonce = $request->query('nonce');
        if (! is_array($flow) || ! is_string($flow['nonce'] ?? null) || ! is_string($nonce) || $nonce === '' || ! hash_equals($flow['nonce'], $nonce)
            || (int) ($flow['expires_at'] ?? 0) < now()->getTimestamp() || ($flow['user_id'] ?? null) !== $user->id) {
            return $this->back()->withErrors(['meta_connect' => __('connectors.meta_connect_expired')]);
        }

        $error = $request->query('error');
        if (is_string($error) && $error !== '') {
            $this->store->endFlow($nonce);
            Log::info('Meta connect: the dialog was not completed.', ['actor' => $user->id, 'error' => Str::limit($error, 40, '')]);

            return $error === 'cancelled'
                ? $this->back()->with('success', __('connectors.meta_connect_cancelled'))
                : $this->back()->withErrors(['meta_connect' => __('connectors.meta_connect_failed')]);
        }
        // The token arrived before the browser (contract v2); nothing here means the broker delivered nothing for this
        // nonce (or it expired): connecting again starts a new flow.
        $handoff = $this->store->pullDelivery($nonce, $user->id);
        if ($handoff === null) {
            Log::warning('Meta connect: no delivery from RugerTek Connect for this flow.', ['actor' => $user->id]);

            return $this->back()->withErrors(['meta_connect' => __('connectors.meta_connect_retry')]);
        }

        $previous = $this->store->get();
        $commerce = $handoff->commerceCatalogs();
        if ($commerce === []) {
            // Without a usable catalog the token is useless: nothing is kept and the panel explains what to do.
            $this->store->forget();
            Log::info('Meta connect: no commerce catalog shared.', ['actor' => $user->id, 'business_id' => $handoff->businessId, 'catalogs' => count($handoff->catalogs)]);

            return $this->back()->with('meta_connect_no_catalog', $handoff->catalogs === [] ? 'none' : 'generic');
        }
        $this->store->save(MetaConnection::connected($handoff->businessId, $handoff->graphVersion, $handoff->grantedScopes, $user->id), $handoff->accessToken);
        Log::info('Meta connect: connected.', ['actor' => $user->id, 'business_id' => $handoff->businessId, 'catalogs' => count($commerce)]);

        // The same business reconnecting with its catalog still shared keeps it; a single catalog is chosen at once.
        $kept = $previous->businessId === $handoff->businessId && $previous->catalogId !== null
            ? current(array_filter($commerce, fn (MetaCatalog $catalog): bool => $catalog->id === $previous->catalogId))
            : false;
        if ($kept instanceof MetaCatalog || count($commerce) === 1) {
            return $this->useCatalog($user, $kept instanceof MetaCatalog ? $kept : $commerce[0]);
        }
        $request->session()->put(self::SESSION_CATALOGS, [
            'user_id' => $user->id,
            'business_id' => $handoff->businessId,
            'expires_at' => now()->getTimestamp() + self::FLOW_TTL,
            'catalogs' => array_map(fn (MetaCatalog $catalog): array => $catalog->toArray(), $commerce),
        ]);

        return redirect()->route('admin.connectors.meta.catalog');
    }

    /** The choice screen when the client shared several `commerce` catalogs (or wants another one later). */
    public function chooseCatalog(Request $request): View|RedirectResponse
    {
        $user = $this->administrator($request);
        $connection = $this->store->get();
        if (! $connection->isConnected() || $connection->needsReconnect()) {
            return $this->back();
        }
        $catalogs = $this->catalogs($request, $user, $connection);
        if ($catalogs === null) {
            return $this->back()->withErrors(['meta_connect' => __('connectors.meta_catalogs_unreadable')]);
        }

        return view('admin.connectors.meta-catalog', [
            'catalogs' => $catalogs,
            'connection' => $connection,
            'commerceManagerUrl' => self::COMMERCE_MANAGER_URL,
        ]);
    }

    public function selectCatalog(Request $request): RedirectResponse
    {
        $user = $this->administrator($request);
        $connection = $this->store->get();
        if (! $connection->isConnected() || $connection->needsReconnect()) {
            return $this->back();
        }
        $id = (string) $request->input('catalog_id');
        $chosen = current(array_filter($this->catalogs($request, $user, $connection) ?? [], fn (MetaCatalog $catalog): bool => $catalog->id === $id));
        if (! $chosen instanceof MetaCatalog) {
            return redirect()->route('admin.connectors.meta.catalog')->withErrors(['catalog_id' => __('connectors.meta_catalog_invalid')]);
        }
        $request->session()->forget(self::SESSION_CATALOGS);

        return $this->useCatalog($user, $chosen);
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $user = $this->administrator($request);
        $connection = $this->store->get();
        if (! $connection->isConnected()) {
            return $this->back();
        }
        $deleteFeed = $request->boolean('delete_feed');
        $warning = null;
        try {
            app(MetaCatalogSync::class)->teardown($deleteFeed, $user);
        } catch (Throwable $exception) {
            $warning = __('connectors.meta_disconnect_meta_failed');
            Log::warning('Meta disconnect: Meta could not be updated.', ['actor' => $user->id, 'error' => $this->describe($exception)]);
        } finally {
            // The token and the Meta ids go always, whatever Meta answered.
            $this->store->forget();
            $request->session()->forget([self::SESSION_FLOW, self::SESSION_CATALOGS]);
        }
        Log::info('Meta disconnect: back to the manual feed.', ['actor' => $user->id, 'business_id' => $connection->businessId, 'delete_feed' => $deleteFeed]);

        $redirect = $this->back()->with('success', __('connectors.meta_disconnected'))->with('meta_disconnected', true);

        return $warning === null ? $redirect : $redirect->withErrors(['meta_connect' => $warning]);
    }

    /**
     * The catalog is decided: MetaCatalogSync::provision() (2c) keeps it, turns the feed on (the feed Meta reads needs
     * its URL), creates or reuses the scheduled feed in Meta and asks Meta to read it now; a Meta outage is queued
     * there and still returns. Whatever fails, the connection itself stays and the panel says what happened.
     */
    private function useCatalog(User $user, MetaCatalog $catalog): RedirectResponse
    {
        try {
            $connection = app(MetaCatalogSync::class)->provision($catalog->id, $user);
        } catch (ValidationException $exception) {
            return $this->back()->withErrors(['meta_connect' => $exception->validator->errors()->first()]);
        } catch (MetaCatalogSyncException $exception) {
            Log::warning('Meta connect: provisioning the catalog failed.', ['actor' => $user->id, 'catalog_id' => $catalog->id, 'reason' => $exception->reason]);

            return $this->back()->withErrors(['meta_connect' => $exception->getMessage()]);
        } catch (MetaGraphException $exception) {
            Log::warning('Meta connect: provisioning the catalog failed.', ['actor' => $user->id, 'catalog_id' => $catalog->id, 'error' => $this->describe($exception)]);
            if ($exception->revokesConnection()) {
                $this->store->markNeedsReconnect($exception->displayMessage());

                return $this->back()->withErrors(['meta_connect' => __('connectors.meta_reconnect_text')]);
            }

            return $this->back()->withErrors(['meta_connect' => $exception->objectUnavailable() ? __('connectors.meta_catalog_unshared') : __('connectors.meta_provision_failed')]);
        } catch (Throwable $exception) {
            Log::warning('Meta connect: provisioning the catalog failed.', ['actor' => $user->id, 'catalog_id' => $catalog->id, 'error' => $this->describe($exception)]);

            return $this->back()->withErrors(['meta_connect' => __('connectors.meta_provision_failed')]);
        }
        Log::info('Meta connect: catalog chosen.', ['actor' => $user->id, 'catalog_id' => $catalog->id]);

        return $this->back()->with('success', __('connectors.meta_connected', ['catalog' => $connection->catalogName ?? $catalog->name]));
    }

    /**
     * Site credentials with the broker, registering this origin the first time (the broker fetches the challenge
     * from this same site while verify() waits).
     *
     * @return array{0: string, 1: string}
     *
     * @throws RuwebConnectException
     */
    private function site(): array
    {
        $siteId = $this->store->connectSiteId();
        $secret = $this->store->connectSiteSecret();
        if ($siteId !== null && $secret !== null) {
            return [$siteId, $secret];
        }
        $name = trim((string) Setting::get('site_name'));
        $registration = $this->broker->register(SiteUrl::origin(), $name !== '' ? Str::limit($name, 120, '') : SiteUrl::origin());
        $this->store->startRegistration($registration->registrationId, $registration->challengeToken, $registration->expiresIn);
        $site = $this->broker->verify($registration->registrationId);
        $this->store->saveConnectSite($site->siteId, $site->siteSecret);

        return [$site->siteId, $site->siteSecret];
    }

    /**
     * The `commerce` catalogs to choose from: the list of the handoff kept in the session, or (later visits) what
     * Meta reports now. Null when Meta cannot be read.
     *
     * @return list<MetaCatalog>|null
     */
    private function catalogs(Request $request, User $user, MetaConnection $connection): ?array
    {
        $kept = $request->session()->get(self::SESSION_CATALOGS);
        if (is_array($kept) && ($kept['user_id'] ?? null) === $user->id && ($kept['business_id'] ?? null) === $connection->businessId
            && (int) ($kept['expires_at'] ?? 0) >= now()->getTimestamp() && is_array($kept['catalogs'] ?? null)) {
            return array_values(array_map(fn (array $catalog): MetaCatalog => MetaCatalog::fromGraph($catalog), array_filter($kept['catalogs'], 'is_array')));
        }
        try {
            $catalogs = app(MetaGraphClient::class)->assignedProductCatalogs();
        } catch (MetaGraphException $exception) {
            Log::warning('Meta connect: catalogs could not be read.', ['actor' => $user->id, 'error' => $this->describe($exception)]);
            if ($exception->revokesConnection()) {
                $this->store->markNeedsReconnect($exception->displayMessage());
            }

            return null;
        }

        return array_values(array_filter($catalogs, fn (MetaCatalog $catalog): bool => $catalog->isCommerce()));
    }

    private function administrator(Request $request): User
    {
        $user = $request->user();
        Gate::forUser($user)->authorize(ConnectorOperations::ABILITY);

        return $user;
    }

    private function back(): RedirectResponse
    {
        return redirect()->route('admin.connectors.show', ['connector' => MetaCatalogConnector::KEY])->withFragment('meta-connection');
    }

    private function registrationError(RuwebConnectException $exception): string
    {
        return match ($exception->errorCode) {
            RuwebConnectException::NOT_CONFIGURED, RuwebConnectException::INSECURE_URL => __('connectors.meta_connect_unavailable'),
            'challenge_failed', 'invalid_origin' => __('connectors.meta_register_unreachable', ['origin' => SiteUrl::origin()]),
            'site_disabled' => __('connectors.meta_register_disabled'),
            default => __('connectors.meta_connect_broker_down'),
        };
    }

    /** Exception summary for the log: class and message (Meta and broker errors never carry a secret). */
    private function describe(Throwable $exception): string
    {
        return class_basename($exception).': '.Str::limit($exception->getMessage(), 200);
    }
}
