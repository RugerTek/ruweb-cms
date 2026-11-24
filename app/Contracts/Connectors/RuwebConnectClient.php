<?php

namespace App\Contracts\Connectors;

use App\Connectors\Meta\Connect\RuwebConnectException;
use App\Connectors\Meta\Connect\RuwebConnectHandoff;
use App\Connectors\Meta\Connect\RuwebConnectRegistration;
use App\Connectors\Meta\Connect\RuwebConnectSite;
use SensitiveParameter;

/**
 * Site side of the RugerTek Connect HTTP contract v1 (plan-meta-v2; base URL `cms.meta.connect_url`, env
 * RUWEB_CONNECT_URL). Stateless: the caller (2b) reads and stores the site credentials and the registration through
 * App\Connectors\Meta\Connect\MetaConnectionStore. Implemented by App\Connectors\Meta\Connect\HttpRuwebConnectClient
 * (bound in the container); tests use Tests\Support\FakeRuwebConnect. Signatures: RuwebConnectSignature.
 *
 * @throws RuwebConnectException from every network call
 */
interface RuwebConnectClient
{
    /** `POST /v1/sites/register` {origin, name} → 201 with the challenge to serve at /.well-known/ruweb-connect/<token>. */
    public function register(string $origin, string $name): RuwebConnectRegistration;

    /** `POST /v1/sites/register/{registration_id}/verify` → 200 {site_id, site_secret} (the secret comes once). */
    public function verify(string $registrationId): RuwebConnectSite;

    /**
     * The browser URL `GET /v1/meta/start?site&nonce&ts&sig` that sends the administrator to Meta's dialog. The nonce
     * comes back on /admin/connectors/meta/connected: keep it in the session and compare it there.
     */
    public function startUrl(string $siteId, #[SensitiveParameter] string $siteSecret, string $nonce, ?int $timestamp = null): string;

    /** Signed `POST /v1/meta/handoff/{handoff_id}` → the token, once (a second call → 404 handoff_not_found). */
    public function handoff(string $siteId, #[SensitiveParameter] string $siteSecret, string $handoffId): RuwebConnectHandoff;
}
