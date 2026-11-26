<?php

namespace App\Connectors\Meta\Graph;

/**
 * `GET /me?fields=id,name,client_business_id` with a system user token: clientBusinessId is the client's business
 * portfolio (the identity of the connection). systemUserId changes on every reconnection (spike §4): never store it as
 * the identity.
 */
final class MetaIdentity
{
    public function __construct(
        public readonly string $systemUserId,
        public readonly ?string $name,
        public readonly ?string $clientBusinessId,
    ) {}

    /** @param  array<string, mixed>  $json */
    public static function fromGraph(array $json): self
    {
        return new self((string) ($json['id'] ?? ''), MetaGraphValues::string($json['name'] ?? null), MetaGraphValues::string($json['client_business_id'] ?? null));
    }
}
