<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One payment attempt of an order (PLAN.md E3.2). A failed or expired Bancard attempt is never reused: retrying
 * creates a new row, whose id becomes its `shop_process_id`. `environment` is the one the attempt was created in,
 * so its notifications are verified with that environment's key even after the site switches. Status changes go
 * through App\Services\Shop\PaymentOperations, which follows TRANSITIONS.
 *
 * @property int $order_id
 * @property string $method_key
 * @property string $provider
 * @property string $status
 * @property Money $amount
 * @property string $currency
 * @property string|null $environment
 * @property int|null $shop_process_id
 * @property string|null $process_id
 * @property string|null $authorization_number
 * @property string|null $ticket_number
 * @property string|null $response_code
 * @property string|null $response_description
 * @property array<string, mixed>|null $payload_json
 * @property Carbon|null $confirmed_at
 * @property Carbon $created_at
 */
class Payment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REFUNDED = 'refunded';

    /** Every status (the `payments.status` enum has exactly these). */
    public const STATUSES = ['pending', 'confirmed', 'failed', 'expired', 'refunded'];

    /**
     * Allowed moves: pending → confirmed | failed | expired, confirmed → refunded (PLAN.md E3.2).
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        'pending' => ['confirmed', 'failed', 'expired'],
        'confirmed' => ['refunded'],
        'failed' => [],
        'expired' => [],
        'refunded' => [],
    ];

    /** Largest Bancard shop_process_id (15 digits). */
    public const MAX_SHOP_PROCESS_ID = 999_999_999_999_999;

    protected $fillable = [
        'order_id',
        'method_key',
        'provider',
        'status',
        'amount',
        'currency',
        'environment',
        'shop_process_id',
        'process_id',
        'authorization_number',
        'ticket_number',
        'response_code',
        'response_description',
        'payload_json',
        'confirmed_at',
        'failed_at',
        'expired_at',
        'refunded_at',
    ];

    protected $attributes = ['status' => self::STATUS_PENDING];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'shop_process_id' => 'integer',
            'payload_json' => 'array',
            'confirmed_at' => 'datetime',
            'failed_at' => 'datetime',
            'expired_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** No transition is left (failed, expired or refunded). A confirmed attempt can still be refunded. */
    public function isFinal(): bool
    {
        return $this->availableTransitions() === [];
    }

    /** @return list<string> */
    public function availableTransitions(): array
    {
        return self::TRANSITIONS[$this->status] ?? [];
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, $this->availableTransitions(), true);
    }

    /** Attempts of a provider still waiting for their outcome since before $before (abandoned-payment sweep). */
    public function scopePendingSince(Builder $query, string $provider, Carbon $before): Builder
    {
        return $query->where('provider', $provider)->where('status', self::STATUS_PENDING)->where('created_at', '<=', $before);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'method_key', 'key');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class)->orderBy('id');
    }
}
