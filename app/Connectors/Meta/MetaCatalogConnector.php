<?php

namespace App\Connectors\Meta;

use App\Connectors\CatalogItem;
use App\Connectors\ConnectorRequirement;
use App\Connectors\FeedWarning;
use App\Connectors\Sources\ShopProductSource;
use App\Contracts\Connectors\FeedConnector;
use App\Models\Media;
use App\Models\Setting;
use App\Services\Media\FeedImage;
use Illuminate\Validation\Rule;

/**
 * Meta (Facebook and Instagram) catalog feed (`cms.connectors.meta`, PLAN.md §4.3–4.7): the shop products as an
 * RSS XML file that Commerce Manager downloads on a schedule. check() decides what enters (skips and notices);
 * MetaFeedWriter writes the document. The URLs below are Meta's official pages, used by the panel guide.
 */
class MetaCatalogConnector implements FeedConnector
{
    public const KEY = 'meta';

    /** User-agent fragments of Meta's feed downloader (FacebookExternalHit), lowercase. */
    public const FETCH_AGENTS = ['facebookexternalhit'];

    public const CONDITIONS = ['new', 'refurbished', 'used'];

    public const COMMERCE_MANAGER_URL = 'https://business.facebook.com/commerce_manager';

    public const HELP_SCHEDULED_FEEDS_URL = 'https://developers.facebook.com/documentation/ads-commerce/catalog/guides/scheduled-feeds/';

    public const HELP_TROUBLESHOOT_URL = 'https://business.facebook.com/business/help/2041876302542944';

    /** Field rules the writer follows (consulted 2026-11-23, "Updated: Oct 5, 2026"). */
    public const FIELDS_DOC_URL = 'https://developers.facebook.com/documentation/ads-commerce/commerce-platform/catalog/fields';

    public function __construct(
        private readonly MetaFeedWriter $writer,
        private readonly FeedImage $images,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'connectors.meta_label';
    }

    public function description(): string
    {
        return 'connectors.meta_description';
    }

    public function icon(): string
    {
        return 'admin.connectors.icons.meta';
    }

    public function requirements(): array
    {
        return [ConnectorRequirement::shopCatalog()];
    }

    public function defaults(): array
    {
        return [
            'brand' => mb_substr($this->siteName(), 0, MetaFeedWriter::BRAND_MAX),
            'condition' => 'new',
            'availability' => CatalogItem::IN_STOCK,
            'google_product_category' => '',
        ];
    }

    public function rules(): array
    {
        return [
            'brand' => ['required', 'string', 'max:'.MetaFeedWriter::BRAND_MAX],
            'condition' => ['required', Rule::in(self::CONDITIONS)],
            'availability' => ['required', Rule::in([CatalogItem::IN_STOCK, CatalogItem::OUT_OF_STOCK])],
            'google_product_category' => ['nullable', 'string', 'max:250'],
        ];
    }

    public function source(): string
    {
        return ShopProductSource::KEY;
    }

    public function check(CatalogItem $item, array $settings): array
    {
        $warnings = [];
        if ($item->lacksVariants()) {
            $warnings[] = FeedWarning::for($item, FeedWarning::NO_VARIANTS);
        }
        if ($item->price === null) {
            $warnings[] = FeedWarning::for($item, FeedWarning::MISSING_PRICE);
        }

        $media = $item->imageMediaId === null ? null : Media::query()->find($item->imageMediaId);
        if (! $media instanceof Media || ! $media->isImage()) {
            $warnings[] = FeedWarning::for($item, FeedWarning::MISSING_IMAGE);
        } elseif ($this->images->jpegUrl($media) === null) {
            $warnings[] = FeedWarning::for($item, FeedWarning::IMAGE_NOT_CONVERTIBLE);
        } elseif ($this->images->isTooSmall($media)) {
            $warnings[] = FeedWarning::for($item, FeedWarning::IMAGE_TOO_SMALL);
        }

        if ($item->brand === null || trim($item->brand) === '') {
            $warnings[] = FeedWarning::for($item, FeedWarning::DEFAULT_BRAND);
        }
        if (MetaFeedWriter::titleIsTruncated($item)) {
            $warnings[] = FeedWarning::for($item, FeedWarning::TITLE_TRUNCATED, ['limit' => MetaFeedWriter::TITLE_MAX]);
        }
        if (trim($item->description) === '') {
            $warnings[] = FeedWarning::for($item, FeedWarning::DESCRIPTION_FROM_TITLE);
        }

        return $warnings;
    }

    public function write(iterable $items, array $settings, $stream): void
    {
        $settings = array_replace($this->defaults(), $settings);
        $name = $this->siteName();

        $this->writer->write($items, $settings, ['title' => $name, 'description' => $name], $stream);
    }

    public function contentType(): string
    {
        return 'application/xml; charset=UTF-8';
    }

    public function extension(): string
    {
        return 'xml';
    }

    public function fetchAgents(): array
    {
        return self::FETCH_AGENTS;
    }

    private function siteName(): string
    {
        $name = trim((string) Setting::get('site_name'));

        return $name !== '' ? $name : (string) config('app.name');
    }
}
