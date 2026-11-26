<?php

namespace App\Services\Connectors\Meta;

use App\Connectors\ConnectorRegistry;
use App\Connectors\FeedWarning;
use App\Connectors\Meta\Connect\MetaConnectionStore;
use App\Connectors\Meta\Graph\MetaBatchIssue;
use App\Connectors\Meta\Graph\MetaBatchStatus;
use App\Connectors\Meta\Graph\MetaGraphException;
use App\Connectors\Meta\MetaCatalogConnector;
use App\Connectors\Meta\MetaItemFields;
use App\Connectors\Sources\ShopProductSource;
use App\Contracts\Connectors\MetaGraphClient;
use App\Jobs\CheckMetaItemsBatch;
use App\Jobs\PublishMetaItems;
use App\Models\Locale;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Instant catalog updates of Meta v2 (plan-meta-v2, track 3a). The hourly scheduled feed (MetaCatalogSync, 2c) stays
 * the source of truth — it replaces the whole catalog — and this is the fast path: when a product, variant, price,
 * stock or publication changes on a connected site, the affected products are remembered and, after DEBOUNCE_SECONDS
 * without waiting for more, ONE `items_batch` (PRODUCT_ITEM) goes to the chosen catalog: UPDATE for every item the
 * feed would include (Meta creates it when missing), DELETE for the ones that left (unpublished, deleted, a SKU that
 * changed, or now skipped by MetaCatalogConnector::check()). Fields come from MetaItemFields, the same mapping as the
 * feed. Then `check_batch_request_status` (CheckMetaItemsBatch, with backoff) and its errors AND warnings (an invalid
 * price comes back as a warning, spike §3.3.4) go to MetaCatalogSync::recordItemIssues(), per retailer id.
 *
 * Limits: ≤ MAX_ITEMS_PER_BATCH requests per call (split), a pause while MetaUsage::shouldSlowDown() and backoff on the
 * rate-limit codes (RATE_LIMIT_CODES) or a Meta outage; the pending products are kept until they are sent.
 * 190/102 → needs_reconnect, 100/33 on the catalog → needs_reconnect «quitaste el acceso» (the same texts as 2c).
 * Only ids and handles are kept (cache and job payloads): the token is read by MetaGraphClient when the call is made.
 * Manual mode (v1), no catalog chosen, the connector off or the shop catalog off: nothing is remembered or sent.
 */
final class MetaItemsPublisher
{
    /** Seconds the first change waits for more before the batch goes out. */
    public const DEBOUNCE_SECONDS = 60;

    /** Requests per items_batch call (Meta takes up to 5000; the brief fixes 3000). */
    public const MAX_ITEMS_PER_BATCH = 3000;

    public const ITEM_TYPE = 'PRODUCT_ITEM';

    public const METHOD_UPDATE = 'UPDATE';

    public const METHOD_DELETE = 'DELETE';

    /** Graph codes that mean «too many calls, wait»: 4/17/32 app/user/page, 613 call rate, 80009/80014 catalog. */
    public const RATE_LIMIT_CODES = [4, 17, 32, 613, 80009, 80014];

    /** Seconds before retrying a batch after a Meta outage or a rate limit, by attempt. */
    public const BACKOFF = [60, 300, 900, 3600];

    /** Shortest pause after Meta's usage header asked to slow down. */
    public const MIN_PAUSE_SECONDS = 60;

    private const PENDING_KEY = 'meta_items.pending';

    private const SCHEDULED_KEY = 'meta_items.scheduled';

    private const LOCK_KEY = 'meta_items.lock';

    private const PAUSE_KEY = 'meta_items.paused_until';

    /** retailer id => issue of the last batch that sent it: what the panel shows (MetaCatalogSync::recordItemIssues()). */
    private const ISSUES_KEY = 'meta_items.issues';

    public function __construct(
        private readonly MetaConnectionStore $store,
        private readonly MetaGraphClient $graph,
        private readonly ConnectorRegistry $registry,
        private readonly MetaCatalogSync $sync,
        private readonly MetaItemFields $fields,
    ) {}

    /**
     * Whether changes go to Meta right away: connected, a catalog chosen, no reconnection pending, the Meta connector
     * on and its requirements met (shop catalog).
     */
    public function enabled(): bool
    {
        if (! $this->store->get()->canSync()) {
            return false;
        }

        return (bool) $this->registry->record(MetaCatalogConnector::KEY)?->enabled && $this->registry->unmet(MetaCatalogConnector::KEY) === [];
    }

