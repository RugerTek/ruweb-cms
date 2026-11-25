<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One admin request made during a support session: every write and every page view. `changed_fields` holds the
 * NAMES of the submitted fields (for settings, the setting keys), never a value. See
 * docs/operations/support-access.md#what-is-recorded.
 */
class SupportAudit extends Model
{
    public $timestamps = false;

    protected $table = 'support_audit';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'at' => 'datetime',
            'changed_fields' => 'array',
            'status' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(SupportSession::class, 'session_id');
    }

    public function isWrite(): bool
    {
        return ! in_array($this->method, ['GET', 'HEAD'], true);
    }
}
