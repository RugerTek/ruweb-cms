<?php

namespace App\Services\SupportAccess;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Signs a support pass exactly as the RuWeb Cloud console must (docs/operations/support-access.md#pass-format).
 * In this repository it backs only the development command `support:issue-pass` and the tests: production sites
 * never hold a signing key.
 */
class SupportPassIssuer
{
    /**
     * @param  string  $secretKey  64-byte Ed25519 secret key
     * @param  array<string, mixed>  $overrides  claims to replace or add (tests)
     */
    public static function issue(string $secretKey, string $audience, string $issuer, string $email, string $name, string $role = 'operacion', ?int $sessionTtl = null, array $overrides = []): string
    {
        if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new InvalidArgumentException('An Ed25519 secret key has 64 bytes.');
        }
        $now = Carbon::now()->getTimestamp();
        $claims = [
            'v' => SupportPassVerifier::VERSION,
            'aud' => $audience,
            'iss' => $issuer,
            'sub' => $email,
            'name' => $name,
            'role' => $role,
            'iat' => $now,
            'exp' => $now + 60,
            'jti' => Str::random(32),
        ];
        if ($sessionTtl !== null) {
            $claims['session_ttl'] = $sessionTtl;
        }
        $claims = array_merge($claims, $overrides);

        return self::sign($secretKey, $claims);
    }

    /** @param  array<string, mixed>  $claims */
    public static function sign(string $secretKey, array $claims): string
    {
        $payload = SupportAccessKeys::base64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $signature = sodium_crypto_sign_detached(SupportPassVerifier::SIGNING_CONTEXT.$payload, $secretKey);

        return $payload.'.'.SupportAccessKeys::base64UrlEncode($signature);
    }
}
