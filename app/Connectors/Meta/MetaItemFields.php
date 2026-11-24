<?php

namespace App\Connectors\Meta;

use App\Connectors\CatalogItem;
use App\Models\Media;
use App\Services\Media\FeedImage;
use App\Support\SiteUrl;
use App\ValueObjects\Money;

/**
 * The fields Meta receives for one catalog item, shared by the RSS feed (MetaFeedWriter, `g:` elements) and the
 * instant updates of Meta v2 (App\Services\Connectors\Meta\MetaItemsPublisher, `items_batch` data): both send the
 * same names and the same values, in the same order, so an item never changes depending on how it reached Meta.
 * Values are plain strings, already cleaned (valid UTF-8 without characters XML 1.0 forbids, trimmed); URLs are
 * absolute on App\Support\SiteUrl::origin() and images are JPEG/PNG (App\Services\Media\FeedImage). Limits follow
 * Meta's catalog fields page (Updated: Oct 28, 2026; read 2026-11-24).
 */
final class MetaItemFields
{
    public const TITLE_MAX = 200;

    public const DESCRIPTION_MAX = 9999;

    public const BRAND_MAX = 100;

    public const ATTRIBUTE_MAX = 200;

    /** `product_type`: the category path (`Ropa > Remeras`). */
    public const PRODUCT_TYPE_MAX = 1000;

    /** `custom_label_0` to `custom_label_4`. */
    public const CUSTOM_LABEL_MAX = 100;

    /** Separator between category levels in `product_type`, as in Meta's example. */
    public const CATEGORY_SEPARATOR = ' > ';

    /** `custom_label_1` of items with a sale price, to build sets and campaigns of products on sale. */
    public const SALE_LABEL = 'oferta';

    public const ADDITIONAL_IMAGES_MAX = 50;

    /** Total characters of the comma-separated `additional_image_link` value. */
    public const ADDITIONAL_IMAGE_LINK_MAX = 2000;

    /** Meta accepts up to three `additional_variant_attribute` pairs per item. */
    public const ADDITIONAL_VARIANT_ATTRIBUTES_MAX = 3;

    /** Variant attribute keys written as their own field (lowercase key => field). */
    public const VARIANT_FIELDS = [
        'color' => 'color',
        'colour' => 'color',
        'size' => 'size',
        'talle' => 'size',
        'talla' => 'size',
        'tamaño' => 'size',
        'pattern' => 'pattern',
        'estampado' => 'pattern',
        'material' => 'material',
        'gender' => 'gender',
    ];

    /** Values Meta accepts for `gender`; any other value goes to `additional_variant_attribute`. */
    public const GENDERS = ['female', 'male', 'unisex'];

    public function __construct(private readonly FeedImage $images) {}

    /** Title as Meta receives it: plain, valid XML text cut to TITLE_MAX characters. */
    public static function title(CatalogItem $item): string
    {
        return mb_substr(self::clean($item->title), 0, self::TITLE_MAX);
    }

    public static function titleIsTruncated(CatalogItem $item): bool
    {
        return mb_strlen(self::clean($item->title)) > self::TITLE_MAX;
    }

    /** `150000 PYG`: amount, a space and the ISO 4217 code (Money keeps whole units, no symbol). */
    public static function price(Money $money): string
    {
        return $money->amount.' '.strtoupper($money->currency);
    }

    /**
     * JPEG/PNG URL of every image the items use (main and gallery), one query for all of them.
     *
     * @param  iterable<CatalogItem>  $items
     * @return array<int, ?string> media id => URL (null when it cannot be produced)
     */
    public function imageUrls(iterable $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            if ($item->imageMediaId !== null) {
                $ids[] = $item->imageMediaId;
            }
            array_push($ids, ...$item->additionalImageMediaIds);
        }
        if ($ids === []) {
            return [];
        }
        $urls = [];
        foreach (Media::query()->whereIn('id', array_unique($ids))->get() as $media) {
            $urls[(int) $media->id] = $media->isImage() ? $this->images->jpegUrl($media) : null;
        }

