<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Delivery address of an order (one `shipping` row when the customer chose home delivery).
 *
 * @property string $type
 * @property string $address
 * @property string $city
 * @property string|null $department
 * @property string|null $reference
 */
class OrderAddress extends Model
{
    public const TYPE_SHIPPING = 'shipping';

    protected $fillable = ['order_id', 'type', 'address', 'city', 'department', 'reference'];

    protected $attributes = ['type' => self::TYPE_SHIPPING];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
