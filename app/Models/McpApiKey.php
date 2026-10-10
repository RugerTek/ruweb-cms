<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class McpApiKey extends Model
{
    protected $fillable = ['name', 'key_hash', 'scope', 'expires_at', 'last_used_at', 'revoked_at'];

    protected $hidden = ['key_hash'];

    protected $casts = ['expires_at' => 'datetime', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function issue(User $user, string $name, string $scope, ?string $expiresAt): string
    {
        $plain = 'ruweb_mcp_'.Str::random(48);
        $user->mcpApiKeys()->create(['name' => $name, 'scope' => $scope, 'expires_at' => $expiresAt, 'key_hash' => hash('sha256', $plain)]);

        return $plain;
    }
}
