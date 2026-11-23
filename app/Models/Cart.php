<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Guest cart behind the `ruweb_cart` cookie (PLAN.md E3.2 step 1). Prices are never stored here: they are read
 * from the products every time the cart is shown. Written only through App\Services\Shop\CartService.
 *
 * @property string $token
 * @property Carbon $expires_at
 */
class Cart extends Model
{
    /** Length of the cookie value. */
    public const TOKEN_LENGTH = 64;

    protected $fillable = ['token', 'expires_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public static function generateToken(): string
    {
        return Str::random(self::TOKEN_LENGTH);
    }

    /** The live cart behind a cookie value; null when unknown or expired. */
    public static function findByToken(?string $token): ?self
    {
        if ($token === null || strlen($token) !== self::TOKEN_LENGTH) {
            return null;
        }

        return self::query()->where('token', $token)->where('expires_at', '>', now())->first();
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<=', now());
    }
}
