<?php

namespace App\Services\AmibaChat;

use App\Connectors\AmibaChat\AmibaChatConnector;
use App\Connectors\ConnectorRegistry;
use App\Models\Connector;
use App\Support\SiteUrl;
use Illuminate\Http\Request;

/**
 * Runtime of the Amiba chatbot connector (App\Connectors\AmibaChat\AmibaChatConnector): whether a page loads Amiba's
 * floating chat and with which handle. resources/views/partials/amiba-chat.blade.php renders
 * `<script src="https://widget.amiba.io/loader.js" data-amiba-widget="HANDLE" async>`, the snippet Amiba hands out.
 *
 * The chat loads on public pages while the connector is on with a valid handle. Unlike the Meta Pixel it also loads
 * for someone signed in to the panel (a chat measures nothing, and the owner wants to see it working), but never on
 * a panel screen (/admin), inside the panel's previews, nor on the checkout when `hide_on_checkout` is set.
 */
final class AmibaChat
{
    /** Routes of the checkout (order details and payment), named `shop.*` in the base group and `localized.shop.*`. */
    public const CHECKOUT_ROUTES = ['shop.checkout.*', 'localized.shop.checkout.*', 'shop.order.*', 'localized.shop.order.*'];

    public function __construct(private readonly ConnectorRegistry $registry) {}

    /** The saved handle while the connector is on; null otherwise. */
    public function handle(): ?string
    {
        if (! $this->registry->has(AmibaChatConnector::KEY) || ! $this->record()?->enabled) {
            return null;
        }
        $handle = $this->registry->settings(AmibaChatConnector::KEY)['handle'] ?? null;

        return AmibaChatConnector::validHandle($handle) ? $handle : null;
    }

    /** The handle to render on this request, or null when the chat must not appear here. */
    public function handleFor(?Request $request = null, bool $editorPreview = false): ?string
    {
        $request ??= request();
        if ($editorPreview || $request->is('admin') || $request->is('admin/*')) {
            return null;
        }
        $handle = $this->handle();
        if ($handle === null) {
            return null;
        }
        if ($this->hideOnCheckout() && $request->route() !== null && $request->routeIs(...self::CHECKOUT_ROUTES)) {
            return null;
        }

        return $handle;
    }

    public function hideOnCheckout(): bool
    {
        return $this->registry->has(AmibaChatConnector::KEY)
            && filter_var($this->registry->settings(AmibaChatConnector::KEY)['hide_on_checkout'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /** The loader Amiba serves (`cms.amiba_chat.widget_url`/loader.js). */
    public static function loaderUrl(): string
    {
        return rtrim((string) config('cms.amiba_chat.widget_url'), '/').'/loader.js';
    }

    /**
     * The exact origin the client must add to the widget's «Orígenes permitidos» in Amiba: scheme://host[:port] in
     * lowercase, as Amiba compares them (andromeda-api WebChatOriginHelper: exact match, no path).
     */
    public static function siteOrigin(): string
    {
        return self::normalizeOrigin(SiteUrl::origin()) ?? SiteUrl::origin();
    }

    /** scheme://host[:port] in lowercase, or null when it is not an http(s) URL. */
    public static function normalizeOrigin(mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }
        $parts = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }
        $port = $parts['port'] ?? null;
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme.'://'.$host.($port !== null && (int) $port !== $default ? ':'.$port : '');
    }

    private function record(): ?Connector
    {
        return $this->registry->record(AmibaChatConnector::KEY);
    }
}
