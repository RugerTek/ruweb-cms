<?php

namespace App\Connectors\Meta;

use App\Connectors\CatalogItem;
use App\Models\Media;
use App\Services\Media\FeedImage;
use App\Support\SiteUrl;
use App\ValueObjects\Money;
use XMLWriter;

/**
 * Writes a Meta catalog feed as RSS 2.0 with `g:` fields (PLAN.md §4.5): the `<?xml` declaration first, one field
 * per line, streamed to the target in batches so the catalog never sits in memory as a whole document. Images are
 * resolved per batch to JPEG/PNG URLs (App\Services\Media\FeedImage); every URL is made absolute with
 * App\Support\SiteUrl::origin(). Limits follow Meta's catalog fields page (Updated: Oct 5, 2026).
 */
final class MetaFeedWriter
{
    public const NAMESPACE_G = 'http://base.google.com/ns/1.0';

    public const TITLE_MAX = 200;

    public const DESCRIPTION_MAX = 9999;

    public const BRAND_MAX = 100;

    public const ATTRIBUTE_MAX = 200;

    public const ADDITIONAL_IMAGES_MAX = 50;

    /** Total characters of the comma-separated `additional_image_link` value. */
    public const ADDITIONAL_IMAGE_LINK_MAX = 2000;

    /** Meta accepts up to three `additional_variant_attribute` pairs per item. */
    public const ADDITIONAL_VARIANT_ATTRIBUTES_MAX = 3;

    /** Variant attribute keys written as their own `g:` field (lowercase key => field). */
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

    /** Items per batch: images are loaded with one query per batch and the buffer is flushed to the stream. */
    private const BATCH = 100;

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
     * @param  iterable<CatalogItem>  $items  items that passed the connector's check()
     * @param  array<string, mixed>  $settings  effective connector settings (brand, condition, availability, google_product_category)
     * @param  array{title: string, description: string}  $channel
     * @param  resource  $stream
     */
    public function write(iterable $items, array $settings, array $channel, $stream): void
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('rss');
        $xml->writeAttribute('version', '2.0');
        $xml->writeAttribute('xmlns:g', self::NAMESPACE_G);
        $xml->startElement('channel');
        $xml->writeElement('title', self::clean($channel['title']));
        $xml->writeElement('link', SiteUrl::origin().'/');
        $xml->writeElement('description', self::clean($channel['description']));

        $batch = [];
        foreach ($items as $item) {
            $batch[] = $item;
            if (count($batch) === self::BATCH) {
                $this->writeBatch($xml, $batch, $settings);
                fwrite($stream, $xml->flush());
                $batch = [];
            }
        }
        $this->writeBatch($xml, $batch, $settings);

        $xml->endElement(); // channel
        $xml->endElement(); // rss
        $xml->endDocument();
        fwrite($stream, $xml->flush());
    }

    /**
     * @param  list<CatalogItem>  $items
     * @param  array<string, mixed>  $settings
     */
    private function writeBatch(XMLWriter $xml, array $items, array $settings): void
    {
        if ($items === []) {
            return;
        }
        $urls = $this->imageUrls($items);
        foreach ($items as $item) {
            $image = $item->imageMediaId === null ? null : ($urls[$item->imageMediaId] ?? null);
            if ($image === null || $item->price === null) {
                // check() leaves these out; an image that stopped converting in between never yields a broken item.
                continue;
            }
            $this->writeItem($xml, $item, $settings, $image, $urls);
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<int, ?string>  $urls  media id => JPEG/PNG URL (null when it cannot be produced)
     */
    private function writeItem(XMLWriter $xml, CatalogItem $item, array $settings, string $image, array $urls): void
    {
        $title = self::title($item);
        $description = mb_substr(self::clean($item->description), 0, self::DESCRIPTION_MAX);

        $xml->startElement('item');
        $this->field($xml, 'id', $item->id);
        if ($item->groupId !== null && $item->groupId !== $item->id) {
            $this->field($xml, 'item_group_id', $item->groupId);
        }
        $this->field($xml, 'title', $title);
        $this->field($xml, 'description', $description !== '' ? $description : $title);
        $this->field($xml, 'availability', $item->availability ?? (string) ($settings['availability'] ?? CatalogItem::IN_STOCK));
        $this->field($xml, 'condition', (string) ($settings['condition'] ?? 'new'));
        $this->field($xml, 'price', self::price($item->price));
        if ($item->salePrice !== null) {
            $this->field($xml, 'sale_price', self::price($item->salePrice));
        }
        $this->field($xml, 'link', $this->absolute($item->url));
        $this->field($xml, 'image_link', $image);
        $additional = $this->additionalImages($item, $urls, $image);
        if ($additional !== '') {
            $this->field($xml, 'additional_image_link', $additional);
        }
        $this->field($xml, 'brand', mb_substr(self::clean($item->brand ?? (string) ($settings['brand'] ?? '')), 0, self::BRAND_MAX));
        $category = self::clean((string) ($settings['google_product_category'] ?? ''));
        if ($category !== '') {
            $this->field($xml, 'google_product_category', $category);
        }
        $this->variantFields($xml, $item->variantAttributes);
        $xml->endElement();
    }

    /** @param array<string, string> $attributes */
    private function variantFields(XMLWriter $xml, array $attributes): void
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
        foreach ($fields as $field => $value) {
            $this->field($xml, $field, $value);
        }
        if ($additional !== []) {
            $this->field($xml, 'additional_variant_attribute', implode(',', $additional));
        }
    }

    /** @param array<int, ?string> $urls */
    private function additionalImages(CatalogItem $item, array $urls, string $image): string
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

    /**
     * JPEG/PNG URL of every image the batch uses, one query per batch.
     *
     * @param  list<CatalogItem>  $items
     * @return array<int, ?string>
     */
    private function imageUrls(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            if ($item->imageMediaId !== null) {
                $ids[] = $item->imageMediaId;
            }
            array_push($ids, ...$item->additionalImageMediaIds);
        }
        $urls = [];
        foreach (Media::query()->whereIn('id', array_unique($ids))->get() as $media) {
            $urls[(int) $media->id] = $media->isImage() ? $this->images->jpegUrl($media) : null;
        }

        return $urls;
    }

    private function field(XMLWriter $xml, string $name, string $value): void
    {
        $xml->writeElement('g:'.$name, self::clean($value));
    }

    private function absolute(string $url): string
    {
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        return SiteUrl::origin().'/'.ltrim($url, '/');
    }

    /** Valid UTF-8 without characters XML 1.0 forbids, trimmed. */
    private static function clean(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';

        return trim($text);
    }
}