        return $urls;
    }

    /**
     * The item's fields in feed order, or null when it cannot be sent: no price, or a main image that does not
     * resolve in $urls (check() leaves those out; an image that stopped converting in between never yields a broken
     * item). Optional fields with an empty value are left out; `brand` is always sent.
     *
     * @param  array<string, mixed>  $settings  effective connector settings (brand, condition, availability, google_product_category)
     * @param  array<int, ?string>  $urls  from imageUrls()
     * @return array<string, string>|null
     */
    public static function fields(CatalogItem $item, array $settings, array $urls): ?array
    {
        $image = $item->imageMediaId === null ? null : ($urls[$item->imageMediaId] ?? null);
        if ($image === null || $item->price === null) {
            return null;
        }
        $title = self::title($item);
        $description = mb_substr(self::clean($item->description), 0, self::DESCRIPTION_MAX);

        $fields = ['id' => $item->id];
        if ($item->groupId !== null && $item->groupId !== $item->id) {
            $fields['item_group_id'] = $item->groupId;
        }
        $fields['title'] = $title;
        $fields['description'] = $description !== '' ? $description : $title;
        $fields['availability'] = $item->availability ?? (string) ($settings['availability'] ?? CatalogItem::IN_STOCK);
        $fields['condition'] = (string) ($settings['condition'] ?? 'new');
        $fields['price'] = self::price($item->price);
        if ($item->salePrice !== null) {
            $fields['sale_price'] = self::price($item->salePrice);
        }
        $fields['link'] = self::absolute($item->url);
        $fields['image_link'] = $image;
        $additional = self::additionalImages($item, $urls, $image);
        if ($additional !== '') {
            $fields['additional_image_link'] = $additional;
        }
        $fields['brand'] = mb_substr(self::clean($item->brand ?? (string) ($settings['brand'] ?? '')), 0, self::BRAND_MAX);
        $category = self::clean((string) ($settings['google_product_category'] ?? ''));
        if ($category !== '') {
            $fields['google_product_category'] = $category;
        }
        $fields += self::campaignFields($item);
        $fields += self::variantFields($item->variantAttributes);

        return array_map(fn (string $value): string => self::clean($value), $fields);
    }

    /** Valid UTF-8 without characters XML 1.0 forbids, trimmed. */
    public static function clean(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';

        return trim($text);
    }

    /**
     * Fields to build product sets and campaigns in Commerce Manager: `product_type` = category path,
     * `custom_label_0` = top-level category, `custom_label_1` = SALE_LABEL with a sale price, `custom_label_2` = the
     * product's own brand (never the connector default). Empty values are left out.
     *
     * @return array<string, string>
     */
    private static function campaignFields(CatalogItem $item): array
    {
        $path = array_values(array_filter(
            array_map(fn ($name) => self::clean((string) $name), $item->categoryPath),
            fn (string $name) => $name !== '',
        ));
        $labels = [
            'product_type' => [implode(self::CATEGORY_SEPARATOR, $path), self::PRODUCT_TYPE_MAX],
            'custom_label_0' => [$path[0] ?? '', self::CUSTOM_LABEL_MAX],
            'custom_label_1' => [$item->salePrice !== null ? self::SALE_LABEL : '', self::CUSTOM_LABEL_MAX],
            'custom_label_2' => [self::clean($item->brand ?? ''), self::CUSTOM_LABEL_MAX],
        ];
        $fields = [];
        foreach ($labels as $field => [$value, $max]) {
            $value = trim(mb_substr($value, 0, $max));
            if ($value !== '') {
                $fields[$field] = $value;
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, string>  $attributes
     * @return array<string, string>
     */
    private static function variantFields(array $attributes): array
    {
        $fields = [];
        $additional = [];
        foreach ($attributes as $key => $value) {
            $value = mb_substr(self::clean((string) $value), 0, self::ATTRIBUTE_MAX);
            $name = self::clean((string) $key);
            if ($value === '' || $name === '') {
                continue;
            }
            $field = self::VARIANT_FIELDS[mb_strtolower($name)] ?? null;
            if ($field === 'gender') {
                $field = in_array(mb_strtolower($value), self::GENDERS, true) ? 'gender' : null;
                $value = $field === null ? $value : mb_strtolower($value);
            }
            if ($field !== null && ! isset($fields[$field])) {
                $fields[$field] = $value;

                continue;
            }
            if (count($additional) < self::ADDITIONAL_VARIANT_ATTRIBUTES_MAX) {
                // `Key:Value` pairs separated by commas: those two characters cannot appear inside a pair.
                $additional[] = str_replace([':', ','], ' ', $name).':'.str_replace([':', ','], ' ', $value);
            }
        }
        if ($additional !== []) {
            $fields['additional_variant_attribute'] = implode(',', $additional);
        }

        return $fields;
    }

    /** @param array<int, ?string> $urls */
    private static function additionalImages(CatalogItem $item, array $urls, string $image): string
    {
        $links = [];
        $length = 0;
        foreach ($item->additionalImageMediaIds as $id) {
            $url = $urls[$id] ?? null;
            if ($url === null || $url === $image || in_array($url, $links, true) || str_contains($url, ',')) {
                continue;
            }
            $added = strlen($url) + ($links === [] ? 0 : 1);
            if ($length + $added > self::ADDITIONAL_IMAGE_LINK_MAX || count($links) === self::ADDITIONAL_IMAGES_MAX) {
                break;
            }
            $links[] = $url;
            $length += $added;
        }

        return implode(',', $links);
    }

    private static function absolute(string $url): string
    {
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        return SiteUrl::origin().'/'.ltrim($url, '/');
    }
}
