<?php

namespace App\Connectors\MetaPixel;

use App\Connectors\ConnectorAction;
use App\Connectors\ConnectorCategory;
use App\Connectors\ConnectorRegistry;
use App\Connectors\ConnectorRequirement;
use App\Contracts\Connectors\Connector;
use App\Models\Setting;

/**
 * Meta Pixel + Conversions API (`cms.connectors.meta_pixel`, docs/operations/meta-pixel.md): tells Meta what visitors
 * do on the site (PageView, ViewContent, AddToCart, InitiateCheckout, Purchase, Lead) from the browser (fbq) and,
 * when an access token is saved, from the server too, both with the same event_id so Meta counts each action once.
 * It publishes no feed and works with the shop off (PageView and Lead still measure a site without a shop).
 *
 * Settings (validated by rules(), editable from the panel and MCP): `pixel_id` (the dataset ID of Events Manager;
 * defaults to the ID saved in Admin > Analytics, which this connector replaces while active) and `test_event_code`
 * (Events Manager > Test events). The access token is a secret: App\Services\Marketing\MetaPixelOperations stores it
 * encrypted in Connector::$secrets, bound to the site origin, and it is never shown, returned by MCP or logged.
 * The runtime lives in App\Services\Marketing\MetaPixel.
 */
class MetaPixelConnector implements Connector
{
    public const KEY = 'meta_pixel';

    /** Requirement: a pixel (dataset) ID saved. */
    public const REQUIREMENT_PIXEL_ID = 'meta_pixel_id';

    /** Events the site sends, in the order a purchase goes through them. */
    public const EVENTS = ['PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout', 'Purchase', 'Lead'];

    /** Pixel (dataset) IDs are numeric, like the field of Admin > Analytics accepts. */
    public const PIXEL_ID_PATTERN = '/^\d{5,32}$/';

    /** Codes shown by Events Manager > Test events (TEST12345). */
    public const TEST_CODE_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** Official pages the panel links to (consulted 2026-11-24). */
    public const EVENTS_MANAGER_URL = 'https://business.facebook.com/events_manager2';

    public const HELP_ACCESS_TOKEN_URL = 'https://developers.facebook.com/documentation/ads-commerce/conversions-api/get-started';

    public const HELP_TEST_EVENTS_URL = 'https://developers.facebook.com/documentation/ads-commerce/conversions-api/using-the-api';

    public const HELP_DEDUPLICATION_URL = 'https://developers.facebook.com/documentation/ads-commerce/conversions-api/deduplicate-pixel-and-server-events';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'meta_pixel.label';
    }

    public function description(): string
    {
        return 'meta_pixel.description';
    }

    public function icon(): string
    {
        return 'admin.connectors.icons.meta';
    }

    public function category(): ConnectorCategory
    {
        return ConnectorCategory::Marketing;
    }

    /** Only the pixel ID: without the shop the site still measures page views and contact form leads. */
    public function requirements(): array
    {
        $met = self::validPixelId(app(ConnectorRegistry::class)->settings(self::KEY)['pixel_id'] ?? null);

        return [new ConnectorRequirement(
            self::REQUIREMENT_PIXEL_ID,
            $met,
            'meta_pixel.requirement_pixel_id',
            $met ? null : new ConnectorAction('admin.connectors.show', 'meta_pixel.action_configure', null, 'GET'),
        )];
    }

    public function defaults(): array
    {
        $legacy = trim((string) Setting::get('meta_pixel_id'));

        return [
            'pixel_id' => self::validPixelId($legacy) ? $legacy : '',
            'test_event_code' => '',
        ];
    }

    public function rules(): array
    {
        return [
            'pixel_id' => ['nullable', 'string', 'regex:'.self::PIXEL_ID_PATTERN],
            'test_event_code' => ['nullable', 'string', 'regex:'.self::TEST_CODE_PATTERN],
        ];
    }

    public static function validPixelId(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PIXEL_ID_PATTERN, $value) === 1;
    }
}
