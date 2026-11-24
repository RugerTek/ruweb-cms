<?php

namespace App\Services\Connectors\Meta;

use App\Connectors\Meta\Connect\MetaConnection;
use App\Connectors\Meta\Connect\MetaConnectionStore;
use App\Connectors\Meta\Graph\MetaBatchIssue;
use App\Connectors\Meta\Graph\MetaBatchStatus;
use App\Connectors\Meta\Graph\MetaGraphException;
use App\Connectors\Meta\Graph\MetaProductFeed;
use App\Connectors\Meta\Graph\MetaUploadError;
use App\Connectors\Meta\MetaCatalogConnector;
use App\Contracts\Connectors\MetaGraphClient;
use App\Jobs\SyncMetaCatalogFeed;
use App\Models\Connector;
use App\Models\Locale;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use App\Services\Connectors\ConnectorOperations;
use App\Support\SiteUrl;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Meta v2 catalog sync through the Graph API (plan-meta-v2, track 2c). Once the client connected with «Conectar con
 * Facebook» (track 2b) and chose a «Productos online» catalog, Ruweb creates in that catalog a scheduled feed
 * (`product_feeds`, HOURLY, replace) pointing at its own /feeds/meta/{token}.xml, asks Meta to read it at once
 * (`uploads`) and shows the result of the last upload in the panel. Verified against Graph v27 in the spike (§3.3):
 * the schedule URL can be changed in place (regenerating the feed token needs nothing from the client), a failed
 * upload never deletes the products already in the catalog, and the catalog itself is never deleted here.
 *
 * Errors of Meta (MetaGraphException):
 * - 190 (any subcode) / 102 → the client removed the app: MetaConnectionStore::markNeedsReconnect() drops the token.
 * - 100/33 on the chosen catalog → the client unshared it: needs_reconnect too, with «quitaste el acceso al catálogo»
 *   (reconnecting is how the client shares it again). 100/33 on the feed only → it was deleted in Commerce Manager:
 *   «Sincronizar ahora» creates it again.
 * - transient (network, 5xx, rate limits) → SyncMetaCatalogFeed retries with backoff; the panel never breaks.
 * Anything else is recorded as the connection's last error and logged (no token: MetaGraphException strips it).
 */
final class MetaCatalogSync
{
    /** Feed upload problems and item problems kept for the panel. */
    public const ISSUES_LIMIT = 20;

    /** Seconds status() is cached (Meta reads the feed at most once an hour). */
    public const STATUS_TTL = 300;

    /** Seconds a status read while Meta was unavailable is cached. */
    public const UNAVAILABLE_TTL = 60;

    /** Seconds between two «Sincronizar ahora». */
    public const MANUAL_COOLDOWN = 60;

    public const FEED_INTERVAL = 'HOURLY';

    /** Prefix of the name of the feed Ruweb creates: «Ruweb – <site name>». */
    public const FEED_NAME_PREFIX = 'Ruweb – ';

    private const STATUS_KEY = 'meta_sync.status';

    private const STATUS_VERSION_KEY = 'meta_sync.status_version';

    private const ITEM_ISSUES_KEY = 'meta_sync.item_issues';

    private const COOLDOWN_KEY = 'meta_sync.manual_cooldown';

    public function __construct(
        private readonly MetaConnectionStore $store,
        private readonly MetaGraphClient $graph,
        private readonly ConnectorOperations $operations,
    ) {}

    /**
     * The client chose $catalogId (2b): keep it, turn the Meta connector on (its feed URL needs the token), create the
     * scheduled feed in that catalog — or reuse the Ruweb feed already there, by name or URL — point it at the current
     * feed URL, ask Meta to read it now and keep its id. When Meta does not answer, the catalog is kept and the rest is
     * queued (SyncMetaCatalogFeed): the call still returns.
     *
     * @throws AuthorizationException when $actor is not an administrator
     * @throws ValidationException (`connector`) when the shop catalog is off (ConnectorOperations::activate())
     * @throws MetaCatalogSyncException not connected, revoked (190/102), catalog not shared (100/33) or not «Productos online», any other Meta error
     */
    public function provision(string $catalogId, User $actor): MetaConnection
    {
        Gate::forUser($actor)->authorize(ConnectorOperations::ABILITY);
        $connection = $this->usableConnection();

        try {
            $catalog = $this->graph->catalog($catalogId);
        } catch (MetaGraphException $e) {
            if ($e->isTransient()) {
                $this->operations->activate($actor, MetaCatalogConnector::KEY);
                $this->store->save($connection->withCatalog($catalogId, $catalogId === $connection->catalogId ? $connection->catalogName : null));
                $this->queue(SyncMetaCatalogFeed::PROVISION, $e);

                return $this->store->get();
            }
            if ($e->objectUnavailable() && $catalogId !== $connection->catalogId) {
                // A catalog the client did not share (or no longer): nothing stored changes.
                throw MetaCatalogSyncException::for(MetaCatalogSyncException::CATALOG_UNAVAILABLE, ['catalog' => $catalogId]);
            }
            throw $this->failure($e, $connection->catalogId);
        }
        if (! $catalog->isCommerce()) {
            throw MetaCatalogSyncException::for(MetaCatalogSyncException::CATALOG_NOT_COMMERCE, ['catalog' => $catalog->name]);
        }

        $this->operations->activate($actor, MetaCatalogConnector::KEY);
        $this->store->save($connection->withCatalog($catalogId, $catalog->name));
        $this->syncFeed($actor);

        return $this->store->get();
    }

