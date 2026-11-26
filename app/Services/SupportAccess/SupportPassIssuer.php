<?php

namespace App\Services\SupportAccess;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Signs a support pass exactly as the RuWeb Cloud console must (docs/operations/support-access.md#pass-format): ES256
 * over SHA-256 of SIGNING_CONTEXT + payload segment, signature as the 64-byte r||s that Azure Key Vault `sign` returns.
 * In this repository it backs only the development commands and the tests: production sites never hold a signing key.
 */
class SupportPassIssuer
{
    /** Lifetime the development issuer gives its passes (the verifier accepts up to max_pass_lifetime). */
    public const LIFETIME = 30;

    /**
     * @param  array<string, mixed>  $overrides  claims to replace or add (tests); a null value removes the claim
     */
    public static function issue(OpenSSLAsymmetricKey $privateKey, string $audience, string $issuer, string $email, string $name, string $reason, string $role = 'operacion', ?int $sessionTtl = null, ?string $ticket = null, array $overrides = []): string
    {
        $now = Carbon::now()->getTimestamp();
        $claims = [
            'v' => SupportPassVerifier::VERSION,
            'aud' => $audience,
            'iss' => $issuer,
            'sub' => $email,
            'name' => $name,
            'role' => $role,
            'reason' => $reason,
            'iat' => $now,
            'exp' => $now + self::LIFETIME,
            'jti' => Str::random(32),
        ];
        if ($ticket !== null) {
            $claims['ticket'] = $ticket;
        }
        if ($sessionTtl !== null) {
            $claims['session_ttl'] = $sessionTtl;
        }
        $claims = array_filter(array_merge($claims, $overrides), fn ($value): bool => $value !== null);

        return self::sign($privateKey, $claims);
    }

    /** @param  array<string, mixed>  $claims */
    public static function sign(OpenSSLAsymmetricKey $privateKey, array $claims): string
    {
        $payload = SupportAccessKeys::base64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if (! openssl_sign(SupportPassVerifier::SIGNING_CONTEXT.$payload, $der, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the support pass with the P-256 key.');
        }
        $raw = Es256Signature::derToRaw($der);
        if ($raw === null) {
            throw new RuntimeException('The support pass key must be an ECDSA P-256 key.');
        }

        return $payload.'.'.SupportAccessKeys::base64UrlEncode($raw);
    }

    /**
     * A new P-256 key pair (development and tests). PHP builds for Windows ship without a default openssl.cnf, so a
     * failed first attempt retries with an empty one.
     */
    public static function generateKey(): OpenSSLAsymmetricKey
    {
        $options = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
        $key = @openssl_pkey_new($options);
        if ($key === false) {
            $config = tempnam(sys_get_temp_dir(), 'ossl');
            try {
                $key = openssl_pkey_new($options + ['config' => $config]);
            } finally {
                @unlink($config);
            }
        }
        if ($key === false) {
            throw new RuntimeException('OpenSSL could not generate a P-256 key.');
        }

        return $key;
    }

    /** PKCS#8 PEM body of a private key on one line, the format RUWEB_SUPPORT_DEV_PRIVATE_KEY accepts. */
    public static function privateKeyBody(OpenSSLAsymmetricKey $key): string
    {
        $pem = '';
        if (! @openssl_pkey_export($key, $pem)) {
            $config = tempnam(sys_get_temp_dir(), 'ossl');
            try {
                openssl_pkey_export($key, $pem, null, ['config' => $config]);
            } finally {
                @unlink($config);
            }
        }

        return (string) preg_replace('/-----(BEGIN|END) PRIVATE KEY-----|\s+/', '', $pem);
    }
}
