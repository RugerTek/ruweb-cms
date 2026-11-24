<?php

namespace App\Connectors\Meta\Connect;

use App\Connectors\Meta\MetaCatalogConnector;
use App\Events\Connectors\MetaConnectionLost;
use App\Models\Connector;
use App\Support\SiteUrl;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Persistence of the Meta v2 connection in the `connectors` row of the `meta` connector (plan-meta-v2). Everything
 * lives in Connector::$secrets — encrypted with APP_KEY and bound to SiteUrl::origin(), like the Meta Pixel token and
 * the Bancard keys — and never in `settings_json`: ConnectorOperations::updateSettings() keeps only keys with a rule
 * (it would drop the connection on the next save), revision() hashes settings (every health check would turn the
 * panel's revision stale) and MCP returns settings. A copy restored on another domain or under another APP_KEY reads
 * as manual (v1) and asks to connect again.
 *
 * Entries: the MetaConnection (no secret), the Graph access token, the RugerTek Connect site credentials (site_id +
 * site_secret, delivered once by the broker) and the registration in progress (challenge served at
 * /.well-known/ruweb-connect/{token}). The token and the site secret only leave through accessToken() /
 * connectSiteSecret(), for MetaGraphClient and RuwebConnectClient; they are never shown (only tokenSuffix), returned
 * by MCP or logged. A repository: callers (2b/2c/3c operations) authorize the actor before writing.
 */
final class MetaConnectionStore
{
    public const CONNECTION = 'meta_connection';

    public const ACCESS_TOKEN = 'graph_access_token';

    public const SITE_ID = 'connect_site_id';

    public const SITE_SECRET = 'connect_site_secret';

    public const REGISTRATION = 'connect_registration';

    /** The connection, or MetaConnection::manual() when nothing usable is stored (v1 sites). */
    public function get(): MetaConnection
    {
        $data = $this->row()?->secretValue(self::CONNECTION);

        return is_array($data) ? MetaConnection::fromArray($data) : MetaConnection::manual();
    }

    /**
     * Store the connection; with $accessToken, replace the token too (Meta may hand a new one on every reconnection
     * while the old one stays valid: always keep the last, spike §2) and clear a pending reconnection. Saving a
     * manual connection is forget().
     */
    public function save(MetaConnection $connection, #[SensitiveParameter] ?string $accessToken = null): MetaConnection
    {
        if ($connection->isManual()) {
            $this->forget();

            return MetaConnection::manual();
        }
        $token = $accessToken === null ? null : trim($accessToken);
        if ($token !== null && $token !== '') {
            $connection = $connection->withTokenSuffix(substr($token, -4));
        }

        $this->write(function (array $secrets) use ($connection, $token): array {
            $secrets[self::CONNECTION] = $connection->toArray();
            if ($token !== null && $token !== '') {
                $secrets[self::ACCESS_TOKEN] = $token;
            }

            return $secrets;
        });

        return $connection;
    }

    /** Back to manual (v1): drops the connection and its token, keeps the broker site credentials. */
    public function forget(): void
    {
        $this->write(function (array $secrets): array {
            unset($secrets[self::CONNECTION], $secrets[self::ACCESS_TOKEN]);

            return $secrets;
        });
    }

    /**
     * Meta revoked the token (190 any subcode, or 102): drop it, keep the business and catalog so the panel can say
     * what was connected, and flag the connector as needs_reconnect. No-op for a manual connection. The first time
     * (not while a reconnection is already pending) it dispatches MetaConnectionLost: the administrators get one email.
     */
    public function markNeedsReconnect(string $error): MetaConnection
    {
        $connection = $this->get();
        if (! $connection->isConnected()) {
            return $connection;
        }
        $lost = ! $connection->needsReconnect();
        $connection = $connection->withReconnectRequired($error);
        $this->write(function (array $secrets) use ($connection): array {
            $secrets[self::CONNECTION] = $connection->toArray();
            unset($secrets[self::ACCESS_TOKEN]);

            return $secrets;
        });
        if ($lost) {
            MetaConnectionLost::dispatch($connection, (string) $connection->lastError);
        }

        return $connection;
    }

    /** Result of a health check (3c): null = Meta answered fine. No-op for a manual connection. */
    public function recordHealth(?string $error): MetaConnection
    {
        $connection = $this->get();

        return $connection->isConnected() ? $this->save($connection->withHealth($error)) : $connection;
    }

    /**
     * @internal For MetaGraphClient only: the token of a connection that can call Meta, else null.
     */
    public function accessToken(): ?string
    {
        $connection = $this->get();
        if (! $connection->isConnected() || $connection->needsReconnect()) {
            return null;
        }
        $token = $this->row()?->secret(self::ACCESS_TOKEN);

        return $token;
    }

    public function connectSiteId(): ?string
    {
        $value = $this->row()?->secretValue(self::SITE_ID);

        return is_string($value) && $value !== '' && $this->connectSiteSecret() !== null ? $value : null;
    }

    /**
     * @internal For RuwebConnectClient calls only (signing); never shown, returned by MCP or logged.
     */
    public function connectSiteSecret(): ?string
    {
        return $this->row()?->secret(self::SITE_SECRET);
    }

    /** The broker verified the site (`POST /v1/sites/register/{id}/verify`): keep its credentials, end the registration. */
    public function saveConnectSite(string $siteId, #[SensitiveParameter] string $siteSecret): void
    {
        $this->write(function (array $secrets) use ($siteId, $siteSecret): array {
            $secrets[self::SITE_ID] = $siteId;
            $secrets[self::SITE_SECRET] = $siteSecret;
            unset($secrets[self::REGISTRATION]);

            return $secrets;
        });
    }

    public function forgetConnectSite(): void
    {
        $this->write(function (array $secrets): array {
            unset($secrets[self::SITE_ID], $secrets[self::SITE_SECRET], $secrets[self::REGISTRATION]);

            return $secrets;
        });
    }

    /** `POST /v1/sites/register` answered: serve the challenge until it expires. */
    public function startRegistration(string $registrationId, string $challengeToken, int $expiresIn): void
    {
        $this->write(function (array $secrets) use ($registrationId, $challengeToken, $expiresIn): array {
            $secrets[self::REGISTRATION] = [
                'id' => $registrationId,
                'challenge_token' => $challengeToken,
                'expires_at' => now()->getTimestamp() + max(1, $expiresIn),
            ];

            return $secrets;
        });
    }

    /** Registration id in progress (not expired), for the verify call. */
    public function registrationId(): ?string
    {
        $registration = $this->registration();

        return $registration === null ? null : $registration['id'];
    }

    /**
     * The body /.well-known/ruweb-connect/{token} answers: the challenge token when $token is the one of a
     * registration in progress (constant-time comparison), else null (404).
     */
    public function challengeFor(string $token): ?string
    {
        $registration = $this->registration();
        if ($registration === null || $token === '' || ! hash_equals($registration['challenge_token'], $token)) {
            return null;
        }

        return $registration['challenge_token'];
    }

    public function clearRegistration(): void
    {
        $this->write(function (array $secrets): array {
            unset($secrets[self::REGISTRATION]);

            return $secrets;
        });
    }

    /** @return array{id: string, challenge_token: string, expires_at: int}|null */
    private function registration(): ?array
    {
        $value = $this->row()?->secretValue(self::REGISTRATION);
        if (! is_array($value) || ! is_string($value['id'] ?? null) || ! is_string($value['challenge_token'] ?? null) || $value['challenge_token'] === '' || ! is_int($value['expires_at'] ?? null)) {
            return null;
        }

        return $value['expires_at'] > now()->getTimestamp() ? $value : null;
    }

    private function row(): ?Connector
    {
        return Connector::query()->where('key', MetaCatalogConnector::KEY)->first();
    }

    /**
     * Read-modify-write of the secrets under a row lock. Unusable secrets (another origin or APP_KEY) start from
     * empty: they are replaced, never decrypted. Creates the row (disabled) when the connector was never activated.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    private function write(callable $change): void
    {
        DB::transaction(function () use ($change): void {
            $connector = Connector::query()->where('key', MetaCatalogConnector::KEY)->lockForUpdate()->first()
                ?? new Connector(['key' => MetaCatalogConnector::KEY, 'enabled' => false]);
            $current = [];
            foreach ([self::CONNECTION, self::ACCESS_TOKEN, self::SITE_ID, self::SITE_SECRET, self::REGISTRATION] as $name) {
                $value = $connector->exists ? $connector->secretValue($name) : null;
                if ($value !== null) {
                    $current[$name] = $value;
                }
            }
            $secrets = $change($current);
            if ($secrets === [] && ! $connector->exists) {
                return;
            }
            $connector->secrets = $secrets === [] ? null : $secrets;
            $connector->bound_origin = $secrets === [] ? null : SiteUrl::origin();
            $connector->save();
        });
    }
}
