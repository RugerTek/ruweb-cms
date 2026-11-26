<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A frozen order line: title, variant, sku and price as they were when the order was placed. The product and
 * variant links are cleared (not the line) when the product is deleted.
 *
 * @property int|null $product_id
 * @property int|null $variant_id
 * @property string $title
 * @property string|null $variant_name
 * @property string|null $sku
 * @property Money $unit_price_amount
 * @property int $quantity
 * @property Money $line_total_amount
 */
class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'variant_id',
        'title',
        'variant_name',
        'sku',
        'unit_price_amount',
        'quantity',
        'line_total_amount',
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'variant_id' => 'integer',
            'unit_price_amount' => MoneyCast::class,
            'quantity' => 'integer',
            'line_total_amount' => MoneyCast::class,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
