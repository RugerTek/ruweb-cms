<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of the content revision ledger. See docs/snapshots.md.
 *
 * Rows are append-only and intentionally tiny: no payload, no diff. Their
 * purpose is to let two installations tell whether one contains the other's
 * history, not to reconstruct content.
 */
class ContentRevision extends Model
{
    public $timestamps = false;

    protected $fillable = ['hash', 'parent_hash', 'entity_type', 'entity_id', 'action', 'user_id', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shortHash(): string
    {
        return substr($this->hash, 0, 12);
    }
}
