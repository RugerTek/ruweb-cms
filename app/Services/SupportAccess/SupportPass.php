<?php

namespace App\Services\SupportAccess;

/** The verified claims of a support pass (docs/operations/support-access.md#pass-format). */
final class SupportPass
{
    public function __construct(
        public readonly string $audience,
        public readonly string $issuer,
        public readonly string $email,
        public readonly string $name,
        public readonly string $role,
        public readonly int $issuedAt,
        public readonly int $expiresAt,
        public readonly string $jti,
        public readonly int $sessionTtl,
    ) {}
}
