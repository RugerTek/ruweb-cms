<?php

namespace App\Services\Products;

use App\Http\Requests\Admin\Concerns\ValidatesStaleTranslationFields;
use App\Models\Locale;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidationValidator;

/**
 * Validation shared by the panel and MCP for shop products. Amounts are integers in `cms.shop.currency` (the panel
 * turns typed values such as "150.000" into integers with MoneyHelper::parseInput before calling it); a null price
 * is a catalog item without a public price. Commerce fields (sku, prices, stock, variants) belong to the
 * base-language row and are rejected on translations. `variants`, when present, is the whole list.
 */
final class ProductInput
{
    use ValidatesStaleTranslationFields;

    public const COMMERCE_FIELDS = ['sku', 'price_amount', 'compare_at_price_amount', 'has_variants', 'track_stock', 'allow_backorder', 'stock', 'weight_grams', 'dimensions_json', 'variants'];

    public const MAX_VARIANTS = 100;

    public function __construct(private array $data, private ?Product $product = null, private bool $partial = false, private bool $draftOnly = false) {}

    public function input(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    public function all(): array
    {
        return $this->data;
    }

    public function validate(): array
    {
        $input = $this->data;
        // A patch may send one of the two prices: the pair is checked with the stored other one, which is not rewritten.
        $stored = [];
        if ($this->partial && $this->product && (array_key_exists('price_amount', $input) xor array_key_exists('compare_at_price_amount', $input))) {
            $missing = array_key_exists('price_amount', $input) ? 'compare_at_price_amount' : 'price_amount';
            $input[$missing] = $this->product->{$missing}?->amount;
            $stored[] = $missing;
        }
        $validator = Validator::make($input, $this->rules(), [
            'sku.unique' => __('shop.sku_unique'),
            'variants.*.sku.unique' => __('shop.sku_unique'),
            'variants.*.sku.distinct' => __('shop.sku_unique'),
            'variants.*.sku.different' => __('shop.sku_unique'),
            'stock.required' => __('shop.stock_required_tracked'),
            'variants.*.stock.required' => __('shop.stock_required_tracked'),
            'compare_at_price_amount.gt' => __('shop.compare_at_price_greater'),
            'variants.*.compare_at_price_amount.gt' => __('shop.compare_at_price_greater'),
            'compare_at_price_amount.required_with' => __('shop.compare_at_price_greater'),
            'variants.*.price_amount.required_with' => __('shop.compare_at_price_greater'),
            'price_amount.required_with' => __('shop.compare_at_price_greater'),
            '*.prohibited' => __('shop.commerce_fields_base_only'),
        ]);
        $validator->after(fn (ValidationValidator $validator) => $this->checkVariantList($validator));
        if (! $this->partial) {
            $this->withValidator($validator);
        }
        $data = Arr::except($validator->validate(), $stored);
        if (($this->draftOnly || $this->partial) && array_key_exists('meta_json', $this->data)) {
            $data['meta_json'] = $this->data['meta_json'];
        }
        foreach (['price_amount', 'compare_at_price_amount', 'stock', 'weight_grams'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = (int) $data[$key];
            }
        }
        if (array_key_exists('variants', $data)) {
            $data['variants'] = array_map(fn (array $variant) => $this->normalizeVariant($variant), array_values($data['variants'] ?? []));
        }

        return $data;
    }

    public function rules(): array
    {
        $locale = $this->locale();
        $media = ['nullable', 'integer', 'exists:media,id'];
        $amount = ['nullable', 'integer', 'min:0', 'max:'.PHP_INT_MAX];
        $variantIds = $this->product?->exists ? $this->product->variants()->pluck('id')->all() : [];
        // A submitted list replaces the stored one, so its own skus may move between rows; otherwise they still count.
        $replacedIds = array_key_exists('variants', $this->data) ? $variantIds : [];
        $tracksStock = (bool) ($this->data['track_stock'] ?? $this->product?->track_stock ?? false);
        $hasVariants = $this->hasVariants();
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(Locale::catalogCodes()), Rule::unique('products', 'slug')->where('locale', $locale)->ignore($this->product?->id)],
            'locale' => ['nullable', 'string', Rule::in(Locale::installedCodes())],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'status' => ['required', 'string', Rule::in(config('cms.statuses'))],
            'published_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:published_at'],
            'featured_image' => $media,
            'gallery' => ['nullable', 'array', 'max:50'],
            'gallery.*' => ['integer', 'distinct', 'exists:media,id'],
            'brands' => ['nullable', 'array'],
            'brands.*' => ['integer', 'distinct', Rule::exists('taxonomies', 'id')->where('type', Product::BRAND_TAXONOMY)->where('locale', $locale)],
            'meta_json' => ['nullable', 'array'],
            'meta_json.title' => ['nullable', 'string', 'max:255'],
            'meta_json.description' => ['nullable', 'string', 'max:320'],
            'acknowledged_fields' => ['nullable', 'array'],
            'acknowledged_fields.*' => ['string'],
            'expected_revision' => ['nullable', 'string', 'size:64'],

