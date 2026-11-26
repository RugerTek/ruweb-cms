<?php

namespace App\Connectors\Meta\Connect;

use SensitiveParameter;

/**
 * Signatures of the RugerTek Connect HTTP contract v2 (plan-meta-v2), shared by the site and the broker:
 * - request (broker → site: the token delivery to /_ruweb/connect/meta/handoff, RuwebConnectHandoffReceiver; the
 *   scheme also serves site → broker calls): headers X-Ruweb-Site, X-Ruweb-Timestamp and
 *   X-Ruweb-Signature = hex(hmac_sha256(site_secret, "<METHOD>\n<path>\n<timestamp>\n<sha256 hex of the body>")),
 *   accepted within ±300 s, compared in constant time. `path` is the contract path (`/_ruweb/connect/meta/handoff`),
 *   without query string nor a base path.
 * - start link (browser): sig = hex(hmac_sha256(site_secret, "start\n<site_id>\n<nonce>\n<ts>")).
 */
final class RuwebConnectSignature
{
    public const HEADER_SITE = 'X-Ruweb-Site';

    public const HEADER_TIMESTAMP = 'X-Ruweb-Timestamp';

    public const HEADER_SIGNATURE = 'X-Ruweb-Signature';

    public const WINDOW_SECONDS = 300;

    public static function request(#[SensitiveParameter] string $secret, string $method, string $path, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', strtoupper($method)."\n".$path."\n".$timestamp."\n".hash('sha256', $body), $secret);
    }

    public static function start(#[SensitiveParameter] string $secret, string $siteId, string $nonce, int $timestamp): string
    {
        return hash_hmac('sha256', "start\n".$siteId."\n".$nonce."\n".$timestamp, $secret);
    }

    /**
     * The three signed headers of a request.
     *
     * @return array<string, string>
     */
    public static function headers(string $siteId, #[SensitiveParameter] string $secret, string $method, string $path, int $timestamp, string $body): array
    {
        return [
            self::HEADER_SITE => $siteId,
            self::HEADER_TIMESTAMP => (string) $timestamp,
            self::HEADER_SIGNATURE => self::request($secret, $method, $path, $timestamp, $body),
        ];
    }

    /** Constant-time check of a request signature and its ±300 s window. */
    public static function verifyRequest(#[SensitiveParameter] string $secret, string $method, string $path, string $timestamp, string $body, string $signature, ?int $now = null): bool
    {
        if (preg_match('/^\d{1,12}$/', $timestamp) !== 1 || abs(($now ?? time()) - (int) $timestamp) > self::WINDOW_SECONDS) {
            return false;
        }

        return hash_equals(self::request($secret, $method, $path, (int) $timestamp, $body), strtolower($signature));
    }
}