    /**
     * Disconnecting (2b): with $deleteFeed, delete the feed Ruweb created in the catalog (a failure of Meta is logged
     * and does not stop anything), then forget the connection and its token. The client's catalog and its products are
     * never deleted; the connector stays as it was (manual mode, v1, keeps its feed URL).
     *
     * @throws AuthorizationException when $actor is not an administrator
     */
    public function teardown(bool $deleteFeed, User $actor): void
    {
        Gate::forUser($actor)->authorize(ConnectorOperations::ABILITY);
        $connection = $this->store->get();
        if ($deleteFeed && $connection->isConnected() && ! $connection->needsReconnect() && $connection->productFeedId !== null) {
            try {
                $this->graph->deleteProductFeed($connection->productFeedId);
            } catch (MetaGraphException $e) {
                $this->log('Meta feed could not be deleted while disconnecting.', $e);
            }
        }
        $this->store->forget();
        Cache::forget(self::ITEM_ISSUES_KEY);
        $this->forgetStatus();
    }

    /**
     * The feed token was regenerated (ConnectorOperations::rotateToken()): point the scheduled feed at the new URL so
     * the client has nothing to change in Meta. No-op without a synced connection. Never throws: a Meta outage queues
     * a retry, any other error is recorded for the panel.
     */
    public function refreshFeedUrl(): void
    {
        $connection = $this->store->get();
        if (! $connection->canSync() || $connection->productFeedId === null) {
            return;
        }
        try {
            $this->runQueued(SyncMetaCatalogFeed::REFRESH_URL);
        } catch (MetaGraphException $e) {
            $this->queue(SyncMetaCatalogFeed::REFRESH_URL, $e);
        }
    }

    /**
     * «Sincronizar ahora»: make sure the feed exists and points at the current URL, then ask Meta to read it. At most
     * once every MANUAL_COOLDOWN seconds. A Meta outage queues it and returns normally.
     *
     * @throws AuthorizationException when $actor is not an administrator
     * @throws MetaCatalogSyncException
     */
    public function syncNow(User $actor): MetaConnection
    {
        Gate::forUser($actor)->authorize(ConnectorOperations::ABILITY);
        $connection = $this->usableConnection();
        if ($connection->catalogId === null) {
            throw MetaCatalogSyncException::for(MetaCatalogSyncException::NOT_CONNECTED);
        }
        if (! Cache::add(self::COOLDOWN_KEY, true, self::MANUAL_COOLDOWN)) {
            throw MetaCatalogSyncException::for(MetaCatalogSyncException::TOO_SOON, ['seconds' => self::MANUAL_COOLDOWN]);
        }
        $this->syncFeed($actor);

        return $this->store->get();
    }

