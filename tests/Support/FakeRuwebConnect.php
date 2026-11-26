<?php

namespace Tests\Support;

use App\Connectors\Meta\Connect\RuwebConnectHandoffReceiver;
use App\Connectors\Meta\Connect\RuwebConnectSignature;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Double of the RugerTek Connect broker (HTTP contract v2 of plan-meta-v2), installed with Http::fake on URL and set
 * as `cms.meta.connect_url`. It checks what the real broker will: register body, the registration id on verify (and
 * the challenge, through challengeCheck when given). deliver() plays Meta's callback the way the broker does it: a
 * signed POST of the token to the site's /_ruweb/connect/meta/handoff (X-Ruweb-* headers, HMAC of
 * "POST\n<path>\n<ts>\n<sha256(body)>" with SITE_SECRET), with the real values of the spike (business, catalogs,
 * scopes) and FakeMetaGraph::TOKEN. The broker stores nothing, so neither does the fake.
 */
final class FakeRuwebConnect
{
    public const URL = 'https://connect.example';

    public const REGISTRATION_ID = '01JFAKEREGISTRATION00000000';

    public const CHALLENGE_TOKEN = 'FakeChallengeToken0123456789abcdefABCDEF';

    public const SITE_ID = '01JFAKESITE0000000000000000';

    public const SITE_SECRET = 'fake-site-secret-0123456789abcdef0123456789abcdef0123';

    /** @var list<array{method: string, path: string, body: string, headers: array<string, list<string>>}> */
    private array $calls = [];

    private bool $registered = false;

    /** fn (string $challengePath, string $challengeToken): bool — the GET the broker does to the site's origin. */
    public ?Closure $challengeCheck = null;

    public ?int $now = null;

    public static function install(): self
    {
        $fake = new self;
        config(['cms.meta.connect_url' => self::URL]);
        Http::fake([self::URL.'/*' => fn (Request $request) => $fake->handle($request)]);

        return $fake;
    }

    /**
     * The JSON body the broker POSTs after a finished OAuth (contract v2).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function payload(string $nonce, array $overrides = []): array
    {
        return array_replace([
            'access_token' => FakeMetaGraph::TOKEN,
            'token_type' => 'system_user',
            'business_id' => FakeMetaGraph::BUSINESS_ID,
            'catalogs' => [
                ['id' => FakeMetaGraph::OTHER_CATALOG_ID, 'name' => 'Ruweb prueba feed', 'vertical' => 'commerce', 'product_count' => 3],
                ['id' => FakeMetaGraph::GENERIC_CATALOG_ID, 'name' => 'Ruweb spike v2 Catalog', 'vertical' => 'generic', 'product_count' => 0],
                ['id' => FakeMetaGraph::CATALOG_ID, 'name' => 'Ruweb spike v2 commerce', 'vertical' => 'commerce', 'product_count' => 0],
            ],
            'graph_version' => 'v27.0',
            'granted_scopes' => ['catalog_management', 'public_profile'],
            'nonce' => $nonce,
        ], $overrides);
    }

    /**
     * Server variables of a broker → site delivery signed like the real broker (TokenDelivery).
     *
     * @return array<string, string>
     */
    public static function signedServer(string $body, ?int $timestamp = null, string $secret = self::SITE_SECRET, string $siteId = self::SITE_ID): array
    {
        $timestamp ??= now()->getTimestamp();

        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_RUWEB_SITE' => $siteId,
            'HTTP_X_RUWEB_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_RUWEB_SIGNATURE' => RuwebConnectSignature::request($secret, 'POST', RuwebConnectHandoffReceiver::PATH, $timestamp, $body),
        ];
    }

    /**
     * A finished OAuth on the broker: the signed POST of the token to the site, as the broker does it before sending
     * the browser back. Returns the site's answer (200 accepted, 404 refused).
     *
     * @param  array<string, mixed>  $overrides  payload fields
     */
    public function deliver(TestCase $test, string $nonce, array $overrides = [], ?int $timestamp = null, string $secret = self::SITE_SECRET): TestResponse
    {
        $body = json_encode(self::payload($nonce, $overrides), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $test->call('POST', RuwebConnectHandoffReceiver::PATH, [], [], [], self::signedServer($body, $timestamp ?? $this->now, $secret), $body);
    }

    /** @return list<array{method: string, path: string, body: string, headers: array<string, list<string>>}> */
    public function calls(): array
    {
        return $this->calls;
    }

    /** Whether a start URL built by the site carries a valid signature for SITE_ID / SITE_SECRET. */
    public static function validStartUrl(string $url): bool
    {
        if (! str_starts_with($url, self::URL.'/v1/meta/start?')) {
            return false;
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        foreach (['site', 'nonce', 'ts', 'sig'] as $field) {
            if (! is_string($query[$field] ?? null) || $query[$field] === '') {
                return false;
            }
        }

        return $query['site'] === self::SITE_ID
            && hash_equals(RuwebConnectSignature::start(self::SITE_SECRET, $query['site'], $query['nonce'], (int) $query['ts']), $query['sig']);
    }

    public function handle(Request $request): mixed
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $this->calls[] = ['method' => $request->method(), 'path' => $path, 'body' => $request->body(), 'headers' => $request->headers()];

        if ($request->method() === 'GET' && $path === '/v1/health') {
            return Http::response(['ok' => true, 'version' => '2']);
        }
        if ($request->method() === 'POST' && $path === '/v1/sites/register') {
            $body = json_decode($request->body(), true);
            if (! is_array($body) || ! is_string($body['origin'] ?? null) || preg_match('#^https?://[^/]+$#', $body['origin']) !== 1 || ! is_string($body['name'] ?? null)) {
                return $this->error(422, 'invalid_request');
            }
            $this->registered = true;

            return Http::response([
                'registration_id' => self::REGISTRATION_ID,
                'challenge_token' => self::CHALLENGE_TOKEN,
                'challenge_path' => '/.well-known/ruweb-connect/'.self::CHALLENGE_TOKEN,
                'expires_in' => 600,
            ], 201);
        }
        if ($request->method() === 'POST' && $path === '/v1/sites/register/'.self::REGISTRATION_ID.'/verify') {
            if (! $this->registered) {
                return $this->error(404, 'registration_not_found');
            }
            if ($this->challengeCheck !== null && ! ($this->challengeCheck)('/.well-known/ruweb-connect/'.self::CHALLENGE_TOKEN, self::CHALLENGE_TOKEN)) {
                return $this->error(422, 'challenge_failed');
            }

            return Http::response(['site_id' => self::SITE_ID, 'site_secret' => self::SITE_SECRET]);
        }
        if (str_starts_with($path, '/v1/sites/register/')) {
            return $this->error(404, 'registration_not_found');
        }

        return $this->error(404, 'not_found');
    }

    private function error(int $status, string $code): mixed
    {
        return Http::response(['error' => $code, 'message' => 'FakeRuwebConnect: '.$code], $status);
    }
}