    /**
     * Products changed (App\Listeners\Connectors\MetaCatalogChanges): remember them and the retailer ids known to be
     * gone (a deleted variant, an old SKU), and schedule the batch once per debounce window. No-op when not enabled().
     *
     * @param  list<int>  $productIds
     * @param  list<string>  $goneRetailerIds
     */
    public function productsChanged(array $productIds, array $goneRetailerIds = []): void
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), fn (int $id): bool => $id > 0)));
        $goneRetailerIds = array_values(array_unique(array_filter(array_map(fn ($id): string => trim((string) $id), $goneRetailerIds), fn (string $id): bool => $id !== '')));
        if (($productIds === [] && $goneRetailerIds === []) || ! $this->enabled()) {
            return;
        }
        $this->merge($productIds, $goneRetailerIds);
        DB::afterCommit(fn () => $this->schedule());
    }

    /**
     * Products and retailer ids waiting for the next batch.
     *
     * @return array{products: list<int>, gone: list<string>}
     */
    public function pending(): array
    {
        $pending = Cache::get(self::PENDING_KEY);

        return [
            'products' => array_values(array_map('intval', array_keys(is_array($pending['products'] ?? null) ? $pending['products'] : []))),
            'gone' => array_values(array_map('strval', array_keys(is_array($pending['gone'] ?? null) ? $pending['gone'] : []))),
        ];
    }

    /**
     * @internal For PublishMetaItems: send what is pending. Returns null when done (sent, nothing to send, or dropped
     * because the connection cannot sync), or the seconds to wait before running again (paused by Meta's usage, rate
     * limited or Meta down; the pending products are kept). Revocation and other Meta errors are handled here.
     */
    public function publishPending(int $attempt = 1): ?int
    {
        Cache::forget(self::SCHEDULED_KEY);
        if (! $this->enabled()) {
            if (! $this->store->get()->canSync()) {
                Cache::forget(self::PENDING_KEY);
            }

            return null;
        }
        $wait = $this->pausedFor();
        if ($wait > 0) {
            return $this->retryIn($wait);
        }
        $pending = $this->take();
        if ($pending['products'] === [] && $pending['gone'] === []) {
            return null;
        }

        $batch = $this->build($pending['products'], $pending['gone']);
        if ($batch['requests'] === []) {
            return null;
        }
        $catalogId = (string) $this->store->get()->catalogId;
        $handles = [];
        $chunks = array_chunk($batch['requests'], $this->chunkSize());
        foreach ($chunks as $index => $requests) {
            try {
                array_push($handles, ...$this->graph->itemsBatch($catalogId, $requests, self::ITEM_TYPE));
            } catch (MetaGraphException $e) {
                if ($this->isRateLimit($e) || $e->isTransient()) {
                    $this->restore(array_slice($chunks, $index), $batch['products']);
                    $this->log('Meta did not take the items batch; it is retried later.', $e);
                    $this->pauseFromUsage();
                    $this->queueChecks($handles, $chunks, $index, $batch['deletes']);

                    return $this->retryIn(max($this->pausedFor(), self::BACKOFF[min(max($attempt, 1), count(self::BACKOFF)) - 1]));
                }
                $this->failure($e);

                return null;
            }
            if ($this->pauseFromUsage() && $index < count($chunks) - 1) {
                // Meta asked to slow down: the rest waits for the pause.
                $this->restore(array_slice($chunks, $index + 1), $batch['products']);
                $this->queueChecks($handles, $chunks, $index + 1, $batch['deletes']);

                return $this->retryIn($this->pausedFor());
            }
        }
        $this->queueChecks($handles, $chunks, count($chunks), $batch['deletes']);

        return null;
    }

    /**
     * @internal For CheckMetaItemsBatch: read the result of the handles. False while Meta is still processing one of
     * them; true once recorded (or nothing more to do). Revocation and other Meta errors are handled here; a
     * transient error is thrown so the job retries.
     *
     * @param  list<string>  $handles
     * @param  list<string>  $sent  every retailer id of these handles (their previous issues are replaced)
     * @param  list<string>  $deletes  retailer ids sent as DELETE: their problems are not shown (best effort; the
     *                                 hourly feed replaces the catalog anyway)
     *
     * @throws MetaGraphException when it isTransient() or a rate limit
     */
    public function checkBatches(array $handles, array $sent, array $deletes): bool
    {
        $connection = $this->store->get();
        if (! $connection->canSync() || $handles === []) {
            return true;
        }
        $statuses = [];
        try {
            foreach ($handles as $handle) {
                $status = $this->graph->batchStatus((string) $connection->catalogId, $handle);
                if (! $status->finished()) {
                    return false;
                }
                $statuses[] = $status;
            }
        } catch (MetaGraphException $e) {
            if ($this->isRateLimit($e) || $e->isTransient()) {
                throw $e;
            }
            $this->failure($e);

            return true;
        }
        $this->recordIssues($statuses, $sent, $deletes);

        return true;
    }

    /** @internal For the jobs: they gave up retrying (or run on the sync driver, which cannot wait). */
    public function giveUp(?MetaGraphException $e = null): void
    {
        Cache::forget(self::SCHEDULED_KEY);
        if ($e !== null) {
            $this->log('Meta items batch gave up after retries.', $e);
        }
        $this->store->recordHealth(MetaCatalogSync::text('meta_sync.error_meta_unavailable'));
        $this->sync->forgetStatus();
    }

    /**
     * The retailer ids a product stands for in the feed: its variants' (SKU or `v<id>`) when it has variants loaded,
     * else its own (SKU or `p<id>`) — App\Connectors\Sources\ShopProductSource.
     *
     * @return list<string>
     */
    public static function retailerIds(Product $product): array
    {
        if ($product->has_variants) {
            $variants = $product->relationLoaded('variants') ? $product->variants : $product->variants()->get();
            if ($variants->isNotEmpty()) {
                return $variants->map(fn (ProductVariant $variant): string => self::variantRetailerId($variant))->values()->all();
            }
        }

        return [self::productRetailerId($product)];
    }

    public static function productRetailerId(Product $product, ?string $sku = null): string
    {
        $sku ??= (string) $product->sku;

        return $sku !== '' ? $sku : 'p'.$product->id;
    }

    public static function variantRetailerId(ProductVariant $variant, ?string $sku = null): string
    {
        $sku ??= (string) $variant->sku;

        return $sku !== '' ? $sku : 'v'.$variant->id;
    }

    /**
     * The requests for these products, as the feed sees them now: UPDATE with MetaItemFields for every item check()
     * lets in, DELETE for the rest of their retailer ids and for $gone (minus the ones still present).
     *
     * @param  list<int>  $productIds
     * @param  list<string>  $gone
     * @return array{requests: list<array{method: string, data: array<string, string>}>, products: array<string, int>, deletes: list<string>}
     */
    private function build(array $productIds, array $gone): array
    {
        $base = Locale::baseCode();
        $wanted = [];
        $products = Product::query()->whereIn('id', $productIds)->with('variants')->get();
        foreach ($products as $product) {
            if ($product->locale === $base) {
                $wanted[self::productRetailerId($product)] = (int) $product->id;
            }
        }

        $connector = $this->registry->feed(MetaCatalogConnector::KEY);
        $source = $this->registry->source($connector->source());
        $settings = $this->registry->settings(MetaCatalogConnector::KEY);
        $productOf = [];
        $deletes = [];
        $included = [];
        $seen = [];
        if ($wanted !== [] && $source->available()) {
            // Only the changed products are read (ShopProductSource::itemsFor); another source streams the whole catalog
            // and only the changed products are kept.
            $items = $source instanceof ShopProductSource ? $source->itemsFor(array_values($wanted)) : $source->items();
            foreach ($items as $item) {
                $group = $item->groupId ?? $item->id;
                if (! isset($wanted[$group])) {
                    continue;
                }
                $seen[$group] = true;
                $productOf[$item->id] = $wanted[$group];
                $skips = array_filter($connector->check($item, $settings), fn (FeedWarning $warning): bool => $warning->skips) !== [];
                if ($skips) {
                    $deletes[$item->id] = true;
                } else {
                    $included[] = $item;
                }
            }
        }

        $updates = [];
        $urls = $this->fields->imageUrls($included);
        foreach ($included as $item) {
            $fields = MetaItemFields::fields($item, $settings, $urls);
            if ($fields === null) {
                $deletes[$item->id] = true;

                continue;
            }
            $updates[$item->id] = $fields;
        }
        // Unpublished, expired or otherwise out of the source: every id they stand for leaves the catalog.
        foreach ($products as $product) {
            $key = self::productRetailerId($product);
            if ($product->locale === $base && ! isset($seen[$key])) {
                foreach (self::retailerIds($product) as $id) {
                    $deletes[$id] = true;
                    $productOf[$id] = (int) $product->id;
                }
            }
        }
        foreach ($gone as $id) {
            $deletes[$id] = true;
        }
        $deletes = array_diff_key($deletes, $updates);

        $requests = [];
        foreach ($updates as $fields) {
            $requests[] = ['method' => self::METHOD_UPDATE, 'data' => $fields];
        }
        foreach (array_keys($deletes) as $id) {
            $requests[] = ['method' => self::METHOD_DELETE, 'data' => ['id' => (string) $id]];
        }

        return ['requests' => $requests, 'products' => array_map('intval', $productOf), 'deletes' => array_map('strval', array_keys($deletes))];
    }

    /**
     * Keep the issues of the finished batches: the retailer ids they sent drop their previous issues, their new
     * errors and warnings are added (not the ones of DELETE requests), and the whole list goes to the panel.
     *
     * @param  list<MetaBatchStatus>  $statuses
     * @param  list<string>  $sent
     * @param  list<string>  $deletes
     */
    private function recordIssues(array $statuses, array $sent, array $deletes): void
    {
        $stored = Cache::get(self::ISSUES_KEY);
        $issues = is_array($stored) ? $stored : [];
        foreach ($sent as $id) {
            unset($issues['id:'.$id]);
        }
        $issues = array_filter($issues, fn (string $key): bool => str_starts_with($key, 'id:'), ARRAY_FILTER_USE_KEY);
        $deleted = array_flip($deletes);
        foreach ($statuses as $status) {
            foreach ([MetaSyncIssue::SEVERITY_ERROR => $status->errors, MetaSyncIssue::SEVERITY_WARNING => $status->warnings] as $severity => $list) {
                foreach ($list as $index => $issue) {
                    if ($issue->retailerId !== null && isset($deleted[$issue->retailerId])) {
                        continue;
                    }
                    $key = $issue->retailerId !== null ? 'id:'.$issue->retailerId : 'batch:'.$status->handle.':'.$severity.':'.$index;
                    if (isset($issues[$key]) && $issues[$key]['severity'] === MetaSyncIssue::SEVERITY_ERROR) {
                        continue;
                    }
                    $issues[$key] = ['severity' => $severity, 'retailer_id' => $issue->retailerId, 'line' => $issue->line, 'message' => $issue->message];
                }
            }
        }
        Cache::forever(self::ISSUES_KEY, $issues);

        $errors = [];
        $warnings = [];
        foreach ($issues as $issue) {
            $item = new MetaBatchIssue($issue['retailer_id'], $issue['line'], (string) $issue['message']);
            if ($issue['severity'] === MetaSyncIssue::SEVERITY_ERROR) {
                $errors[] = $item;
            } else {
                $warnings[] = $item;
            }
        }
        $handle = implode(',', array_map(fn (MetaBatchStatus $status): string => $status->handle, $statuses));
        $this->sync->recordItemIssues(new MetaBatchStatus($handle, MetaBatchStatus::FINISHED, count($errors), $errors, $warnings));
    }

    /**
     * Queue the status check of the handles sent so far ($chunks before $upTo), with their retailer ids.
     *
     * @param  list<string>  $handles
     * @param  list<list<array{method: string, data: array<string, string>}>>  $chunks
     * @param  list<string>  $deletes
     */
    private function queueChecks(array $handles, array $chunks, int $upTo, array $deletes): void
    {
        if ($handles === []) {
            return;
        }
        $sent = [];
        foreach (array_slice($chunks, 0, $upTo) as $requests) {
            foreach ($requests as $request) {
                $sent[] = (string) $request['data']['id'];
            }
        }
        $sentDeletes = array_values(array_intersect($deletes, $sent));
        CheckMetaItemsBatch::dispatch($handles, $sent, $sentDeletes)->delay(now()->addSeconds(CheckMetaItemsBatch::FIRST_CHECK_SECONDS));
    }

    /**
     * 190/102 → needs_reconnect; 100/33 → the catalog was unshared: needs_reconnect saying which; anything else →
     * the connection's last error. The pending products are dropped (the hourly feed catches up).
     */
    private function failure(MetaGraphException $e): void
    {
        Cache::forget(self::PENDING_KEY);
        if ($e->revokesConnection()) {
            $this->store->markNeedsReconnect(MetaCatalogSync::text('meta_sync.error_revoked'));
        } elseif ($e->objectUnavailable()) {
            $connection = $this->store->get();
            $this->store->markNeedsReconnect(MetaCatalogSync::text('meta_sync.error_catalog_unavailable', ['catalog' => $connection->catalogName ?? $connection->catalogId ?? '']));
        } else {
            $this->log('Meta rejected an items batch.', $e);
            $this->store->recordHealth(MetaCatalogSync::text('meta_sync.error_meta_error', ['message' => $e->displayMessage()]));
        }
        $this->sync->forgetStatus();
    }

    private function isRateLimit(MetaGraphException $e): bool
    {
        return in_array($e->graphCode, self::RATE_LIMIT_CODES, true);
    }

    /** Store a pause when Meta's usage header of the last call asks to slow down. Returns whether it did. */
    private function pauseFromUsage(): bool
    {
        $seconds = 0;
        foreach ($this->graph->lastUsage() as $usage) {
            if ($usage->shouldSlowDown()) {
                $seconds = max($seconds, self::MIN_PAUSE_SECONDS, $usage->estimatedTimeToRegainAccess * 60);
            }
        }
        if ($seconds === 0) {
            return false;
        }
        $until = now()->getTimestamp() + $seconds;
        Cache::put(self::PAUSE_KEY, max($until, (int) Cache::get(self::PAUSE_KEY, 0)), $seconds);

        return true;
    }

    /** Seconds left of a pause asked by Meta (0 when none). */
    private function pausedFor(): int
    {
        return max(0, (int) Cache::get(self::PAUSE_KEY, 0) - now()->getTimestamp());
    }

    /** The job runs again in $seconds: changes meanwhile join it instead of queueing another one. */
    private function retryIn(int $seconds): int
    {
        $seconds = max(1, $seconds);
        Cache::put(self::SCHEDULED_KEY, true, $seconds + self::DEBOUNCE_SECONDS);

        return $seconds;
    }

    private function schedule(): void
    {
        if (! Cache::add(self::SCHEDULED_KEY, true, self::DEBOUNCE_SECONDS * 2)) {
            return;
        }
        $connection = (string) config('queue.default');
        if (config('queue.connections.'.$connection.'.driver') === 'sync') {
            // The sync driver cannot delay: one batch after the response gathers the changes of the request.
            PublishMetaItems::dispatchAfterResponse();

            return;
        }
        PublishMetaItems::dispatch()->delay(now()->addSeconds(self::DEBOUNCE_SECONDS));
    }

    /**
     * @param  list<int>  $productIds
     * @param  list<string>  $gone
     */
    private function merge(array $productIds, array $gone): void
    {
        $this->locked(function () use ($productIds, $gone): void {
            $pending = Cache::get(self::PENDING_KEY);
            $products = is_array($pending['products'] ?? null) ? $pending['products'] : [];
            $goneIds = is_array($pending['gone'] ?? null) ? $pending['gone'] : [];
            foreach ($productIds as $id) {
                $products[$id] = true;
            }
            foreach ($gone as $id) {
                $goneIds[$id] = true;
            }
            Cache::forever(self::PENDING_KEY, ['products' => $products, 'gone' => $goneIds]);
        });
    }

    /** @return array{products: list<int>, gone: list<string>} */
    private function take(): array
    {
        $pending = ['products' => [], 'gone' => []];
        $this->locked(function () use (&$pending): void {
            $pending = $this->pending();
            Cache::forget(self::PENDING_KEY);
        });

        return $pending;
    }

    /**
     * Put back the products and DELETE ids of the requests that were not sent.
     *
     * @param  list<list<array{method: string, data: array<string, string>}>>  $chunks
     * @param  array<string, int>  $productOf
     */
    private function restore(array $chunks, array $productOf): void
    {
        $products = [];
        $gone = [];
        foreach ($chunks as $requests) {
            foreach ($requests as $request) {
                $id = (string) $request['data']['id'];
                if (isset($productOf[$id])) {
                    $products[] = $productOf[$id];
                }
                if ($request['method'] === self::METHOD_DELETE) {
                    $gone[] = $id;
                }
            }
        }
        $this->merge(array_values(array_unique($products)), array_values(array_unique($gone)));
    }

    private function locked(callable $callback): void
    {
        try {
            Cache::lock(self::LOCK_KEY, 10)->block(5, $callback);
        } catch (LockTimeoutException $e) {
            // The hourly feed still carries the change; never break the save that triggered it.
            Log::warning('Meta items batch: pending changes could not be locked.', ['message' => $e->getMessage()]);
        }
    }

    private function chunkSize(): int
    {
        return max(1, min(self::MAX_ITEMS_PER_BATCH, (int) config('cms.meta.items_batch_size', self::MAX_ITEMS_PER_BATCH)));
    }

    private function log(string $message, Throwable $e): void
    {
        $context = ['message' => $e->getMessage()];
        if ($e instanceof MetaGraphException) {
            $context += ['code' => $e->graphCode, 'subcode' => $e->subcode, 'http_status' => $e->httpStatus, 'fbtrace_id' => $e->fbtraceId];
        }
        Log::warning($message, $context);
    }
}
