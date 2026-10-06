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

#[Fillable(['name', 'username', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token', 'mcp_api_key_hash'])]
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
            'password' => 'hashed',
            'mcp_api_key_generated_at' => 'datetime',
            'mcp_api_key_last_used_at' => 'datetime',
        ];
    }

    public function generateMcpApiKey(): string
    {
        $plainApiKey = 'ruweb_mcp_'.Str::random(48);

        $this->forceFill([
            'mcp_api_key_hash' => hash('sha256', $plainApiKey),
            'mcp_api_key_generated_at' => now(),
            'mcp_api_key_last_used_at' => null,
        ])->save();

        return $plainApiKey;
    }

    public function revokeMcpApiKey(): void
    {
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

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
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
