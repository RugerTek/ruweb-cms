<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A delivery zone with a fixed price (PLAN.md P2). Orders copy its name and price when they are placed, so editing
 * or deleting a zone never changes an existing order.
 *
 * @property string $name
 * @property Money $price_amount
 * @property bool $enabled
 * @property int $sort_order
 */
class ShippingZone extends Model
{
    protected $fillable = ['name', 'price_amount', 'enabled', 'sort_order'];

    protected $attributes = ['enabled' => true, 'sort_order' => 0, 'price_amount' => 0];

    protected function casts(): array
    {
        return [
            'price_amount' => MoneyCast::class,
            'enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** Zones the customer can choose, in panel order. */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true)->orderBy('sort_order')->orderBy('id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
