<?php

namespace App\Connectors\Meta\Connect;

use SensitiveParameter;

/**
 * Answer of `POST /v1/sites/register/{id}/verify`: the site credentials. The broker delivers the secret ONCE; store it
 * right away with MetaConnectionStore::saveConnectSite() (encrypted). Re-registering the same origin rotates it.
 */
final class RuwebConnectSite
{
    public function __construct(
        public readonly string $siteId,
        #[SensitiveParameter] public readonly string $siteSecret,
    ) {}

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['siteId' => $this->siteId, 'siteSecret' => '[hidden]'];
    }
}