    /**
     * The last upload of the Ruweb feed as Meta reports it, with its problems (≤ ISSUES_LIMIT) and the item problems of
     * the last items_batch. Cached STATUS_TTL seconds; reading it may flag needs_reconnect (190/102, catalog 100/33).
     * Without a usable connection it answers at once, without calling Meta.
     */
    public function status(): MetaSyncStatus
    {
        $connection = $this->store->get();
        if (! $connection->isConnected()) {
            return MetaSyncStatus::of(MetaSyncStatus::NOT_CONNECTED);
        }
        if ($connection->needsReconnect()) {
            return $this->describe(MetaSyncStatus::of(MetaSyncStatus::NEEDS_RECONNECT, $connection->lastError), $connection);
        }
        if ($connection->catalogId === null) {
            return MetaSyncStatus::of(MetaSyncStatus::NO_CATALOG);
        }

        $key = self::STATUS_KEY.'.'.sha1($connection->catalogId.'|'.$connection->productFeedId.'|'.Cache::get(self::STATUS_VERSION_KEY, 0));
        $cached = Cache::get($key);
        if ($cached instanceof MetaSyncStatus) {
            return $cached;
        }
        $status = $this->readStatus($connection);
        if ($status->state !== MetaSyncStatus::NEEDS_RECONNECT && $status->state !== MetaSyncStatus::NO_FEED) {
            Cache::put($key, $status, $status->state === MetaSyncStatus::UNAVAILABLE ? self::UNAVAILABLE_TTL : self::STATUS_TTL);
        }

        return $status;
    }

    /** Drop the cached status (after any change; the panel reads Meta again). */
    public function forgetStatus(): void
    {
        Cache::forever(self::STATUS_VERSION_KEY, (int) Cache::get(self::STATUS_VERSION_KEY, 0) + 1);
    }

    /**
     * Keep the errors and warnings of an items_batch (track 3a) for the panel, each one linked to its product through
     * the retailer id (the feed's g:id). Replaces the previous batch's problems; an empty status clears them.
     */
    public function recordItemIssues(MetaBatchStatus $batch): void
    {
        $issues = [];
        foreach ([MetaSyncIssue::SEVERITY_ERROR => $batch->errors, MetaSyncIssue::SEVERITY_WARNING => $batch->warnings] as $severity => $list) {
            foreach ($list as $issue) {
                if ($issue instanceof MetaBatchIssue && count($issues) < self::ISSUES_LIMIT) {
                    $issues[] = $this->itemIssue($severity, $issue)->toArray();
                }
            }
        }
        Cache::forever(self::ITEM_ISSUES_KEY, ['issues' => $issues, 'total' => count($batch->errors) + count($batch->warnings)]);
        $this->forgetStatus();
    }

    /**
     * The product a retailer id of Meta stands for: the feed writes the SKU when there is one, else `p<id>` (product)
     * or `v<id>` (variant) — App\Connectors\Sources\ShopProductSource.
     */
    public function productForRetailerId(string $retailerId): ?Product
    {
        $retailerId = trim($retailerId);
        if ($retailerId === '') {
            return null;
        }
        $base = Locale::baseCode();
        $product = Product::query()->where('sku', $retailerId)->where('locale', $base)->first()
            ?? ProductVariant::query()->where('sku', $retailerId)->first()?->product;
        if ($product === null && preg_match('/^p(\d+)$/', $retailerId, $match) === 1) {
            $product = Product::query()->find((int) $match[1]);
        }
        if ($product === null && preg_match('/^v(\d+)$/', $retailerId, $match) === 1) {
            $product = ProductVariant::query()->find((int) $match[1])?->product;
        }

        return $product;
    }

    /**
     * @internal For SyncMetaCatalogFeed (and refreshFeedUrl()): run one queued action. Meta errors that are not
     * transient are handled here (needs_reconnect or the connection's last error); transient ones are thrown so the
     * job retries later.
     *
     * @throws MetaGraphException only when it isTransient()
     */
    public function runQueued(string $action): void
    {
        $connection = $this->store->get();
        if (! $connection->canSync()) {
            return;
        }
        try {
            match ($action) {
                SyncMetaCatalogFeed::PROVISION => $this->provisionQueued($connection),
                SyncMetaCatalogFeed::REFRESH_URL => $this->refreshQueued($connection),
                default => $this->syncFeed(null),
            };
        } catch (MetaGraphException $e) {
            if ($e->isTransient()) {
                throw $e;
            }
            $this->failure($e, $connection->catalogId);
        } catch (MetaCatalogSyncException $e) {
            $this->store->recordHealth($e->getMessage());
            $this->forgetStatus();
        }
    }

    /** @internal For SyncMetaCatalogFeed: it gave up retrying a transient error. */
    public function giveUp(MetaGraphException $e): void
    {
        $this->log('Meta catalog sync gave up after retries.', $e);
        $this->store->recordHealth(self::text('meta_sync.error_meta_unavailable'));
        $this->forgetStatus();
    }

    /** Absolute URL of the Meta feed with the current token (APP_URL origin, like the feed's own links), or null. */
    public function feedUrl(): ?string
    {
        $token = Connector::query()->where('key', MetaCatalogConnector::KEY)->first()?->plainToken();

        return $token === null ? null : SiteUrl::origin().route('connectors.feed', ['connector' => MetaCatalogConnector::KEY, 'token' => $token], false);
    }

