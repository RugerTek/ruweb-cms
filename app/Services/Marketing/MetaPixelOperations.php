<?php

namespace App\Services\Marketing;

use App\Connectors\ConnectorRegistry;
use App\Connectors\MetaPixel\MetaPixelConnector;
use App\Models\Connector;
use App\Models\User;
use App\Services\Connectors\ConnectorOperations;
use App\Support\SiteUrl;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Panel operations of the Meta Pixel connector that the generic ConnectorOperations does not cover: the Conversions
 * API access token (a secret: encrypted in Connector::$secrets and bound to SiteUrl::origin(), like the Bancard keys;
 * never returned, shown, sent to MCP or logged) and the "Send test event" button. Pixel ID and test events code are
 * ordinary settings (ConnectorOperations::updateSettings()). Administrators only (`manage-connectors`).
 */
final class MetaPixelOperations
{
    /** Meta access tokens are long opaque strings; this only rejects what clearly is not one (a URL, a script). */
    public const TOKEN_PATTERN = '/^[A-Za-z0-9_\-|.]{20,512}$/';

    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly MetaPixel $pixel,
        private readonly MetaConversionsApi $api,
    ) {}

    /**
     * Save a new access token, replacing the previous one (also one unreadable here). Creates the row (disabled) if
     * the connector was never activated.
     *
     * @throws AuthorizationException when the actor is not an administrator
     * @throws ValidationException (`access_token`) when it does not look like a token
     */
    public function saveAccessToken(User $actor, #[SensitiveParameter] string $token): Connector
    {
        $this->authorize($actor);
        $token = trim($token);
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw ValidationException::withMessages(['access_token' => __('meta_pixel.error_token_format')]);
        }

        return DB::transaction(function () use ($token) {
            $connector = $this->row();
            $connector->secrets = [MetaPixel::ACCESS_TOKEN => $token];
            $connector->bound_origin = SiteUrl::origin();
            $connector->save();

            return $connector;
        });
    }

    /**
     * Remove the access token: only the browser pixel keeps measuring.
     *
     * @throws AuthorizationException when the actor is not an administrator
     */
    public function forgetAccessToken(User $actor): void
    {
        $this->authorize($actor);
        DB::transaction(function () {
            $connector = $this->row();
            if (! $connector->exists) {
                return;
            }
            $connector->secrets = null;
            $connector->bound_origin = null;
            $connector->save();
        });
    }

    /**
     * Last 4 characters of the saved token ("termina en …"), the most the panel shows; null without a usable one.
     */
    public function tokenSuffix(): ?string
    {
        $token = $this->pixel->accessToken();

        return $token === null ? null : substr($token, -4);
    }

    /**
     * Send a PageView with the test events code to the Conversions API right now and return Meta's answer. Needs the
     * pixel ID, a usable token and a test events code (so the test never counts as a real visit).
     *
     * @throws AuthorizationException when the actor is not an administrator
     * @throws ValidationException (`test_event`) when something is missing
     */
    public function sendTestEvent(User $actor, ?string $userAgent = null, ?string $ip = null): MetaApiResult
    {
        $this->authorize($actor);
        $settings = $this->registry->settings(MetaPixelConnector::KEY);
        $pixelId = $settings['pixel_id'] ?? null;
        $code = $settings['test_event_code'] ?? null;
        $token = $this->pixel->accessToken();
        Validator::make(
            ['pixel_id' => $pixelId, 'access_token' => $token, 'test_event_code' => $code],
            ['pixel_id' => ['required', 'regex:'.MetaPixelConnector::PIXEL_ID_PATTERN], 'access_token' => ['required'], 'test_event_code' => ['required']],
            [
                'pixel_id.required' => __('meta_pixel.test_needs_pixel_id'),
                'pixel_id.regex' => __('meta_pixel.test_needs_pixel_id'),
                'access_token.required' => __('meta_pixel.test_needs_token'),
                'test_event_code.required' => __('meta_pixel.test_needs_code'),
            ],
        )->validate();

        $event = $this->pixel->serverEvent('PageView', 'test-'.Str::uuid(), [], array_filter([
            'client_ip_address' => (string) $ip,
            'client_user_agent' => (string) ($userAgent ?: 'Ruweb panel test event'),
        ], fn (string $value): bool => $value !== ''), SiteUrl::origin().'/');

        return $this->api->send((string) $pixelId, (string) $token, [$event], (string) $code);
    }

    private function authorize(User $actor): void
    {
        Gate::forUser($actor)->authorize(ConnectorOperations::ABILITY);
    }

    private function row(): Connector
    {
        return Connector::query()->where('key', MetaPixelConnector::KEY)->lockForUpdate()->first()
            ?? new Connector(['key' => MetaPixelConnector::KEY, 'enabled' => false]);
    }
}
