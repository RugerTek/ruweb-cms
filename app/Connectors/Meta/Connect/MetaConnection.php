<?php

namespace App\Connectors\Meta\Connect;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * How the Meta catalog connector reaches Meta (plan-meta-v2): `manual` (v1, the client pastes the feed URL in Commerce
 * Manager; nothing else is stored) or `connected` («Conectar con Facebook»: a system user token obtained through the
 * RugerTek Connect broker, kept encrypted by MetaConnectionStore). Immutable: change it with the with*() methods and
 * save it through MetaConnectionStore. It never holds the token itself, only its last 4 characters.
 *
 * The identity of a connection is businessId (`/me.client_business_id`) + the token: the system user id changes on
 * every reconnection (spike §4), so it is not kept. needsReconnect() is set when Meta answered 190/102 (the client
 * removed the app): the token is dropped and the panel asks to connect again.
 */
final class MetaConnection
{
    public const MODE_MANUAL = 'manual';

    public const MODE_CONNECTED = 'connected';

    /** Longest error text kept (lastError). */
    public const ERROR_MAX = 300;

    /**
     * @param  list<string>  $grantedScopes
     */
    public function __construct(
        public readonly string $mode = self::MODE_MANUAL,
        public readonly ?string $businessId = null,
        public readonly ?string $catalogId = null,
        public readonly ?string $catalogName = null,
        public readonly ?string $productFeedId = null,
        public readonly ?string $graphVersion = null,
        public readonly array $grantedScopes = [],
        public readonly ?DateTimeImmutable $connectedAt = null,
        public readonly ?int $connectedBy = null,
        public readonly ?DateTimeImmutable $lastHealthAt = null,
        public readonly ?string $lastError = null,
        public readonly ?string $tokenSuffix = null,
        public readonly ?DateTimeImmutable $reconnectRequiredAt = null,
    ) {
        if (! in_array($mode, [self::MODE_MANUAL, self::MODE_CONNECTED], true)) {
            throw new InvalidArgumentException('Unknown Meta connection mode "'.$mode.'".');
        }
        if ($tokenSuffix !== null && strlen($tokenSuffix) > 4) {
            throw new InvalidArgumentException('MetaConnection keeps at most the last 4 characters of the token.');
        }
    }

    /** The v1 state: feed URL pasted by hand, nothing connected. */
    public static function manual(): self
    {
        return new self;
    }

    /**
     * A fresh connection right after the broker handoff (2b). The catalog is chosen later when Meta shared several.
     *
     * @param  list<string>  $grantedScopes
     */
    public static function connected(string $businessId, string $graphVersion, array $grantedScopes, ?int $connectedBy, ?DateTimeInterface $at = null): self
    {
        return new self(
            mode: self::MODE_CONNECTED,
            businessId: $businessId,
            graphVersion: $graphVersion,
            grantedScopes: array_values(array_map('strval', $grantedScopes)),
            connectedAt: self::immutable($at) ?? new DateTimeImmutable,
            connectedBy: $connectedBy,
        );
    }

    public function isManual(): bool
    {
        return $this->mode === self::MODE_MANUAL;
    }

    public function isConnected(): bool
    {
        return $this->mode === self::MODE_CONNECTED;
    }

    /** Connected once, but Meta revoked the token: the panel offers «Volver a conectar». */
    public function needsReconnect(): bool
    {
        return $this->isConnected() && $this->reconnectRequiredAt !== null;
    }

    /** Connected, a catalog chosen and no reconnection pending: what feed sync and items_batch need. */
    public function canSync(): bool
    {
        return $this->isConnected() && ! $this->needsReconnect() && $this->catalogId !== null;
    }

    public function withCatalog(string $catalogId, ?string $catalogName): self
    {
        return $this->copy(['catalogId' => $catalogId, 'catalogName' => $catalogName, 'productFeedId' => $catalogId === $this->catalogId ? $this->productFeedId : null]);
    }

    public function withProductFeed(?string $productFeedId): self
    {
        return $this->copy(['productFeedId' => $productFeedId]);
    }