    /**
     * A meta_sync text in the panel language (`admin_locale`), also from jobs and MCP: what is stored as the
     * connection's last error is read later in the panel.
     *
     * @param  array<string, string|int>  $replace
     */
    public static function text(string $key, array $replace = []): string
    {
        try {
            $locale = (string) Setting::get('admin_locale', 'es');
        } catch (Throwable) {
            $locale = 'es';
        }

        return (string) __($key, $replace, $locale !== '' ? $locale : 'es');
    }

    /** Commerce Manager › Data sources of the chosen catalog, always with the client's business_id (never another portfolio). */
    public function commerceManagerUrl(): ?string
    {
        $connection = $this->store->get();
        if ($connection->catalogId === null || $connection->businessId === null) {
            return null;
        }

        return 'https://business.facebook.com/commerce/'.rawurlencode($connection->catalogId).'/data_sources/?business_id='.rawurlencode($connection->businessId);
    }

    /** «Ruweb – <site name>», the name of the feed Ruweb creates (and recognizes) in the catalog. */
    public function feedName(): string
    {
        $name = trim((string) Setting::get('site_name'));

        return self::FEED_NAME_PREFIX.mb_substr($name !== '' ? $name : (string) config('app.name'), 0, 80);
    }

    /** The job re-checks the catalog the panel could not verify while Meta was down. */
    private function provisionQueued(MetaConnection $connection): void
    {
        $catalog = $this->graph->catalog((string) $connection->catalogId);
        if (! $catalog->isCommerce()) {
            $this->store->save($connection->withCatalog((string) $connection->catalogId, $catalog->name));
            throw MetaCatalogSyncException::for(MetaCatalogSyncException::CATALOG_NOT_COMMERCE, ['catalog' => $catalog->name]);
        }
        if ($catalog->name !== $connection->catalogName) {
            $this->store->save($connection->withCatalog((string) $connection->catalogId, $catalog->name));
        }
        $this->syncFeed(null);
    }

    private function refreshQueued(MetaConnection $connection): void
    {
        $url = $this->requireFeedUrl(null);
        try {
            $this->graph->updateProductFeedSchedule((string) $connection->productFeedId, $url, self::FEED_INTERVAL);
        } catch (MetaGraphException $e) {
            if (! $e->objectUnavailable()) {
                throw $e;
            }
            // The feed is gone (or the catalog was unshared): ensureFeed() tells which and recreates it if it can.
            $this->syncFeed(null);

            return;
        }
        $this->store->recordHealth(null);
        $this->forgetStatus();
    }

    /**
     * Ensure the scheduled feed (create, reuse or repoint it) and ask Meta to read it now. A transient error queues the
     * whole step when called from the panel ($actor given) and is thrown back to the job otherwise.
     *
     * @throws MetaCatalogSyncException from the panel
     * @throws MetaGraphException from the job
     */
    private function syncFeed(?User $actor): void
    {
        $connection = $this->store->get();
        try {
            $url = $this->requireFeedUrl($actor);
            $feedId = $this->ensureFeed($connection, $url);
            $this->store->save($this->store->get()->withProductFeed($feedId));
            $this->graph->createUpload($feedId, $url);
        } catch (MetaGraphException $e) {
            if ($actor === null) {
                throw $e;
            }
            if ($e->isTransient()) {
                $this->queue(SyncMetaCatalogFeed::UPLOAD, $e);

                return;
            }
            throw $this->failure($e, $connection->catalogId);
        }
        $this->store->recordHealth(null);
        $this->forgetStatus();
    }

    /** The id of the Ruweb feed in the catalog, pointing at $url (created when there is none). */
    private function ensureFeed(MetaConnection $connection, string $url): string
    {
        $catalogId = (string) $connection->catalogId;
        $feed = null;
        if ($connection->productFeedId !== null) {
            try {
                $feed = $this->graph->productFeed($connection->productFeedId);
            } catch (MetaGraphException $e) {
                if (! $e->objectUnavailable()) {
                    throw $e;
                }
                // 100/33: deleted in Commerce Manager, or the whole catalog was unshared (the next call tells).
                $this->graph->catalog($catalogId);
            }
        }
        $feed ??= $this->findRuwebFeed($catalogId);
        if ($feed === null) {
            return $this->graph->createProductFeed($catalogId, $this->feedName(), $this->currency(), $url, self::FEED_INTERVAL);
        }
        if ($feed->scheduleUrl !== $url || $feed->scheduleInterval !== self::FEED_INTERVAL) {
            $this->graph->updateProductFeedSchedule($feed->id, $url, self::FEED_INTERVAL);
        }

        return $feed->id;
    }

