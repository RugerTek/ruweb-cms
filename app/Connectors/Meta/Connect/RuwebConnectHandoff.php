<?php

namespace App\Connectors\Meta\Connect;

use App\Connectors\Meta\Graph\MetaCatalog;
use SensitiveParameter;

/**
 * Answer of `POST /v1/meta/handoff/{id}` (single use): the system user token, the client's business portfolio and the
 * catalogs shared in Meta's dialog. Save the token at once (MetaConnectionStore::save()); never log or show it.
 */
final class RuwebConnectHandoff
{
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
