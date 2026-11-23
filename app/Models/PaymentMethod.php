<?php

namespace App\Models;

use App\Support\SiteUrl;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payment method the site can offer (PLAN.md E3.4): `bank_transfer` and `cod` (provider `manual`) and `bancard`,
 * created disabled and empty by the checkout settings migration. `provider` is a key of cms.shop.payment_providers
 * (App\Shop\Payments\PaymentProviderRegistry decides whether the method is ready).
 *
 * `settings_json` holds what the customer may see (bank details, instructions). `secrets` holds credentials,
 * encrypted with APP_KEY, in the shape ['staging' => ['public_key' => …, 'private_key' => …], 'production' => […]];
 * it never leaves through toArray()/JSON, MCP, logs or views. `bound_origin` is the site origin the secrets were
 * saved under: a copy restored on another domain, or under another APP_KEY, must ask for them again (R20).
 * Written only through the panel/MCP operations of the shop.
 *
 * @property string $key
 * @property string $provider
 * @property bool $enabled
 * @property int $sort_order
 * @property array<string, mixed>|null $settings_json
 * @property string|null $environment
 * @property string|null $bound_origin
 */
class PaymentMethod extends Model
{
    public const ENVIRONMENT_STAGING = 'staging';

    public const ENVIRONMENT_PRODUCTION = 'production';

    /** The `payment_methods.environment` / `payments.environment` enum. */
    public const ENVIRONMENTS = ['staging', 'production'];

    protected $fillable = ['key', 'provider', 'enabled', 'sort_order', 'settings_json', 'secrets', 'environment', 'bound_origin'];

    protected $hidden = ['secrets'];

    protected $attributes = ['enabled' => false, 'sort_order' => 0];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'sort_order' => 'integer',
            'settings_json' => 'array',
            'secrets' => 'encrypted:array',
        ];
    }

    /**
     * Secrets are compared as stored ciphertext, never decrypted: saving new keys over ones encrypted under another
     * APP_KEY (the "load the keys again" case) must not fail while Eloquent checks what changed.
     */
    public function originalIsEquivalent($key)
    {
        if ($key === 'secrets') {
            return ($this->attributes['secrets'] ?? null) === ($this->original['secrets'] ?? null);
        }

        return parent::originalIsEquivalent($key);
    }

    /** Enabled methods in panel order (readiness is checked by the registry, not here). */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true)->orderBy('sort_order')->orderBy('id');
    }

    /** One public setting; blank strings count as missing. */
    public function setting(string $key, mixed $default = null): mixed
    {
        $value = ($this->settings_json ?? [])[$key] ?? null;

        return $value === null || (is_string($value) && trim($value) === '') ? $default : $value;
    }

    /**
     * Decrypted secrets: [] when none were saved, null when they cannot be read (another APP_KEY or a damaged
     * value). Never pass the result to a view, a log or an API response.
     *
     * @return array<string, mixed>|null
     */
    public function readSecrets(): ?array
    {
        if (($this->attributes['secrets'] ?? null) === null) {
            return [];
        }

        try {
            $secrets = $this->secrets;
        } catch (DecryptException) {
            return null;
        }

        return is_array($secrets) ? $secrets : null;
    }

    /** Whether stored secrets exist but cannot be decrypted on this site. */
    public function secretsUnreadable(): bool
    {
        return $this->readSecrets() === null;
    }

    /**
     * The key pair of one environment, or null when it is missing, incomplete or unreadable.
     *
     * @return array{public_key: string, private_key: string}|null
     */
    public function credentials(string $environment): ?array
    {
        $pair = ($this->readSecrets() ?? [])[$environment] ?? null;
        if (! is_array($pair)) {
            return null;
        }
        $public = $pair['public_key'] ?? null;
        $private = $pair['private_key'] ?? null;
        if (! is_string($public) || $public === '' || ! is_string($private) || $private === '') {
            return null;
        }

        return ['public_key' => $public, 'private_key' => $private];
    }

    /** Whether the secrets were saved under the origin this site runs on now (App\Support\SiteUrl::origin()). */
    public function isBoundToThisSite(): bool
    {
        return $this->bound_origin !== null && $this->bound_origin === SiteUrl::origin();
    }

    /** Attempts made with this method. */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'method_key', 'key');
    }
}