    /** A feed of this catalog that Ruweb created before: same name, or a schedule URL of this site's Meta feed. */
    private function findRuwebFeed(string $catalogId): ?MetaProductFeed
    {
        $prefix = SiteUrl::origin().'/feeds/'.MetaCatalogConnector::KEY.'/';
        foreach ($this->graph->productFeeds($catalogId) as $feed) {
            if ($feed->name === $this->feedName() || ($feed->scheduleUrl !== null && str_starts_with($feed->scheduleUrl, $prefix))) {
                return $feed;
            }
        }

        return null;
    }

    private function readStatus(MetaConnection $connection): MetaSyncStatus
    {
        if ($connection->productFeedId === null) {
            return $this->describe(MetaSyncStatus::of(MetaSyncStatus::NO_FEED, $connection->lastError), $connection);
        }
        try {
            $feed = $this->graph->productFeed($connection->productFeedId);
            $upload = $feed->latestUploadId === null ? null : $this->graph->upload($feed->latestUploadId);
            $issues = [];
            if ($upload !== null && $upload->finished() && $upload->errorCount + $upload->warningCount > 0) {
                $issues = array_map(fn (MetaUploadError $error): MetaSyncIssue => new MetaSyncIssue(
                    in_array($error->severity, ['warning', 'info'], true) ? MetaSyncIssue::SEVERITY_WARNING : MetaSyncIssue::SEVERITY_ERROR,
                    $error->summary,
                    $error->description,
                    $error->errorType,
                    $error->totalCount,
                ), $this->graph->uploadErrors($upload->id));
            }
        } catch (MetaGraphException $e) {
            return $this->statusAfterFailure($e, $connection);
        }

        $stored = Cache::get(self::ITEM_ISSUES_KEY);
        $itemIssues = is_array($stored['issues'] ?? null) ? array_map(fn (array $issue): MetaSyncIssue => MetaSyncIssue::fromArray($issue), $stored['issues']) : [];
        $all = array_merge($issues, $itemIssues);
        $hasErrors = array_filter($all, fn (MetaSyncIssue $issue): bool => $issue->isError()) !== [];
        $state = match (true) {
            $upload === null || ! $upload->finished() => MetaSyncStatus::PROCESSING,
            $upload->errorCount > 0 || $hasErrors => MetaSyncStatus::WITH_ERRORS,
            default => MetaSyncStatus::SYNCED,
        };

        return new MetaSyncStatus(
            $state,
            catalogId: $connection->catalogId,
            catalogName: $connection->catalogName,
            feedId: $feed->id,
            productCount: $feed->productCount,
            uploadId: $upload?->id,
            uploadStartedAt: $upload?->startTime,
            uploadFinishedAt: $upload?->endTime,
            detectedItems: $upload?->detectedItems ?? 0,
            persistedItems: $upload?->persistedItems ?? 0,
            invalidItems: $upload?->invalidItems ?? 0,
            errorCount: $upload?->errorCount ?? 0,
            warningCount: $upload?->warningCount ?? 0,
            issues: array_slice($all, 0, self::ISSUES_LIMIT),
            issuesTotal: count($issues) + (int) ($stored['total'] ?? count($itemIssues)),
            message: $connection->lastError,
            checkedAt: new DateTimeImmutable,
        );
    }

    private function statusAfterFailure(MetaGraphException $e, MetaConnection $connection, bool $probed = false): MetaSyncStatus
    {
        if ($e->isTransient() || $e->isConnectionFailure()) {
            $this->log('Meta did not answer the sync status.', $e);

            return $this->describe(MetaSyncStatus::of(MetaSyncStatus::UNAVAILABLE, self::text('meta_sync.error_meta_unavailable')), $connection);
        }
        if ($e->objectUnavailable() && ! $probed) {
            // The feed answered 100/33: was the whole catalog unshared, or only the feed deleted?
            try {
                $this->graph->catalog((string) $connection->catalogId);
            } catch (MetaGraphException $catalogError) {
                return $this->statusAfterFailure($catalogError, $connection, probed: true);
            }
            $this->store->save($connection->withProductFeed(null));
            $this->forgetStatus();

            return $this->describe(MetaSyncStatus::of(MetaSyncStatus::NO_FEED, self::text('meta_sync.error_feed_missing')), $connection);
        }
        $error = $this->failure($e, $connection->catalogId);
        $connection = $this->store->get();

        return $connection->needsReconnect()
            ? $this->describe(MetaSyncStatus::of(MetaSyncStatus::NEEDS_RECONNECT, $connection->lastError), $connection)
            : $this->describe(MetaSyncStatus::of(MetaSyncStatus::UNAVAILABLE, $error->getMessage()), $connection);
    }

