<?php

namespace App\Services\Connectors;

use App\Connectors\AmibaChat\AmibaChatConnector;
use App\Connectors\ConnectorRegistry;
use App\Connectors\ConnectorRequirement;
use App\Connectors\FeedResult;
use App\Connectors\Meta\MetaCatalogConnector;
use App\Exceptions\RevisionConflict;
use App\Models\Connector;
use App\Models\User;
use App\Services\Connectors\Meta\MetaCatalogSync;
use App\Services\Shop\ShopModuleOperations;
use App\Support\ShopModule;
use App\Support\SiteUrl;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The single write path of connectors, shared by the panel (Admin > Connectors) and MCP (`connectors_*`). Every
 * actor operation requires an administrator (Gate ability `manage-connectors`) and runs in one transaction.
 * Unknown keys throw NotFoundHttpException (ConnectorRegistry::get()).
 */
final class ConnectorOperations
{
    /** Gate ability defined by App\Providers\ConnectorServiceProvider. */
    public const ABILITY = 'manage-connectors';

    /** A generated feed file older than this is rebuilt (PLAN.md §4.7). */
    public const FEED_TTL_SECONDS = 900;

    /** Cache key holding the microtime of the last touchFeeds(). */
    public const FEEDS_TOUCHED_KEY = 'connectors.feeds_touched_at';

    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly ShopModuleOperations $shopOperations,
        private readonly ShopModule $shop,
    ) {}

    /**
     * Turn the connector on. Creates its row the first time and generates the feed token once.
     *
     * @throws AuthorizationException when the actor is not an administrator
     * @throws ValidationException (`connector`) when a requirement is unmet
     */
    public function activate(User $actor, string $key): Connector
    {
        $this->authorize($actor);
        $unmet = $this->registry->unmet($key);
        if ($unmet !== []) {
            throw ValidationException::withMessages(['connector' => array_map(fn (ConnectorRequirement $requirement): string => __($requirement->message), $unmet)]);
        }

        return DB::transaction(function () use ($key) {
            $connector = $this->row($key);
            if ($connector->token_hash === null) {
                $this->fillToken($connector);
            }
            $connector->enabled = true;
            $connector->save();

            return $connector;
        });
    }

    /**
     * Turn the connector off: the feed answers 404; settings, token and counters are kept. A connector never
     * activated gets no row (the returned model is not persisted).
     *
     * @throws AuthorizationException when the actor is not an administrator
     */
    public function deactivate(User $actor, string $key): Connector
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($key) {
            $connector = $this->row($key);
            $connector->enabled = false;
            if ($connector->exists) {
                $connector->save();
            }

            return $connector;
        });
    }

    /**
     * Partial update: the given keys replace the stored ones, the result (over the connector defaults) is
     * validated with rules() and keys without a rule are dropped. Creates the row (disabled) if missing. The Amiba
     * chatbot accepts the whole snippet Amiba hands out as `handle` (AmibaChatConnector::normalizeSettings()).
     *
     * @param  array<string, mixed>  $settings
     * @param  string|null  $expectedRevision  revision() read by the client; null skips the check
     *
     * @throws AuthorizationException when the actor is not an administrator
     * @throws ValidationException on invalid settings or a malformed revision
     * @throws RevisionConflict (409) when the revision is stale
     */
    public function updateSettings(User $actor, string $key, array $settings, ?string $expectedRevision): Connector
    {
        $this->authorize($actor);
        $definition = $this->registry->get($key);
        if ($definition instanceof AmibaChatConnector) {
            $settings = AmibaChatConnector::normalizeSettings($settings);
        }

        return DB::transaction(function () use ($key, $definition, $settings, $expectedRevision) {
            $connector = $this->row($key, lock: true);
            $this->checkRevision($connector, $expectedRevision);
            $merged = array_replace($definition->defaults(), $connector->settings_json ?? [], $settings);
            $connector->settings_json = Validator::make($merged, $definition->rules())->validate();
            $connector->save();

            return $connector;
        });
    }

    /**
     * New feed token; the previous URL stops working at once. The plain token is returned for the panel only
     * (MCP must not expose it). For Meta connected by API (plan-meta-v2), MetaCatalogSync::refreshFeedUrl() then points
     * the scheduled feed in Meta at the new URL, so the client changes nothing there (a Meta failure never undoes the
     * rotation: it is retried in the queue or shown in the panel).
     *
     * @throws AuthorizationException when the actor is not an administrator
     * @throws ValidationException (`connector`) when the connector was never activated
     */
    public function rotateToken(User $actor, string $key): string
    {
        $this->authorize($actor);
        $this->registry->get($key);

        $token = DB::transaction(function () use ($key) {
            $connector = $this->row($key, lock: true);
            if (! $connector->exists) {
                throw ValidationException::withMessages(['connector' => __('connectors.error_not_activated')]);
            }
            $token = $this->fillToken($connector);
            $connector->save();

            return $token;
        });
        if ($key === MetaCatalogConnector::KEY) {
            try {
                app(MetaCatalogSync::class)->refreshFeedUrl();
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $token;
    }

    /**
     * Turn the product catalog on from a connector card (ShopModuleOperations::setMode(…, 'catalog')). A no-op when
     * the catalog is already on, so `checkout` is never lowered.
     *
     * @throws AuthorizationException when the actor is not an administrator
     * @throws ValidationException (`mode`) when `cms.shop.mode` does not allow the catalog
     */
    public function enableCatalog(User $actor): void
    {
        $this->authorize($actor);
        if (! $this->shop->catalog()) {
            $this->shopOperations->setMode($actor, 'catalog');
        }
    }

    /**
     * Walk the whole source through check() without writing the document: totals, the first $limit included items
     * and every warning. Empty while the source is unavailable. Read-only (no actor, no counters written).
     *
     * @throws NotFoundHttpException when the key is unknown or not a feed connector
     */
    public function preview(string $key, int $limit = 20): FeedResult
    {
        $connector = $this->registry->feed($key);
        $source = $this->registry->source($connector->source());
        if (! $source->available()) {
            return FeedResult::empty();
        }

        $settings = $this->registry->settings($key);
        $included = 0;
        $skipped = 0;
        $sample = [];
        $warnings = [];
        foreach ($source->items() as $item) {
            $itemWarnings = $connector->check($item, $settings);
            array_push($warnings, ...$itemWarnings);
            if (array_filter($itemWarnings, fn ($warning): bool => $warning->skips) !== []) {
                $skipped++;

                continue;
            }
            $included++;
            if (count($sample) < $limit) {
                $sample[] = $item;
            }
        }

        return new FeedResult($included, $skipped, $sample, $warnings);
    }

    /**
     * Log a feed request with a valid token (200 or 304): `last_fetched_at` and `last_fetch_agent` (≤ 255) always,
     * `last_meta_fetched_at` when the user-agent contains one of the connector's fetchAgents() (case-insensitive).
     */
    public function recordFetch(Connector $connector, ?string $userAgent): void
    {
        $agent = $userAgent === null || $userAgent === '' ? null : mb_substr($userAgent, 0, 255);
        $connector->last_fetched_at = now();
        $connector->last_fetch_agent = $agent;
        if ($agent !== null && $this->registry->has($connector->key)) {
            foreach ($this->registry->feed($connector->key)->fetchAgents() as $fragment) {
                if ($fragment !== '' && str_contains(mb_strtolower($agent), mb_strtolower($fragment))) {
                    $connector->last_meta_fetched_at = now();
                    break;
                }
            }
        }
        $connector->save();
    }

    /** Store the totals of the last generated feed file (shown on the card). */
    public function recordCounts(Connector $connector, FeedResult $result): void
    {
        $connector->last_item_count = $result->included;
        $connector->last_skipped_count = $result->skipped;
        $connector->save();
    }

    /** Optimistic revision clients send back as `expected_revision`: sha256 (64 hex) of the editable state. */
    public function revision(Connector $connector): string
    {
        $settings = $connector->settings_json ?? [];
        ksort($settings);

        return hash('sha256', json_encode([
            'key' => $connector->key,
            'enabled' => (bool) $connector->enabled,
            'settings' => $settings,
            'token_hash' => $connector->token_hash,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * Mark every generated feed file stale (a product, variant, media item or connector changed). Called by
     * App\Providers\ConnectorServiceProvider's model listeners; the file is rebuilt on the next request.
     */
    public function touchFeeds(): void
    {
        Cache::forever(self::FEEDS_TOUCHED_KEY, microtime(true));
    }

    /** Microtime of the last touchFeeds(), or null if never. */
    public function feedsTouchedAt(): ?float
    {
        $touched = Cache::get(self::FEEDS_TOUCHED_KEY);

        return is_numeric($touched) ? (float) $touched : null;
    }

    /**
     * Whether a feed file generated at $generatedAt (Unix seconds, e.g. filemtime()) must be rebuilt: older than
     * FEED_TTL_SECONDS, not newer than the last touchFeeds(), or, when $builtForOrigin is given, built for another
     * site origin than SiteUrl::origin() (APP_URL or the domain changed: its links point at the old address).
     * Errs toward rebuilding within the same second.
     */
    public function feedIsStale(int $generatedAt, ?string $builtForOrigin = null): bool
    {
        if ($builtForOrigin !== null && $builtForOrigin !== SiteUrl::origin()) {
            return true;
        }

        $touched = $this->feedsTouchedAt();

        return $generatedAt <= time() - self::FEED_TTL_SECONDS || ($touched !== null && $generatedAt <= $touched);
    }

    private function authorize(User $actor): void
    {
        Gate::forUser($actor)->authorize(self::ABILITY);
    }

    /** The stored row or a new unsaved one for a registered key. */
    private function row(string $key, bool $lock = false): Connector
    {
        $this->registry->get($key);
        $query = Connector::query()->where('key', $key);

        return ($lock ? $query->lockForUpdate() : $query)->first() ?? new Connector(['key' => $key, 'enabled' => false]);
    }

    private function fillToken(Connector $connector): string
    {
        $token = Str::random(48);
        $connector->token = $token;
        $connector->token_hash = Connector::hashToken($token);
        $connector->token_rotated_at = now();

        return $token;
    }

    /** Null skips (compatible clients), a malformed value is a validation error, a stale one is a 409. */
    private function checkRevision(Connector $connector, ?string $expected): void
    {
        if ($expected === null) {
            return;
        }
        Validator::make(['expected_revision' => $expected], ['expected_revision' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/']])->validate();
        $current = $this->revision($connector);
        if (! hash_equals($current, $expected)) {
            throw new RevisionConflict($expected, $current);
        }
    }
}
