<?php

namespace Tests\Support;

use App\Connectors\CatalogItem;
use App\Connectors\ConnectorCategory;
use App\Connectors\ConnectorRequirement;
use App\Connectors\FeedWarning;
use App\Contracts\Connectors\FeedConnector;
use Illuminate\Validation\Rule;

/**
 * Feed connector for tests (TestCase::useFakeConnectors() registers it under `meta` by default). Requires the shop
 * catalog like the Meta connector, skips items without price or image, warns on a missing brand, and writes one
 * tab-separated line per item so tests can read what entered.
 */
final class FakeFeedConnector implements FeedConnector
{
    public string $key = 'meta';

    public string $sourceKey = 'products';

    /** False drops the shop catalog requirement. */
    public bool $requiresCatalog = true;

    public function key(): string
    {
        return $this->key;
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
        return 'admin.connectors.icons.generic';
    }

    public function category(): ConnectorCategory
    {
        return ConnectorCategory::SalesChannel;
    }

    public function requirements(): array
    {
        return $this->requiresCatalog ? [ConnectorRequirement::shopCatalog()] : [];
    }

    public function defaults(): array
    {
        return ['brand' => 'Default brand', 'condition' => 'new'];
    }

    public function rules(): array
    {
        return [
            'brand' => ['required', 'string', 'max:100'],
            'condition' => ['required', Rule::in(['new', 'refurbished', 'used'])],
        ];
    }

    public function source(): string
    {
        return $this->sourceKey;
    }

    public function check(CatalogItem $item, array $settings): array
    {
        $warnings = [];
        if ($item->price === null) {
            $warnings[] = FeedWarning::for($item, FeedWarning::MISSING_PRICE);
        }
        if ($item->imageMediaId === null) {
            $warnings[] = FeedWarning::for($item, FeedWarning::MISSING_IMAGE);
        }
        if ($item->brand === null) {
            $warnings[] = FeedWarning::for($item, FeedWarning::DEFAULT_BRAND);
        }

        return $warnings;
    }

    public function write(iterable $items, array $settings, $stream): void
    {
        foreach ($items as $item) {
            fwrite($stream, $item->id."\t".$item->title."\t".($item->brand ?? $settings['brand'])."\n");
        }
    }

    public function contentType(): string
    {
        return 'text/plain; charset=UTF-8';
    }

    public function extension(): string
    {
        return 'txt';
    }

    public function fetchAgents(): array
    {
        return ['fakebot'];
    }
}