    private function describe(MetaSyncStatus $status, MetaConnection $connection): MetaSyncStatus
    {
        return new MetaSyncStatus($status->state, catalogId: $connection->catalogId, catalogName: $connection->catalogName, feedId: $connection->productFeedId, message: $status->message, checkedAt: $status->checkedAt);
    }

    /**
     * Handle a Meta error that is not transient: 190/102 → needs_reconnect; 100/33 on the stored catalog ($catalogId)
     * → needs_reconnect «quitaste el acceso al catálogo»; anything else → last error. Returns the exception for the panel.
     */
    private function failure(MetaGraphException $e, ?string $catalogId, string $unavailableReason = MetaCatalogSyncException::CATALOG_UNAVAILABLE): MetaCatalogSyncException
    {
        $this->forgetStatus();
        if ($e->revokesConnection()) {
            $this->store->markNeedsReconnect(self::text('meta_sync.error_revoked'));

            return MetaCatalogSyncException::for(MetaCatalogSyncException::REVOKED);
        }
        if ($e->objectUnavailable()) {
            $connection = $this->store->get();
            $name = $connection->catalogName ?? $connection->catalogId ?? '';
            if ($catalogId !== null) {
                $this->store->markNeedsReconnect(self::text('meta_sync.error_catalog_unavailable', ['catalog' => $name]));
            }

            return MetaCatalogSyncException::for($unavailableReason, ['catalog' => $name]);
        }
        $this->log('Meta rejected a catalog sync call.', $e);
        $message = self::text('meta_sync.error_meta_error', ['message' => $e->displayMessage()]);
        $this->store->recordHealth($message);

        return new MetaCatalogSyncException(MetaCatalogSyncException::META_ERROR, $message);
    }

    /** A connected, not revoked connection, else NOT_CONNECTED / REVOKED. */
    private function usableConnection(): MetaConnection
    {
        $connection = $this->store->get();
        if (! $connection->isConnected()) {
            throw MetaCatalogSyncException::for(MetaCatalogSyncException::NOT_CONNECTED);
        }
        if ($connection->needsReconnect()) {
            throw MetaCatalogSyncException::for(MetaCatalogSyncException::REVOKED);
        }

        return $connection;
    }

    /** The feed URL; from the panel a token encrypted with another APP_KEY is regenerated (the old URL is dead anyway). */
    private function requireFeedUrl(?User $actor): string
    {
        $url = $this->feedUrl();
        if ($url === null && $actor !== null) {
            $this->operations->rotateToken($actor, MetaCatalogConnector::KEY);
            $url = $this->feedUrl();
        }

        return $url ?? throw MetaCatalogSyncException::for(MetaCatalogSyncException::NO_FEED_URL);
    }

    private function queue(string $action, MetaGraphException $e): void
    {
        $this->log('Meta did not answer; the catalog sync is queued.', $e);
        $this->store->recordHealth(self::text('meta_sync.error_meta_unavailable'));
        $this->forgetStatus();
        SyncMetaCatalogFeed::dispatch($action);
    }

    private function itemIssue(string $severity, MetaBatchIssue $issue): MetaSyncIssue
    {
        $product = $issue->retailerId === null ? null : $this->productForRetailerId($issue->retailerId);

        return new MetaSyncIssue(
            $severity,
            $issue->message,
            retailerId: $issue->retailerId,
            productTitle: $product?->title,
            editUrl: $product !== null && Route::has('admin.products.edit') ? route('admin.products.edit', $product, false) : null,
        );
    }

    private function currency(): string
    {
        return strtoupper((string) config('cms.shop.currency', 'PYG'));
    }

    private function log(string $message, MetaGraphException $e): void
    {
        Log::warning($message, ['code' => $e->graphCode, 'subcode' => $e->subcode, 'http_status' => $e->httpStatus, 'message' => $e->getMessage(), 'fbtrace_id' => $e->fbtraceId]);
    }
}
