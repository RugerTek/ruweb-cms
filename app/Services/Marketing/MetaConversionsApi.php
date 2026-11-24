<?php

namespace App\Services\Marketing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

/**
 * Client of Meta's Conversions API with Laravel's HTTP client (no SDK): `POST {graph_url}/{version}/{pixel}/events`
 * (Meta, "Using the API", updated 2026-07-17, consulted 2026-11-24). The access token goes in the request body, never
 * in the URL, so it cannot end up in an access log; nothing here logs or throws it. The version and the timeout come
 * from `cms.meta_pixel`.
 */
final class MetaConversionsApi
{
    /** Longest error text kept from Meta's answer. */
    private const MESSAGE_MAX = 300;

    /**
     * @param  list<array<string, mixed>>  $events  server events, already built and hashed (MetaPixel::serverEvent())
     */
    public function send(string $pixelId, #[SensitiveParameter] string $token, array $events, ?string $testEventCode = null): MetaApiResult
    {
        $body = array_filter([
            'data' => $events,
            'test_event_code' => $testEventCode !== null && $testEventCode !== '' ? $testEventCode : null,
            'access_token' => $token,
        ], fn ($value): bool => $value !== null);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(max(1, (int) config('cms.meta_pixel.timeout', 10)))
                ->post($this->endpoint($pixelId), $body);
        } catch (ConnectionException) {
            return new MetaApiResult(false, 0, null, 'connection', null, retryable: true);
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];
        if ($response->successful()) {
            return new MetaApiResult(true, $response->status(), (int) ($json['events_received'] ?? 0), null, $this->trace($json));
        }

        $error = is_array($json['error'] ?? null) ? $json['error'] : [];
        $message = (string) ($error['error_user_msg'] ?? $error['message'] ?? $response->reason());
        // Meta never echoes the token, but a malformed one could come back quoted: it is removed all the same.
        $message = mb_substr(str_replace($token, '[token]', $message), 0, self::MESSAGE_MAX);

        return new MetaApiResult(false, $response->status(), null, $message, $this->trace($json), retryable: $response->serverError() || $response->status() === 429);
    }

    public function endpoint(string $pixelId): string
    {
        return rtrim((string) config('cms.meta_pixel.graph_url', 'https://graph.facebook.com'), '/')
            .'/'.config('cms.meta_pixel.graph_api_version').'/'.rawurlencode($pixelId).'/events';
    }

    /** @param  array<string, mixed>  $json */
    private function trace(array $json): ?string
    {
        $trace = $json['fbtrace_id'] ?? ($json['error']['fbtrace_id'] ?? null);

        return is_string($trace) ? $trace : null;
    }
}
