<?php

namespace App\Models;

use App\Support\Username;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'username', 'email', 'password', 'role', 'can_manage_custom_code'])]
#[Hidden(['password', 'remember_token', 'mcp_api_key_hash', 'pending_email_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function username(): Attribute
    {
        return Attribute::make(set: fn ($value) => Username::normalize($value));
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'pending_email_expires_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'security_version' => 'integer',
            'can_manage_custom_code' => 'boolean',
            'password' => 'hashed',
            'mcp_api_key_generated_at' => 'datetime',
            'mcp_api_key_last_used_at' => 'datetime',
        ];
    }

    public function generateMcpApiKey(): string
    {
        if ($this->mcp_api_key_hash) $this->mcpApiKeys()->where('key_hash', $this->mcp_api_key_hash)->update(['revoked_at' => now()]);
        $plainApiKey = 'ruweb_mcp_'.Str::random(48);

        $this->forceFill([
            'mcp_api_key_hash' => hash('sha256', $plainApiKey),
            'mcp_api_key_generated_at' => now(),
            'mcp_api_key_last_used_at' => null,
        ])->save();

        $this->mcpApiKeys()->create(['name' => __('security.key_default'), 'scope' => 'full', 'key_hash' => $this->mcp_api_key_hash]);
        return $plainApiKey;
    }

    public function revokeMcpApiKey(): void
    {
        if ($this->mcp_api_key_hash) $this->mcpApiKeys()->where('key_hash', $this->mcp_api_key_hash)->update(['revoked_at' => now()]);
        $this->forceFill([
            'mcp_api_key_hash' => null,
            'mcp_api_key_generated_at' => null,
            'mcp_api_key_last_used_at' => null,
        ])->save();
    }

    public function hasMcpApiKey(): bool
    {
        return filled($this->mcp_api_key_hash);
    }

    public static function findByMcpApiKey(string $apiKey): ?self
    {
        return static::query()
            ->where('mcp_api_key_hash', hash('sha256', $apiKey))
            ->first();
    }

    public function markMcpApiKeyAsUsed(): void
    {
        $this->forceFill([
            'mcp_api_key_last_used_at' => now(),
        ])->save();
    }

    public function mcpApiKeys(): HasMany
    {
        return $this->hasMany(McpApiKey::class);
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function canManageCustomCode(): bool
    {
        return $this->isAdmin() && $this->can_manage_custom_code;
    }

    public function isEditor(): bool
    {
        return $this->role === 'editor';
    }

    public function uploadedMedia(): HasMany
    {
        return $this->hasMany(Media::class, 'uploaded_by');
    }
}
