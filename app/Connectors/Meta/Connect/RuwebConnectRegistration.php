<?php

namespace App\Connectors\Meta\Connect;

/** Answer of `POST /v1/sites/register`: serve challengeToken at challengePath (text/plain) for expiresIn seconds. */
final class RuwebConnectRegistration
{
    public function __construct(
        public readonly string $registrationId,
        public readonly string $challengeToken,
        public readonly string $challengePath,
        public readonly int $expiresIn,
    ) {}
}
