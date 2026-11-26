<?php

namespace App\Connectors\Meta\Connect;

use App\Connectors\Meta\Graph\MetaCatalog;
use SensitiveParameter;

/**
 * What RugerTek Connect delivers in `POST /_ruweb/connect/meta/handoff` (contract v2, RuwebConnectHandoffReceiver):
 * the system user token, the client's business portfolio and the catalogs shared in Meta's dialog. The site keeps it
 * (MetaConnectionStore::acceptDelivery(), encrypted) until the administrator's browser comes back, then saves the
 * connection. Never log or show the token.
 */
final class RuwebConnectHandoff
{
    /** Longest token accepted (Meta system user tokens are ~200 characters). */
    public const MAX_TOKEN_LENGTH = 4096;

    /**
     * @param  list<MetaCatalog>  $catalogs
     * @param  list<string>  $grantedScopes
     */
    public function __construct(
        #[SensitiveParameter] public readonly string $accessToken,
        public readonly string $tokenType,
        public readonly string $businessId,
        public readonly array $catalogs,
        public readonly string $graphVersion,
        public readonly array $grantedScopes,
    ) {}

    /**
     * The delivery body (or what MetaConnectionStore kept of it); null when a required field is missing or malformed.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromPayload(#[SensitiveParameter] array $data): ?self
    {
        $token = $data['access_token'] ?? null;
        $business = $data['business_id'] ?? null;
        $version = $data['graph_version'] ?? null;
        if (! is_string($token) || $token === '' || strlen($token) > self::MAX_TOKEN_LENGTH || preg_match('/^[\x21-\x7E]+$/', $token) !== 1
            || ! (is_string($business) || is_int($business)) || preg_match('/^\d{1,32}$/', (string) $business) !== 1
            || ! is_string($version) || preg_match('/^v\d{1,3}\.\d{1,3}$/', $version) !== 1
            || ! is_array($data['catalogs'] ?? []) || ! is_array($data['granted_scopes'] ?? [])) {
            return null;
        }
        $catalogs = [];
        foreach ($data['catalogs'] ?? [] as $catalog) {
            if (is_array($catalog) && (is_string($catalog['id'] ?? null) || is_int($catalog['id'] ?? null)) && (string) $catalog['id'] !== '') {
                $catalogs[] = MetaCatalog::fromGraph($catalog);
            }
        }
        $scopes = array_values(array_filter($data['granted_scopes'] ?? [], fn (mixed $scope): bool => is_string($scope) && preg_match('/^[a-z_]{1,64}$/', $scope) === 1));

        return new self($token, is_string($data['token_type'] ?? null) ? mb_substr($data['token_type'], 0, 40) : '', (string) $business, $catalogs, $version, $scopes);
    }

    /**
     * What MetaConnectionStore keeps (encrypted with the connector secrets) until the browser comes back.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'access_token' => $this->accessToken,
            'token_type' => $this->tokenType,
            'business_id' => $this->businessId,
            'catalogs' => array_map(fn (MetaCatalog $catalog): array => $catalog->toArray(), $this->catalogs),
            'graph_version' => $this->graphVersion,
            'granted_scopes' => $this->grantedScopes,
        ];
    }

    /**
     * Catalogs Ruweb can use (vertical `commerce`).
     *
     * @return list<MetaCatalog>
     */
    public function commerceCatalogs(): array
    {
        return array_values(array_filter($this->catalogs, fn (MetaCatalog $catalog): bool => $catalog->isCommerce()));
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['accessToken' => '[hidden]', 'tokenType' => $this->tokenType, 'businessId' => $this->businessId, 'catalogs' => $this->catalogs, 'graphVersion' => $this->graphVersion, 'grantedScopes' => $this->grantedScopes];
    }
}
