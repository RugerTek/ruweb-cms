<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Stored state of one connector (`cms.connectors` key), created the first time it is activated. The feed token is
 * validated by its sha256 (`token_hash`); the encrypted copy only lets the panel show the URL again, so a snapshot
 * restored under another APP_KEY keeps serving and the panel asks to regenerate. Both stay out of toArray()/JSON.
 * Written only through App\Services\Connectors\ConnectorOperations.
 *
 * @property string $key
 * @property bool $enabled
 * @property array<string, mixed>|null $settings_json
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
    ];

    protected $hidden = ['token', 'token_hash'];

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
        ];
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
