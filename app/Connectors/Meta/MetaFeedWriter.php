<?php

namespace App\Connectors\Meta;

use App\Connectors\CatalogItem;
use App\Support\SiteUrl;
use App\ValueObjects\Money;
use XMLWriter;

/**
 * Writes a Meta catalog feed as RSS 2.0 with `g:` fields (PLAN.md §4.5): the `<?xml` declaration first, one field
 * per line, streamed to the target in batches so the catalog never sits in memory as a whole document. The fields
 * of each item come from MetaItemFields (shared with the items_batch updates of Meta v2): images are resolved per
 * batch to JPEG/PNG URLs and every URL is absolute on App\Support\SiteUrl::origin(). The limits below are kept as
 * aliases of MetaItemFields for existing callers.
 */
final class MetaFeedWriter
{
    public const NAMESPACE_G = 'http://base.google.com/ns/1.0';

    public const TITLE_MAX = MetaItemFields::TITLE_MAX;

    public const DESCRIPTION_MAX = MetaItemFields::DESCRIPTION_MAX;

    public const BRAND_MAX = MetaItemFields::BRAND_MAX;

    public const ATTRIBUTE_MAX = MetaItemFields::ATTRIBUTE_MAX;

    public const PRODUCT_TYPE_MAX = MetaItemFields::PRODUCT_TYPE_MAX;

    public const CUSTOM_LABEL_MAX = MetaItemFields::CUSTOM_LABEL_MAX;

    public const CATEGORY_SEPARATOR = MetaItemFields::CATEGORY_SEPARATOR;

    public const SALE_LABEL = MetaItemFields::SALE_LABEL;

    public const ADDITIONAL_IMAGES_MAX = MetaItemFields::ADDITIONAL_IMAGES_MAX;

    public const ADDITIONAL_IMAGE_LINK_MAX = MetaItemFields::ADDITIONAL_IMAGE_LINK_MAX;

    public const ADDITIONAL_VARIANT_ATTRIBUTES_MAX = MetaItemFields::ADDITIONAL_VARIANT_ATTRIBUTES_MAX;

    public const VARIANT_FIELDS = MetaItemFields::VARIANT_FIELDS;

    public const GENDERS = MetaItemFields::GENDERS;

    /** Items per batch: images are loaded with one query per batch and the buffer is flushed to the stream. */
    private const BATCH = 100;

    public function __construct(private readonly MetaItemFields $fields) {}

    /** Title as Meta receives it: plain, valid XML text cut to TITLE_MAX characters. */
    public static function title(CatalogItem $item): string
    {
        return MetaItemFields::title($item);
    }

    public static function titleIsTruncated(CatalogItem $item): bool
    {
        return MetaItemFields::titleIsTruncated($item);
    }

    /** `150000 PYG`: amount, a space and the ISO 4217 code (Money keeps whole units, no symbol). */
    public static function price(Money $money): string
    {
        return MetaItemFields::price($money);
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
        $urls = $this->fields->imageUrls($items);
        foreach ($items as $item) {
            $fields = MetaItemFields::fields($item, $settings, $urls);
            if ($fields === null) {
                continue;
            }
            $xml->startElement('item');
            foreach ($fields as $name => $value) {
                $xml->writeElement('g:'.$name, $value);
            }
            $xml->endElement();
        }
    }

    /** Valid UTF-8 without characters XML 1.0 forbids, trimmed. */
    private static function clean(string $text): string
    {
        return MetaItemFields::clean($text);
    }
}
