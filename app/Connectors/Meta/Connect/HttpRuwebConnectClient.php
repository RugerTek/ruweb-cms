<?php

namespace App\Connectors\Meta\Connect;

use App\Connectors\Meta\Graph\MetaCatalog;
use App\Contracts\Connectors\RuwebConnectClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use stdClass;

/**
 * RuwebConnectClient over Laravel's HTTP client. Base URL `cms.meta.connect_url` (RUWEB_CONNECT_URL), HTTPS only:
 * `cms.meta.connect_allow_http` (RUWEB_CONNECT_ALLOW_HTTP) allows http:// only in the local and testing environments,
 * so a production site can never send its secret in clear. Timeout `cms.meta.connect_timeout`, no redirects followed,
 * no retries (a handoff is single use). Nothing here logs; secrets are #[SensitiveParameter].
 */
final class HttpRuwebConnectClient implements RuwebConnectClient
{
    public const PATH_REGISTER = '/v1/sites/register';

    public const PATH_START = '/v1/meta/start';

    public function register(string $origin, string $name): RuwebConnectRegistration
    {
        $json = $this->call('POST', self::PATH_REGISTER, ['origin' => $origin, 'name' => $name]);
        foreach (['registration_id', 'challenge_token', 'challenge_path'] as $field) {
            if (! is_string($json[$field] ?? null) || $json[$field] === '') {
                throw new RuwebConnectException(RuwebConnectException::UNEXPECTED, 'RugerTek Connect register answered without '.$field.'.', 201);
            }
        }
        if (preg_match('/^[A-Za-z0-9_-]{16,128}$/', $json['challenge_token']) !== 1 || $json['challenge_path'] !== '/.well-known/ruweb-connect/'.$json['challenge_token']) {
            throw new RuwebConnectException(RuwebConnectException::UNEXPECTED, 'RugerTek Connect answered a challenge this site cannot serve.', 201);
        }

        return new RuwebConnectRegistration($json['registration_id'], $json['challenge_token'], $json['challenge_path'], max(1, (int) ($json['expires_in'] ?? 600)));
    }

    public function verify(string $registrationId): RuwebConnectSite
    {
        $json = $this->call('POST', self::PATH_REGISTER.'/'.rawurlencode($registrationId).'/verify', []);
        if (! is_string($json['site_id'] ?? null) || $json['site_id'] === '' || ! is_string($json['site_secret'] ?? null) || $json['site_secret'] === '') {
            throw new RuwebConnectException(RuwebConnectException::UNEXPECTED, 'RugerTek Connect verify answered without site credentials.', 200);
        }

        return new RuwebConnectSite($json['site_id'], $json['site_secret']);
    }

    public function startUrl(string $siteId, #[SensitiveParameter] string $siteSecret, string $nonce, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return $this->baseUrl().self::PATH_START.'?'.http_build_query([
            'site' => $siteId,
            'nonce' => $nonce,
            'ts' => $timestamp,
            'sig' => RuwebConnectSignature::start($siteSecret, $siteId, $nonce, $timestamp),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function handoff(string $siteId, #[SensitiveParameter] string $siteSecret, string $handoffId): RuwebConnectHandoff
    {
        $path = '/v1/meta/handoff/'.rawurlencode($handoffId);
        $json = $this->call('POST', $path, null, RuwebConnectSignature::headers($siteId, $siteSecret, 'POST', $path, time(), ''));
        $token = $json['access_token'] ?? null;
        $business = $json['business_id'] ?? null;
        if (! is_string($token) || $token === '' || ! is_scalar($business) || (string) $business === '') {
            throw new RuwebConnectException(RuwebConnectException::UNEXPECTED, 'RugerTek Connect handoff answered without a token or business.', 200);
        }
        $catalogs = is_array($json['catalogs'] ?? null) ? array_values(array_map(fn (array $catalog) => MetaCatalog::fromGraph($catalog), array_filter($json['catalogs'], 'is_array'))) : [];

        return new RuwebConnectHandoff(
            $token,
            (string) ($json['token_type'] ?? ''),
            (string) $business,
            $catalogs,
            (string) ($json['graph_version'] ?? config('cms.meta.graph_version')),
            is_array($json['granted_scopes'] ?? null) ? array_values(array_map('strval', $json['granted_scopes'])) : [],
        );
    }

    /**
     * Configured base URL without trailing slash.
     *
     * @throws RuwebConnectException (not_configured, insecure_url)
     */
    public function baseUrl(): string
    {
        $url = rtrim(trim((string) config('cms.meta.connect_url')), '/');
        if ($url === '') {
            throw new RuwebConnectException(RuwebConnectException::NOT_CONFIGURED, 'RUWEB_CONNECT_URL is not set.');
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $httpAllowed = (bool) config('cms.meta.connect_allow_http') && app()->environment('local', 'testing');
        if (! ($scheme === 'https' || ($scheme === 'http' && $httpAllowed)) || parse_url($url, PHP_URL_HOST) === null) {
            throw new RuwebConnectException(RuwebConnectException::INSECURE_URL, 'RUWEB_CONNECT_URL must be an https:// URL.');
        }

        return $url;
    }

    /**
     * @param  array<string, mixed>|null  $body  JSON body; null sends an empty body (what the handoff signs)
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, ?array $body, array $headers = []): array
    {
        $request = $this->request()->withHeaders($headers);
        try {
            /** @var Response $response */
            $response = $request->withBody(self::encode($body), 'application/json')->send($method, $this->baseUrl().$path);
        } catch (ConnectionException) {
            throw new RuwebConnectException(RuwebConnectException::CONNECTION, 'Could not reach RugerTek Connect.');
        }
        $json = $response->json();
        if (! $response->successful()) {
            $code = is_array($json) && is_string($json['error'] ?? null) ? $json['error'] : 'http_'.$response->status();
            $message = is_array($json) && is_string($json['message'] ?? null) ? mb_substr($json['message'], 0, 300) : 'RugerTek Connect answered HTTP '.$response->status().'.';

            throw new RuwebConnectException($code, $message, $response->status());
        }
        if (! is_array($json)) {
            throw new RuwebConnectException(RuwebConnectException::UNEXPECTED, 'RugerTek Connect answered something that is not JSON.', $response->status());
        }

        return $json;
    }

    /**
     * Exact bytes sent (and signed): '' for null, `{}` for an empty object.
     *
     * @param  array<string, mixed>|null  $body
     */
    public static function encode(?array $body): string
    {
        return $body === null ? '' : json_encode($body === [] ? new stdClass : $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->withoutRedirecting()
            ->timeout(max(1, (int) config('cms.meta.connect_timeout', 10)))
            ->connectTimeout(max(1, (int) config('cms.meta.connect_timeout', 10)));
    }
}
