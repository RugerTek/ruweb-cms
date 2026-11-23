<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Traits\RecordsContentRevisions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A purchasable variant of a base-language product, with its own sku, price and stock. */
class ProductVariant extends Model
{
    use RecordsContentRevisions;

    protected $fillable = [
        'product_id',
        'sku',
        'name',
        'attributes_json',
        'price_amount',
        'compare_at_price_amount',
        'stock',
        'weight_grams',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'attributes_json' => 'array',
            'price_amount' => MoneyCast::class,
            'compare_at_price_amount' => MoneyCast::class,
            'stock' => 'integer',
            'weight_grams' => 'integer',
            'order' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Variant writes coalesce into one revision of the parent product per request. */
    public function revisionSubject(): array
    {
        return [Product::class, $this->product_id];
    }
}
