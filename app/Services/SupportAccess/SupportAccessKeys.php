<?php

namespace App\Services\SupportAccess;

/**
 * Reads the support access configuration (config('cms.support_access')) into the exact values the verifier compares:
 * decoded Ed25519 public keys, the audience (origin of APP_URL), issuer and operator domain. Nothing here is secret
 * except the development signing key, which only the local/testing tooling may read (devSecretKey()).
 * See docs/operations/support-access.md.
 */
class SupportAccessKeys
{
    /** Public keys honoured at once: the current one and the next one during a rotation. */
    public const MAX_PUBLIC_KEYS = 2;

    /**
     * Decoded 32-byte public keys; empty when the variable is missing or holds no valid key (feature off).
     *
     * @return list<string>
     */
    public function publicKeys(): array
    {
        $raw = config('cms.support_access.public_keys');
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }
        $keys = [];
        foreach (array_slice(explode(',', $raw), 0, self::MAX_PUBLIC_KEYS) as $entry) {
            $key = self::decodeKey(trim($entry));
            if ($key !== null && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    public function enabled(): bool
    {
        return $this->publicKeys() !== [] && $this->audience() !== null;
    }

    /** `scheme://host[:port]` of APP_URL, lowercase; null when APP_URL is not an absolute http(s) URL. */
    public function audience(): ?string
    {
        return self::origin((string) config('app.url'));
    }

    public static function origin(string $url): ?string
    {
        $parts = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme.'://'.$host.($port !== null && $port !== $default ? ':'.$port : '');
    }

    public function issuer(): string
    {
        return (string) config('cms.support_access.issuer', 'ruweb-cloud');
    }

    public function operatorDomain(): string
    {
        return strtolower(trim((string) config('cms.support_access.operator_domain', 'rugertek.com')));
    }

    /**
     * 64-byte Ed25519 secret key for the development pass issuer, or null. Never available outside local/testing,
     * whatever the environment variable says.
     */
    public function devSecretKey(): ?string
    {
        if (! app()->environment(['local', 'testing'])) {
            return null;
        }
        $raw = config('cms.support_access.dev_private_key');
        $key = is_string($raw) ? self::decodeKey(trim($raw)) : null;
        if ($key === null) {
            return null;
        }
        if (strlen($key) === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            return sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($key));
        }

        return strlen($key) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES ? $key : null;
    }

    /** Standard or URL-safe base64, padding optional; null when it is not base64. */
    public static function decodeKey(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9+\/_-]+={0,2}$/D', $value) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr(rtrim($value, '='), '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]*$/D', $value) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