    /** A health check: null error = Meta answered fine. */
    public function withHealth(?string $error, ?DateTimeInterface $at = null): self
    {
        return $this->copy(['lastHealthAt' => self::immutable($at) ?? new DateTimeImmutable, 'lastError' => self::trimError($error)]);
    }

    public function withReconnectRequired(string $error, ?DateTimeInterface $at = null): self
    {
        $at = self::immutable($at) ?? new DateTimeImmutable;

        return $this->copy(['reconnectRequiredAt' => $at, 'lastHealthAt' => $at, 'lastError' => self::trimError($error), 'tokenSuffix' => null]);
    }

    /** Used by MetaConnectionStore when it stores a new token. */
    public function withTokenSuffix(?string $suffix): self
    {
        return $this->copy(['tokenSuffix' => $suffix, 'reconnectRequiredAt' => $suffix === null ? $this->reconnectRequiredAt : null]);
    }

    /**
     * Plain array for storage (dates as ISO 8601). Holds no secret.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'business_id' => $this->businessId,
            'catalog_id' => $this->catalogId,
            'catalog_name' => $this->catalogName,
            'product_feed_id' => $this->productFeedId,
            'graph_version' => $this->graphVersion,
            'granted_scopes' => $this->grantedScopes,
            'connected_at' => $this->connectedAt?->format(DATE_ATOM),
            'connected_by' => $this->connectedBy,
            'last_health_at' => $this->lastHealthAt?->format(DATE_ATOM),
            'last_error' => $this->lastError,
            'token_suffix' => $this->tokenSuffix,
            'reconnect_required_at' => $this->reconnectRequiredAt?->format(DATE_ATOM),
        ];
    }

    /**
     * Rebuild from toArray(); anything unreadable falls back to manual (a corrupt row never breaks the panel).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        try {
            return new self(
                mode: (string) ($data['mode'] ?? self::MODE_MANUAL),
                businessId: self::stringOrNull($data['business_id'] ?? null),
                catalogId: self::stringOrNull($data['catalog_id'] ?? null),
                catalogName: self::stringOrNull($data['catalog_name'] ?? null),
                productFeedId: self::stringOrNull($data['product_feed_id'] ?? null),
                graphVersion: self::stringOrNull($data['graph_version'] ?? null),
                grantedScopes: is_array($data['granted_scopes'] ?? null) ? array_values(array_map('strval', $data['granted_scopes'])) : [],
                connectedAt: self::date($data['connected_at'] ?? null),
                connectedBy: is_numeric($data['connected_by'] ?? null) ? (int) $data['connected_by'] : null,
                lastHealthAt: self::date($data['last_health_at'] ?? null),
                lastError: self::stringOrNull($data['last_error'] ?? null),
                tokenSuffix: self::stringOrNull($data['token_suffix'] ?? null),
                reconnectRequiredAt: self::date($data['reconnect_required_at'] ?? null),
            );
        } catch (InvalidArgumentException) {
            return self::manual();
        }
    }

    /** @param  array<string, mixed>  $changes */
    private function copy(array $changes): self
    {
        $values = [
            'mode' => $this->mode,
            'businessId' => $this->businessId,
            'catalogId' => $this->catalogId,
            'catalogName' => $this->catalogName,
            'productFeedId' => $this->productFeedId,
            'graphVersion' => $this->graphVersion,
            'grantedScopes' => $this->grantedScopes,
            'connectedAt' => $this->connectedAt,
            'connectedBy' => $this->connectedBy,
            'lastHealthAt' => $this->lastHealthAt,
            'lastError' => $this->lastError,
            'tokenSuffix' => $this->tokenSuffix,
            'reconnectRequiredAt' => $this->reconnectRequiredAt,
        ];

        return new self(...array_replace($values, $changes));
    }

    private static function trimError(?string $error): ?string
    {
        $error = $error === null ? null : trim($error);

        return $error === null || $error === '' ? null : mb_substr($error, 0, self::ERROR_MAX);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);

        return $date === false ? null : $date;
    }

    private static function immutable(?DateTimeInterface $at): ?DateTimeImmutable
    {
        return $at === null ? null : DateTimeImmutable::createFromInterface($at);
    }
}
