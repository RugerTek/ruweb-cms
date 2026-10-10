<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OauthAccessToken extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'token',
        'user_id',
        'client_id',
        'scope',
        'expires_at',
        'last_used_at',
        'revoked_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isValid(): bool
    {
        return ! $this->isExpired() && ! $this->isRevoked();
    }

    public function touchLastUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }
}
