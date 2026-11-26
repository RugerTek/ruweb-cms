<?php

namespace App\Connectors\Meta\Connect;

use App\Models\User;
use App\Services\Connectors\ConnectorOperations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * Site end of contract v2 (plan-meta-v2): RugerTek Connect POSTs the Meta token to `/_ruweb/connect/meta/handoff`
 * (RuwebConnectHandoffController) right after Meta's callback, signed with this site's secret like the site signs its
 * own calls: X-Ruweb-Site, X-Ruweb-Timestamp and X-Ruweb-Signature =
 * hex(hmac_sha256(site_secret, "POST\n/_ruweb/connect/meta/handoff\n<ts>\n<sha256_hex(body)>")), ±300 s.
 *
 * Accepted only when the site id is ours, the signature and window hold, the same signed request was not seen before,
 * the body is a valid delivery and its `nonce` is the pending one of an administrator who pressed «Conectar con
 * Facebook» (MetaConnectionStore::startFlow()) and still may manage connectors. Then the delivery is kept for that
 * administrator (MetaConnectionStore::acceptDelivery()) and the broker gets its 200; the browser follows to
 * /admin/connectors/meta/connected, where MetaConnectController saves the connection. Every refusal is the same
 * answer for the caller (a 404) and a log line with the reason only: never the token, the body or the signature.
 */
final class RuwebConnectHandoffReceiver
{
    public const PATH = '/_ruweb/connect/meta/handoff';

    /** Largest body read: a token and a few hundred catalogs. */
    public const MAX_BODY_BYTES = 262144;

    public function __construct(private readonly MetaConnectionStore $store) {}

    /** Whether the delivery was accepted (200) or refused (404). */
    public function receive(?string $siteId, ?string $timestamp, ?string $signature, #[SensitiveParameter] string $body): bool
    {
        $reason = $this->refusal($siteId, $timestamp, $signature, $body);
        if ($reason !== null) {
            Log::notice('Meta connect: a token delivery was refused.', ['reason' => $reason]);

            return false;
        }

        return true;
    }

    private function refusal(?string $siteId, ?string $timestamp, ?string $signature, #[SensitiveParameter] string $body): ?string
    {
        $ourId = $this->store->connectSiteId();
        $secret = $this->store->connectSiteSecret();
        if ($ourId === null || $secret === null) {
            return 'not_registered';
        }
        if (! is_string($siteId) || ! is_string($timestamp) || ! is_string($signature) || strlen($body) > self::MAX_BODY_BYTES) {
            return 'malformed';
        }
        if (! hash_equals($ourId, $siteId) || ! RuwebConnectSignature::verifyRequest($secret, 'POST', self::PATH, $timestamp, $body, $signature, now()->getTimestamp())) {
            return 'invalid_signature';
        }
        if (! Cache::add('meta_connect:handoff:'.hash('sha256', strtolower($signature)), true, RuwebConnectSignature::WINDOW_SECONDS * 2 + 60)) {
            return 'replayed';
        }
        $data = json_decode($body, true);
        $nonce = is_array($data) ? ($data['nonce'] ?? null) : null;
        if (! is_string($nonce) || preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce) !== 1) {
            return 'invalid_body';
        }
        $handoff = RuwebConnectHandoff::fromPayload($data);
        if ($handoff === null) {
            return 'invalid_body';
        }
        $userId = $this->store->acceptDelivery($nonce, $handoff);
        if ($userId === null) {
            return 'unknown_nonce';
        }
        $user = User::query()->find($userId);
        if ($user === null || ! Gate::forUser($user)->allows(ConnectorOperations::ABILITY)) {
            $this->store->endFlow($nonce);

            return 'not_allowed';
        }
        Log::info('Meta connect: token received from RugerTek Connect.', ['actor' => $userId, 'business_id' => $handoff->businessId, 'catalogs' => count($handoff->catalogs)]);

        return null;
    }
}
