<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One entry of a RügerTek operator through a support pass (docs/operations/support-access.md#what-is-recorded).
 * Operator data is copied, so the record survives even if the support account is later removed.
 */
class SupportSession extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(SupportAudit::class, 'session_id');
    }

    public function isActive(?Carbon $now = null): bool
    {
        return $this->ended_at === null && $this->expires_at->isAfter($now ?? Carbon::now());
    }

    /** active, expired (time ran out without an explicit exit) or ended (exit, logout, replaced). */
    public function state(): string
    {
        if ($this->ended_at !== null) {
            return $this->end_reason === 'expired' ? 'expired' : 'ended';
        }

        return $this->isActive() ? 'active' : 'expired';
    }

    /** When the session stopped (or will stop): the explicit end, else the expiry. */
    public function endedOrExpiresAt(): Carbon
    {
        return $this->ended_at ?? $this->expires_at;
    }

    public function durationMinutes(): int
    {
        $end = $this->isActive() ? Carbon::now() : $this->endedOrExpiresAt();

        return max(0, (int) floor($this->started_at->diffInSeconds($end) / 60));
    }
}
