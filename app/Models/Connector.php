<?php

namespace App\Models;

use App\Support\SiteUrl;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Stored state of one connector (`cms.connectors` key), created the first time it is activated. The feed token is
 * validated by its sha256 (`token_hash`); the encrypted copy only lets the panel show the URL again, so a snapshot
 * restored under another APP_KEY keeps serving and the panel asks to regenerate. Both stay out of toArray()/JSON.
 * `secrets` holds credentials for an external API (the Meta Pixel's Conversions API token), encrypted with APP_KEY;
 * `bound_origin` is the site origin they were saved under, so a copy restored on another domain or under another
 * APP_KEY asks for them again (like payment_methods). Secrets never leave through toArray()/JSON, MCP, logs or views.
 * Written only through App\Services\Connectors\ConnectorOperations (secrets: App\Services\Marketing\MetaPixelOperations).
 *
 * @property string $key
 * @property bool $enabled
 * @property array<string, mixed>|null $settings_json
 * @property string|null $bound_origin
 */
class Connector extends Model
{
    protected $fillable = [
        'key',
        'enabled',
        'settings_json',
        'token_hash',
        'token',
        'token_rotated_at',
        'last_fetched_at',
        'last_fetch_agent',
        'last_meta_fetched_at',
        'last_item_count',
        'last_skipped_count',
        'secrets',
        'bound_origin',
    ];

    protected $hidden = ['token', 'token_hash', 'secrets'];

    protected $attributes = ['enabled' => false];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'settings_json' => 'array',
            'token' => 'encrypted',
            'token_rotated_at' => 'datetime',
            'last_fetched_at' => 'datetime',
            'last_meta_fetched_at' => 'datetime',
            'last_item_count' => 'integer',
            'last_skipped_count' => 'integer',
            'secrets' => 'encrypted:array',
        ];
    }

    /**
     * Secrets are compared as stored ciphertext, never decrypted: saving new ones over secrets encrypted under another
     * APP_KEY must not fail while Eloquent checks what changed.
     */
    public function originalIsEquivalent($key)
    {
        if ($key === 'secrets') {
            return ($this->attributes['secrets'] ?? null) === ($this->original['secrets'] ?? null);
        }

        return parent::originalIsEquivalent($key);
    }

    /**
     * One stored secret, or null when it is missing, cannot be decrypted here (another APP_KEY) or was saved under
     * another site origin (SiteUrl::origin()). For the code that calls the external API only.
     */
    public function secret(string $name): ?string
    {
        $value = $this->usableSecrets()[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Whether secrets are stored but cannot be used on this site (unreadable or saved under another origin). */
    public function secretsUnusable(): bool
    {
        return ($this->attributes['secrets'] ?? null) !== null && $this->usableSecrets() === [];
    }

    /** @return array<string, mixed> */
    private function usableSecrets(): array
    {
        if (($this->attributes['secrets'] ?? null) === null || $this->bound_origin === null || $this->bound_origin !== SiteUrl::origin()) {
            return [];
        }
        try {
            $secrets = $this->secrets;
        } catch (DecryptException) {
            return [];
        }

        return is_array($secrets) ? $secrets : [];
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Constant-time comparison against the stored hash. */
    public function matchesToken(string $token): bool
    {
        return $this->token_hash !== null && hash_equals($this->token_hash, self::hashToken($token));
    }

    /** The current token for the panel, or null when there is none or it was encrypted with another APP_KEY. */
    public function plainToken(): ?string
    {
        try {
            $token = $this->token;
        } catch (DecryptException) {
            return null;
        }

        return is_string($token) && $this->matchesToken($token) ? $token : null;
    }

    /** Public feed URL (route `connectors.feed`) for the panel; null when plainToken() is. Never for MCP. */
    public function feedUrl(): ?string
    {
        $token = $this->plainToken();

        return $token === null ? null : route('connectors.feed', ['connector' => $this->key, 'token' => $token]);
    }
}
