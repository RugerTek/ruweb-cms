<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a payment provider told the site, or what the site asked it (PLAN.md E3.2 step 6): every notification,
 * accepted or not, and every API call, so the panel can show the last contact with Bancard. Append-only. The
 * monitoring ping (empty notification) is not recorded. The payload never carries a private key: providers store
 * what they received or a summary of the call. Kept out of snapshots (P0b, SnapshotService).
 *
 * @property int|null $payment_id
 * @property string $provider
 * @property string $type
 * @property bool|null $token_valid
 * @property array<string, mixed>|null $payload_json
 * @property Carbon $created_at
 */
class PaymentEvent extends Model
{
    /** A notification (webhook) for a known attempt, whatever its outcome. */
    public const TYPE_NOTIFICATION = 'notification';

    /** A notification for an attempt this site does not know. */
    public const TYPE_UNKNOWN_PAYMENT = 'unknown_payment';

    /** A notification whose token did not verify: nothing was changed. */
    public const TYPE_INVALID_TOKEN = 'invalid_token';

    /** A verified notification whose amount or currency differ from the attempt: the attempt fails. */
    public const TYPE_AMOUNT_MISMATCH = 'amount_mismatch';

    /** A call the site made to the provider API (start, query, cancel, connection test). */
    public const TYPE_API_CALL = 'api_call';

    public const UPDATED_AT = null;

    protected $fillable = ['payment_id', 'provider', 'type', 'token_valid', 'payload_json'];

    protected function casts(): array
    {
        return [
            'token_valid' => 'boolean',
            'payload_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** Latest first, for the panel. */
    public function scopeLatestFor(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
