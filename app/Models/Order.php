<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\ValueObjects\Money;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A guest order (PLAN.md E3.2). Items, delivery and amounts are frozen when it is placed; prices never follow the
 * catalog afterwards. The customer reaches it only through its random access token (stored as sha256, plus an
 * encrypted copy to send the link again; both stay out of toArray()/JSON), never by number. Status changes go
 * through App\Services\Shop\OrderOperations, which follows TRANSITIONS.
 *
 * @property string $number
 * @property string $status
 * @property string $locale
 * @property string $customer_name
 * @property string $customer_email
 * @property string $customer_phone
 * @property string $delivery_method
 * @property int|null $shipping_zone_id
 * @property string|null $shipping_zone_name
 * @property string $payment_method_key
 * @property string $currency
 * @property Money $subtotal_amount
 * @property Money $shipping_amount
 * @property Money $total_amount
 * @property array<int, array{product_id: int|null, variant_id: int|null, title: string, requested: int, available: int}>|null $stock_shortfall
 * @property Carbon|null $stock_applied_at
 * @property Carbon $placed_at
 */
class Order extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REFUNDED = 'refunded';

    /** Every status, in lifecycle order (the `orders.status` enum has exactly these). */
    public const STATUSES = ['pending', 'paid', 'fulfilled', 'cancelled', 'refunded'];

    /**
     * Allowed moves: pending → paid → fulfilled, pending|paid → cancelled, paid|fulfilled → refunded (PLAN.md E3.2).
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        'pending' => ['paid', 'cancelled'],
        'paid' => ['fulfilled', 'cancelled', 'refunded'],
        'fulfilled' => ['refunded'],
        'cancelled' => [],
        'refunded' => [],
    ];

    public const DELIVERY_PICKUP = 'pickup';

    public const DELIVERY_DELIVERY = 'delivery';

    public const DELIVERY_METHODS = ['pickup', 'delivery'];

    /** Characters of the token in the order page URL. */
    public const ACCESS_TOKEN_LENGTH = 64;

    protected $fillable = [
        'number',
        'status',
        'locale',
        'access_token_hash',
        'access_token',
        'customer_name',
        'customer_email',
        'customer_phone',
        'delivery_method',
        'shipping_zone_id',
        'shipping_zone_name',
        'payment_method_key',
        'currency',
        'subtotal_amount',
        'shipping_amount',
        'total_amount',
        'notes',
        'internal_notes',
        'stock_applied_at',
        'stock_shortfall',
        'ip',
        'user_agent',
        'recaptcha_score',
        'placed_at',
        'paid_at',
        'fulfilled_at',
        'cancelled_at',
        'refunded_at',
    ];

    protected $hidden = ['access_token', 'access_token_hash'];

    protected $attributes = ['status' => self::STATUS_PENDING, 'shipping_amount' => 0];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'subtotal_amount' => MoneyCast::class,
            'shipping_amount' => MoneyCast::class,
            'total_amount' => MoneyCast::class,
            'stock_shortfall' => 'array',
            'recaptcha_score' => 'float',
            'stock_applied_at' => 'datetime',
            'placed_at' => 'datetime',
            'paid_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /** A new random token for the order page URL. Store hashAccessToken($token) and the token itself. */
    public static function generateAccessToken(): string
    {
        return Str::random(self::ACCESS_TOKEN_LENGTH);
    }

    public static function hashAccessToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** The order behind a token from the URL; null for anything else (including an order number). */
    public static function findByAccessToken(string $token): ?self
    {
        if (strlen($token) !== self::ACCESS_TOKEN_LENGTH) {
            return null;
        }

        return self::query()->where('access_token_hash', self::hashAccessToken($token))->first();
    }

    /** Constant-time comparison against the stored hash. */
    public function matchesAccessToken(string $token): bool
    {
        return hash_equals((string) $this->access_token_hash, self::hashAccessToken($token));
    }

    /** The token for resending the link; null when it was encrypted under another APP_KEY or does not match. */
    public function plainAccessToken(): ?string
    {
        try {
            $token = $this->access_token;
        } catch (DecryptException) {
            return null;
        }

        return is_string($token) && $this->matchesAccessToken($token) ? $token : null;
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

    public function isPickup(): bool
    {
        return $this->delivery_method === self::DELIVERY_PICKUP;
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(OrderAddress::class);
    }

    /** The delivery address; null for pickup orders. */
    public function shippingAddress(): HasOne
    {
        return $this->hasOne(OrderAddress::class)->where('type', OrderAddress::TYPE_SHIPPING);
    }

    /** Every payment attempt, oldest first. */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    /** The current attempt (the newest row). */
    public function latestPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function shippingZone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_key', 'key');
    }
}
