<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Contracts\Editorial\Mediable;
use App\Contracts\Editorial\Previewable;
use App\Contracts\Editorial\Publishable;
use App\Contracts\Editorial\Seoable;
use App\Services\Editorial\EditorialActor;
use App\Services\Editorial\EditorialHistory;
use App\Support\RichTextSanitizer;
use App\Support\ShopModule;
use App\Traits\ComparesJsonByContent;
use App\Traits\HasMedia;
use App\Traits\HasPublicationState;
use App\Traits\HasTaxonomies;
use App\Traits\HasTranslations;
use App\Traits\RecordsContentRevisions;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Shop catalog product (optional module, `cms.shop.mode`). Same editorial contract as Post: translations by row,
 * revisions, editorial history and the editor publication lock. Commerce fields (sku, prices, stock, variants)
 * are read from the base-language row of the translation group.
 */
class Product extends Model implements Mediable, Previewable, Publishable, Seoable
{
    use ComparesJsonByContent, HasMedia, HasPublicationState, HasTaxonomies, HasTranslations;
    use RecordsContentRevisions;

    public const BRAND_TAXONOMY = 'brand';

    /** Shared with posts (`cms.taxonomy_types`); categories() comes from HasTaxonomies. */
    public const CATEGORY_TAXONOMY = 'category';

    protected static function booted(): void
    {
        static::saving(function ($model) {
            if ($model->exists && $model->isDirty()) {
                app(EditorialHistory::class)->captureBefore($model->newQuery()->findOrFail($model->id));
            }
            if (EditorialActor::current()?->isEditor() && ($model->status === 'published' || $model->getOriginal('status') === 'published') && (! $model->exists || $model->isDirty())) {
                abort(403, __('editorial.publish_forbidden'));
            }
        });
        static::deleting(function ($model) {
            abort_if(EditorialActor::current()?->isEditor() && $model->status === 'published', 403, __('editorial.publish_forbidden'));
        });
        static::saved(fn ($model) => app(EditorialHistory::class)->captureAfter($model));
    }

    protected $fillable = [
        'locale',
        'translation_group_id',
        'translation_status',
        'source_fingerprint',
        'source_field_hashes',
        'sku',
        'title',
        'slug',
        'excerpt',
        'description',
        'price_amount',
        'compare_at_price_amount',
        'has_variants',
        'track_stock',
        'allow_backorder',
        'stock',
        'weight_grams',
        'dimensions_json',
        'meta_json',
        'status',
        'published_at',
        'expires_at',
        'created_by',
        'updated_by',
    ];

    /**
     * Rich text is sanitised on the way in, so every writer — admin form, MCP API,
     * seeders — stores the same allowlisted markup.
     */
    protected function setDescriptionAttribute(?string $value): void
    {
        $this->attributes['description'] = app(RichTextSanitizer::class)->sanitize($value);
    }

    protected function casts(): array
    {
        return [
            'source_field_hashes' => 'array',
            'price_amount' => MoneyCast::class,
            'compare_at_price_amount' => MoneyCast::class,
            'has_variants' => 'boolean',
            'track_stock' => 'boolean',
            'allow_backorder' => 'boolean',
            'stock' => 'integer',
            'weight_grams' => 'integer',
            'dimensions_json' => 'array',
            'meta_json' => 'array',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('order')->orderBy('id');
    }

    public function brands(): MorphToMany
    {
        return $this->taxonomiesByType(self::BRAND_TAXONOMY);
    }

    /** Lowest variant price for products with variants, otherwise the product price; null = no public price. */
    public function displayPrice(): ?Money
    {
        if (! $this->has_variants) {
            return $this->price_amount;
        }

        $amount = $this->variants
            ->map(fn (ProductVariant $variant) => $variant->price_amount?->amount)
            ->filter(fn (?int $amount) => $amount !== null)
            ->min();

        return $amount !== null ? Money::fromInt((int) $amount) : null;
    }

    public function meta(): array
    {
        return $this->meta_json ?? [];
    }

    public function url(): string
    {
        return $this->localizedUrl();
    }

    public function localizedUrl(): string
    {
        $path = '/'.app(ShopModule::class)->path().'/'.ltrim($this->slug, '/');

        if ($this->isBaseLocale()) {
            return $path;
        }

        return '/'.$this->locale.$path;
    }

    public function seoTitleFallback(): ?string
    {
        return $this->title;
    }

    public function previewView(): string
    {
        return 'shop.show';
    }

    public function previewData(): array
    {
        return [
            'product' => $this,
            'page' => $this,
        ];
    }
}
