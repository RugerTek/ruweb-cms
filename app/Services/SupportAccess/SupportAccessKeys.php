<?php

namespace App\Services\SupportAccess;

use OpenSSLAsymmetricKey;

/**
 * Reads the support access configuration (config('cms.support_access')) into the exact values the verifier compares:
 * the console's ES256 (ECDSA P-256) public keys, the audience (origin of APP_URL), issuer and operator domain. Nothing
 * here is secret except the development signing key, which only the local/testing tooling may read (devPrivateKey()).
 * See docs/operations/support-access.md#keys.
 */
class SupportAccessKeys
{
    /** Public keys honoured at once: the current one and the next one during a rotation. */
    public const MAX_PUBLIC_KEYS = 2;

    /** DER prefix of a P-256 SubjectPublicKeyInfo (id-ecPublicKey, prime256v1); the uncompressed point follows. */
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /**
     * The configured P-256 public keys; empty when the variable is missing or holds no valid key (feature off).
     *
     * @return list<OpenSSLAsymmetricKey>
     */
    public function publicKeys(): array
    {
        $raw = config('cms.support_access.public_keys');
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }
        $keys = [];
        foreach (array_slice(self::splitKeys($raw), 0, self::MAX_PUBLIC_KEYS) as $entry) {
            $key = self::parsePublicKey($entry);
            if ($key !== null) {
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
     * P-256 private key for the development pass issuer, or null. Never available outside local/testing, whatever the
     * environment variable says. Accepts the PKCS#8 PEM (real or `\n` line breaks) or its base64 body on one line.
     */
    public function devPrivateKey(): ?OpenSSLAsymmetricKey
    {
        if (! app()->environment(['local', 'testing'])) {
            return null;
        }
        $raw = config('cms.support_access.dev_private_key');
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        $pem = self::pem(trim($raw), 'PRIVATE KEY');
        $key = $pem === null ? false : openssl_pkey_get_private($pem);

        return $key !== false && self::isP256($key) ? $key : null;
    }

    /**
     * One entry of RUWEB_SUPPORT_PUBLIC_KEYS: a JWK object (`kty` EC, `crv` P-256, `x` and `y` base64url, as Azure Key
     * Vault exports it; other members such as `kid` or `key_ops` are ignored), or a PEM SubjectPublicKeyInfo (armored
     * with real or `\n` line breaks, or just its base64 body on one line). Anything else, including another curve, an
     * RSA key or a JWK carrying a private part, is null.
     */
    public static function parsePublicKey(string $entry): ?OpenSSLAsymmetricKey
    {
        $entry = trim($entry);
        if ($entry === '') {
            return null;
        }
        $pem = str_starts_with($entry, '{') ? self::jwkToPem($entry) : self::pem($entry, 'PUBLIC KEY');
        $key = $pem === null ? false : openssl_pkey_get_public($pem);

        return $key !== false && self::isP256($key) ? $key : null;
    }

    /**
     * Splits the variable on commas outside JSON objects, so a JWK (which has commas of its own) stays one entry.
     *
     * @return list<string>
     */
    public static function splitKeys(string $raw): array
    {
        $entries = [];
        $depth = 0;
        $current = '';
        foreach (str_split($raw) as $char) {
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth = max(0, $depth - 1);
            }
            if ($char === ',' && $depth === 0) {
                $entries[] = trim($current);
                $current = '';

                continue;
            }
            $current .= $char;
        }
        $entries[] = trim($current);

        return array_values(array_filter($entries, fn (string $entry): bool => $entry !== ''));
    }

    /** The JWK of a P-256 public key, as Key Vault exports it (`kty`, `crv`, `x`, `y`). */
    public static function publicJwk(OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);

        return json_encode([
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => self::base64UrlEncode(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)),
            'y' => self::base64UrlEncode(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT)),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** The base64 body of the PEM SubjectPublicKeyInfo of a key, on one line. */
    public static function publicPemBody(OpenSSLAsymmetricKey $key): string
    {
        return (string) preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', openssl_pkey_get_details($key)['key']);
    }

    private static function jwkToPem(string $json): ?string
    {
        $jwk = json_decode($json, true, 4);
        if (! is_array($jwk) || ! in_array($jwk['kty'] ?? null, ['EC', 'EC-HSM'], true) || ($jwk['crv'] ?? null) !== 'P-256'
            || ! is_string($jwk['x'] ?? null) || ! is_string($jwk['y'] ?? null) || array_key_exists('d', $jwk)) {
            return null;
        }
        $x = self::base64UrlDecode($jwk['x']);
        $y = self::base64UrlDecode($jwk['y']);
        if ($x === null || $y === null || strlen($x) !== 32 || strlen($y) !== 32) {
            return null;
        }
        $der = hex2bin(self::P256_SPKI_PREFIX)."\x04".$x.$y;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    /** An armored PEM (real or `\n` breaks) or a bare base64 body, as a PEM with the given label; null otherwise. */
    private static function pem(string $value, string $label): ?string
    {
        $value = str_replace(['\\r\\n', '\\n'], "\n", $value);
        if (preg_match('/-----BEGIN ([A-Z ]+)-----/', $value, $match) === 1 && $match[1] !== $label) {
            return null;
        }
        $body = preg_replace('/-----(BEGIN|END) [A-Z ]+-----|\s+/', '', $value);
        if (! is_string($body) || $body === '' || preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', $body) !== 1) {
            return null;
        }

        return "-----BEGIN {$label}-----\n".chunk_split($body, 64, "\n")."-----END {$label}-----\n";
    }

    private static function isP256(OpenSSLAsymmetricKey $key): bool
    {
        $details = openssl_pkey_get_details($key);

        return is_array($details) && ($details['type'] ?? null) === OPENSSL_KEYTYPE_EC
            && ($details['ec']['curve_name'] ?? null) === 'prime256v1';
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
