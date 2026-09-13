<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per path that returned 404, with a counter. Feeds the redirects screen so the
 * client can see which legacy URLs still receive traffic and redirect them.
 */
class NotFoundHit extends Model
{
    protected $fillable = [
        'path',
        'method',
        'hit_count',
        'referer',
        'user_agent',
        'ip_address',
        'first_seen_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'hit_count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public static function record(string $path, string $method, ?string $referer, ?string $userAgent, ?string $ip): void
    {
        $path = Redirect::normalizePath($path);
        $now = now();

        $hit = static::query()->firstOrNew(['path' => $path]);
        $hit->fill([
            'method' => strtoupper($method),
            'referer' => $referer ? mb_substr($referer, 0, 255) : null,
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 500) : null,
            'ip_address' => $ip,
            'first_seen_at' => $hit->first_seen_at ?: $now,
            'last_seen_at' => $now,
        ]);
        $hit->hit_count = ((int) $hit->hit_count) + 1;
        $hit->save();
    }
}