            'sku' => ['nullable', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($this->product?->id), Rule::unique('product_variants', 'sku')->whereNotIn('id', $replacedIds)],
            'price_amount' => [...$amount, 'required_with:compare_at_price_amount'],
            'compare_at_price_amount' => [...$amount, 'gt:price_amount'],
            'has_variants' => ['sometimes', 'boolean'],
            'track_stock' => ['sometimes', 'boolean'],
            'allow_backorder' => ['sometimes', 'boolean'],
            'stock' => [Rule::requiredIf($tracksStock && ! $hasVariants && ! $this->partial), 'nullable', 'integer', 'min:-1000000', 'max:1000000000'],
            'weight_grams' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'dimensions_json' => ['nullable', 'array:length,width,height'],
            'dimensions_json.*' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'variants' => ['nullable', 'array', 'max:'.self::MAX_VARIANTS],
            'variants.*' => ['array:id,sku,name,attributes_json,price_amount,compare_at_price_amount,stock,weight_grams'],
            'variants.*.id' => ['nullable', 'integer', 'distinct', Rule::in($variantIds)],
            'variants.*.sku' => ['nullable', 'string', 'max:100', 'distinct', Rule::unique('products', 'sku')->ignore($this->product?->id), Rule::unique('product_variants', 'sku')->whereNotIn('id', $variantIds)],
            'variants.*.name' => ['required', 'string', 'max:255'],
            'variants.*.attributes_json' => ['nullable', 'array', 'max:20'],
            'variants.*.attributes_json.*' => ['nullable', 'string', 'max:255'],
            'variants.*.price_amount' => [...$amount, 'required_with:variants.*.compare_at_price_amount'],
            'variants.*.compare_at_price_amount' => [...$amount, 'gt:variants.*.price_amount'],
            'variants.*.stock' => [Rule::requiredIf($tracksStock), 'nullable', 'integer', 'min:-1000000', 'max:1000000000'],
            'variants.*.weight_grams' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ];
        if (filled($this->data['sku'] ?? null)) {
            $rules['variants.*.sku'][] = 'different:sku';
        } elseif ($this->partial && filled($this->product?->sku)) {
            $rules['variants.*.sku'][] = Rule::notIn([$this->product->sku]);
        }
        // Publication dates change only through the publication operations.
        if ($this->draftOnly || $this->partial) {
            unset($rules['published_at'], $rules['expires_at']);
        }
        if ($this->partial) {
            $rules['title'][0] = 'sometimes';
            $rules['slug'][0] = 'sometimes';
            unset($rules['locale'], $rules['status']);
        }
        if ($locale !== Locale::baseCode()) {
            foreach (self::COMMERCE_FIELDS as $field) {
                $rules = array_filter($rules, fn (string $key) => $key !== $field && ! str_starts_with($key, $field.'.'), ARRAY_FILTER_USE_KEY);
                $rules[$field] = ['prohibited'];
            }
        }

        return $rules;
    }

    /** Language of the row being written: the stored one on updates, the requested or base one on create. */
    public function locale(): string
    {
        return $this->product?->locale ?? ($this->data['locale'] ?? null) ?? Locale::baseCode();
    }

    /** Effective has_variants after this write: submitted value, otherwise the stored one on partial writes. */
    private function hasVariants(): bool
    {
        if (array_key_exists('has_variants', $this->data)) {
            return filter_var($this->data['has_variants'], FILTER_VALIDATE_BOOLEAN);
        }

        return $this->partial && (bool) $this->product?->has_variants;
    }

    /** A product with variants needs at least one; a simple product cannot carry a list. */
    private function checkVariantList(ValidationValidator $validator): void
    {
        if ($this->locale() !== Locale::baseCode()) {
            return;
        }
        $submitted = array_key_exists('variants', $this->data);
        $count = $submitted ? count((array) $this->data['variants']) : ($this->partial ? (int) $this->product?->variants()->count() : 0);
        $hasVariants = $this->hasVariants();
        if ($hasVariants && $count === 0) {
            $validator->errors()->add('variants', __('shop.variant_required'));
        }
        if (! $hasVariants && $submitted && $count > 0) {
            $validator->errors()->add('variants', __('validation.prohibited', ['attribute' => 'variants']));
        }
    }

    private function normalizeVariant(array $variant): array
    {
        foreach (['price_amount', 'compare_at_price_amount', 'stock', 'weight_grams', 'id'] as $key) {
            $variant[$key] = isset($variant[$key]) ? (int) $variant[$key] : null;
        }
        $variant['sku'] = filled($variant['sku'] ?? null) ? $variant['sku'] : null;
        $variant['attributes_json'] = ! empty($variant['attributes_json']) ? $variant['attributes_json'] : null;

        return $variant;
    }

    protected function staleTranslationEntity(): ?Model
    {
        return $this->product;
    }
}
