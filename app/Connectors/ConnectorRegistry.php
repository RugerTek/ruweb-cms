<?php

namespace App\Connectors;

use App\Contracts\Catalog\CatalogSource;
use App\Contracts\Connectors\Connector as ConnectorContract;
use App\Contracts\Connectors\FeedConnector;
use App\Models\Connector;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Read side of the connectors: `cms.connectors` and `cms.catalog_sources` (key => class, resolved through the
 * container on every call, so tests and config changes apply at once), their stored rows and their status.
 * Writes go through App\Services\Connectors\ConnectorOperations.
 */
final class ConnectorRegistry
{
    /**
     * Every registered connector, in config order.
     *
     * @return array<string, ConnectorContract>
     */
    public function all(): array
    {
        $connectors = [];
        foreach (array_keys(config('cms.connectors', [])) as $key) {
            $connectors[$key] = $this->get($key);
        }

        return $connectors;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, config('cms.connectors', []));
    }

    /**
     * @throws NotFoundHttpException when the key is not registered
     * @throws LogicException when the class does not implement the contract or reports another key
     */
    public function get(string $key): ConnectorContract
    {
        if (! $this->has($key)) {
            throw new NotFoundHttpException('Unknown connector.');
        }
        $connector = app(config('cms.connectors')[$key]);
        if (! $connector instanceof ConnectorContract || $connector->key() !== $key) {
            throw new LogicException('cms.connectors.'.$key.' must implement '.ConnectorContract::class.' and return "'.$key.'" from key().');
        }

        return $connector;
    }

    /**
     * @throws NotFoundHttpException when the key is unknown or the connector does not publish a feed
     */
    public function feed(string $key): FeedConnector
    {
        $connector = $this->get($key);
        if (! $connector instanceof FeedConnector) {
            throw new NotFoundHttpException('Not a feed connector.');
        }

        return $connector;
    }

    /**
     * @throws LogicException when the source is not registered or does not implement the contract
     */
    public function source(string $key): CatalogSource
    {
        $class = config('cms.catalog_sources', [])[$key] ?? null;
        $source = $class === null ? null : app($class);
        if (! $source instanceof CatalogSource || $source->key() !== $key) {
            throw new LogicException('cms.catalog_sources.'.$key.' must be registered, implement '.CatalogSource::class.' and return "'.$key.'" from key().');
        }

        return $source;
    }

    /** Stored row; null until the connector is activated for the first time. */
    public function record(string $key): ?Connector
    {
        return Connector::query()->where('key', $key)->first();
    }

    /**
     * Requirements the site does not meet right now.
     *
     * @return list<ConnectorRequirement>
     */
    public function unmet(string $key): array
    {
        return array_values(array_filter($this->get($key)->requirements(), fn (ConnectorRequirement $requirement): bool => ! $requirement->met));
    }

    /**
     * Active connectors that read the shop catalog (they declare the `shop_catalog` requirement, met or not):
     * turning the shop off makes their feeds answer 404. Settings > Shop lists them before confirming.
     *
     * @return array<string, ConnectorContract>
     */
    public function activeNeedingCatalog(): array
    {
        $active = [];
        foreach ($this->all() as $key => $connector) {
            $needsCatalog = array_filter($connector->requirements(), fn (ConnectorRequirement $requirement): bool => $requirement->key === ConnectorRequirement::SHOP_CATALOG) !== [];
            if ($needsCatalog && $this->record($key)?->enabled) {
                $active[$key] = $connector;
            }
        }

        return $active;
    }

    /** Unmet requirement → NeedsAction (even if active); active row → Connected; otherwise Available. */
    public function status(string $key): ConnectorStatus
    {
        if ($this->unmet($key) !== []) {
            return ConnectorStatus::NeedsAction;
        }

        return $this->record($key)?->enabled ? ConnectorStatus::Connected : ConnectorStatus::Available;
    }

    /**
     * Effective settings: the connector defaults overridden by the stored ones.
     *
     * @return array<string, mixed>
     */
    public function settings(string $key): array
    {
        return array_replace($this->get($key)->defaults(), $this->record($key)?->settings_json ?? []);
    }

    /**
     * The row behind a public feed request, or null when the request must get a 404: unknown or non-feed
     * connector, never activated, disabled, a requirement unmet (shop off) or a token that does not match.
     */
    public function feedRecord(string $key, string $token): ?Connector
    {
        if (! $this->has($key) || ! $this->get($key) instanceof FeedConnector || $this->unmet($key) !== []) {
            return null;
        }
        $record = $this->record($key);

        return $record !== null && $record->enabled && $record->matchesToken($token) ? $record : null;
    }
}
