<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product (or variant) in a cart, with its quantity only. `line_key` is derived on save so the database keeps a
 * single row per cart, product and variant (see the checkout tables migration).
 *
 * @property int $cart_id
 * @property int $product_id
 * @property int|null $variant_id
 * @property string $line_key
 * @property int $quantity
 */
class CartItem extends Model
{
    protected $fillable = ['cart_id', 'product_id', 'variant_id', 'quantity'];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'variant_id' => 'integer',
            'quantity' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            $item->line_key = self::lineKey((int) $item->product_id, $item->variant_id === null ? null : (int) $item->variant_id);
        });
    }

    /** "{product_id}:{variant_id or 0}": the unique line of a cart. */
    public static function lineKey(int $productId, ?int $variantId): string
    {
        return $productId.':'.($variantId ?? 0);
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
