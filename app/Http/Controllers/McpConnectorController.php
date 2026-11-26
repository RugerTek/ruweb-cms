<?php

namespace App\Http\Controllers;

use App\Connectors\AmibaChat\AmibaChatConnector;
use App\Connectors\CatalogItem;
use App\Connectors\ConnectorRegistry;
use App\Connectors\ConnectorRequirement;
use App\Connectors\FeedWarning;
use App\Connectors\Meta\Connect\MetaConnectionStore;
use App\Connectors\Meta\MetaCatalogConnector;
use App\Connectors\MetaPixel\MetaPixelConnector;
use App\Contracts\Connectors\FeedConnector;
use App\Exceptions\RevisionConflict;
use App\Models\Connector;
use App\Models\User;
use App\Services\AmibaChat\AmibaChat;
use App\Services\Connectors\ConnectorOperations;
use App\Services\Marketing\MetaPixel;
use App\Support\ShopModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * MCP tools and REST mirror of Admin > Connectors (`connectors_*`, docs/operations/mcp.md#connectors). Always
 * registered (the section exists on every site), administrator only. Writes go through ConnectorOperations, the
 * operation the panel uses. The feed token and the feed URL never leave this controller: the URL is shown only in
 * the panel (Connector::feedUrl()), like transport credentials (docs/operations/mcp-surface.md). The Meta Pixel's
 * pixel ID and test events code are ordinary settings here; its Conversions API access token is set only in the panel
 * and reported as `conversions_api.token_configured`. The Meta catalog reports its «Conectar con Facebook» state as
 * `meta_connection` (mode, business, catalog, «termina en»), never the Graph token. The Amiba chatbot's widget handle is
 * public (it is in the HTML of every page), so it is returned in `amiba_chat`; a pasted snippet is accepted as `handle`.
 */
class McpConnectorController extends Controller
{
    private const REVISION = ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/'];

    /** Bounds of connectors_preview: sample size and listed warnings per kind (totals are always exact). */
    public const PREVIEW_DEFAULT = 20;

    public const PREVIEW_MAX = 100;

    public const WARNINGS_MAX = 200;

    public function __construct(private readonly ConnectorRegistry $registry, private readonly ConnectorOperations $operations) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request->user());

        return response()->json(['data' => array_values(array_map(fn (string $key) => $this->serialize($key, summary: true), array_keys($this->registry->all())))]);
    }

    public function show(Request $request, string $connector): JsonResponse
    {
        $this->authorizeAdmin($request->user());

        return response()->json(['data' => $this->serialize($connector)]);
    }

    /** Totals, the first `limit` included items and why the others are left out. Writes nothing. */
    public function preview(Request $request, string $connector): JsonResponse
    {
        $this->authorizeAdmin($request->user());
        $validated = Validator::make($request->all(), ['limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::PREVIEW_MAX]])->validate();
        $feed = $this->registry->feed($connector);
        $available = $this->registry->source($feed->source())->available();
        $result = $this->operations->preview($connector, (int) ($validated['limit'] ?? self::PREVIEW_DEFAULT));
        $currency = config('cms.shop.currency', 'PYG');
        $skipped = $result->skippedWarnings();
        $notices = $result->notices();

        return response()->json(['data' => [
            'key' => $connector,
            'source' => $feed->source(),
            'source_available' => $available,
            'unmet_requirements' => $this->requirements($connector, unmetOnly: true),
            'included' => $result->included,
            'skipped' => $result->skipped,
            'currency' => $currency,
            'sample' => array_map(fn (CatalogItem $item) => $this->serializeItem($item), $result->sample),
            'skipped_reasons' => array_map(fn (FeedWarning $warning) => $this->serializeWarning($warning), array_slice($skipped, 0, self::WARNINGS_MAX)),
            'skipped_reasons_total' => count($skipped),
            'notices' => array_map(fn (FeedWarning $warning) => $this->serializeWarning($warning), array_slice($notices, 0, self::WARNINGS_MAX)),
            'notices_total' => count($notices),
        ]]);
    }

    /**
     * Patch `settings` (keys replace the stored ones, validated with the connector rules) and/or switch `enabled`,
     * in one transaction guarded by `expected_revision`. A failed activation (unmet requirement) keeps nothing.
     */
    public function update(Request $request, string $connector): JsonResponse
    {
        $user = $request->user();
        $this->authorizeAdmin($user);
        $definition = $this->registry->get($connector);
        $validated = Validator::make($request->all(), [
            'expected_revision' => self::REVISION,
            'enabled' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
        ])->after(function (ValidatorInstance $validator) use ($request) {
            if (! $request->has('enabled') && ! (is_array($request->input('settings')) && $request->input('settings') !== [])) {
                $validator->errors()->add('enabled', 'Send enabled, settings or both.');
            }
        })->validate();
        $settings = $validated['settings'] ?? [];
        $ignored = array_values(array_diff(array_map('strval', array_keys($settings)), array_keys($definition->rules())));

        DB::transaction(function () use ($user, $connector, $validated, $settings) {
            $this->checkRevision($connector, $validated['expected_revision']);
            if ($settings !== []) {
                $this->operations->updateSettings($user, $connector, $settings, $validated['expected_revision']);
            }
            if (array_key_exists('enabled', $validated)) {
                filter_var($validated['enabled'], FILTER_VALIDATE_BOOLEAN)
                    ? $this->operations->activate($user, $connector)
                    : $this->operations->deactivate($user, $connector);
            }
        });

        $warnings = array_map(fn (string $key) => ['code' => 'unknown_setting', 'field' => 'settings.'.$key, 'message' => 'Not a setting of this connector; it was ignored.'], $ignored);

        return response()->json(['data' => $this->serialize($connector)] + ($warnings === [] ? [] : ['warnings' => $warnings]));
    }

    /**
     * New feed token: the previous URL stops working at once. Answers only when it happened; the new URL is shown
     * in the panel, never here.
     */
    public function rotateToken(Request $request, string $connector): JsonResponse
    {
        $user = $request->user();
        $this->authorizeAdmin($user);
        // Only feed connectors have a feed token (Bancard and the Meta Pixel answer 404).
        $this->registry->feed($connector);
        $validated = Validator::make($request->all(), ['expected_revision' => self::REVISION])->validate();

        DB::transaction(function () use ($user, $connector, $validated) {
            $this->checkRevision($connector, $validated['expected_revision']);
            $this->operations->rotateToken($user, $connector);
        });
        $record = $this->registry->record($connector);

        return response()->json(['data' => [
            'key' => $connector,
            'rotated_at' => $record?->token_rotated_at?->toISOString(),
            'revision' => $this->operations->revision($record ?? $this->blank($connector)),
            'feed_url_location' => 'Only in the admin panel: '.$this->panelUrl($connector).' . The previous URL no longer works; paste the new one where the old one was used.',
        ]]);
    }

    private function authorizeAdmin(?User $user): void
    {
        Gate::forUser($user)->authorize(ConnectorOperations::ABILITY);
    }

    /** Locks the row (when it exists) and compares the revision the client read; stale → 409, nothing written. */
    private function checkRevision(string $connector, string $expected): void
    {
        $row = Connector::query()->where('key', $connector)->lockForUpdate()->first() ?? $this->blank($connector);
        $current = $this->operations->revision($row);
        if (! hash_equals($current, $expected)) {
            throw new RevisionConflict($expected, $current);
        }
    }

    /** The unsaved row of a connector never activated: its revision is what connectors_get reports. */
    private function blank(string $connector): Connector
    {
        return new Connector(['key' => $connector, 'enabled' => false]);
    }

    private function panelUrl(string $connector): string
    {
        return route('admin.connectors.show', ['connector' => $connector]);
    }

    /** Never includes token, token_hash, the feed URL or a connector secret (the Meta Pixel access token). */
    private function serialize(string $key, bool $summary = false): array
    {
        $definition = $this->registry->get($key);
        $record = $this->registry->record($key);
        $status = $this->registry->status($key);
        $data = [
            'key' => $key,
            'label' => __($definition->label()),
            'description' => __($definition->description()),
            'status' => $status->value,
            'status_label' => __($status->label()),
            'enabled' => (bool) $record?->enabled,
            'revision' => $this->operations->revision($record ?? $this->blank($key)),
            'is_feed' => $definition instanceof FeedConnector,
            'requirements' => $this->requirements($key, unmetOnly: $summary),
            'last_item_count' => $record?->last_item_count,
            'last_skipped_count' => $record?->last_skipped_count,
            'last_meta_fetched_at' => $record?->last_meta_fetched_at?->toISOString(),
        ];
        if ($definition instanceof MetaPixelConnector) {
            // Whether the Conversions API token is saved and usable here; the token itself only goes into the panel.
            $data['conversions_api'] = [
                'token_configured' => app(MetaPixel::class)->accessToken() !== null,
                'token_needs_reentry' => (bool) $record?->secretsUnusable(),
                'token_location' => 'Secret: set only in the admin panel ('.$this->panelUrl($key).'), never through MCP.',
                'graph_api_version' => (string) config('cms.meta_pixel.graph_api_version'),
                'events' => MetaPixelConnector::EVENTS,
            ];
        }
        if ($definition instanceof MetaCatalogConnector) {
            $data['meta_connection'] = $this->metaConnection();
        }
        if ($definition instanceof AmibaChatConnector) {
            $data['amiba_chat'] = $this->amibaChat($key);
        }
        if ($summary) {
            return $data;
        }

        return $data + [
            'source' => $definition instanceof FeedConnector ? $definition->source() : null,
            'settings' => $this->registry->settings($key),
            'defaults' => $definition->defaults(),
            'setting_keys' => array_keys($definition->rules()),
            'last_fetched_at' => $record?->last_fetched_at?->toISOString(),
            'last_fetch_agent' => $record?->last_fetch_agent,
            'token_rotated_at' => $record?->token_rotated_at?->toISOString(),
            'feed_url_location' => $definition instanceof FeedConnector ? 'Secret: shown only in the admin panel ('.$this->panelUrl($key).'), never through MCP.' : null,
            'activated_at' => $record?->created_at?->toISOString(),
            'updated_at' => $record?->updated_at?->toISOString(),
        ];
    }

    /**
     * Meta v2 «Conectar con Facebook» (plan-meta-v2): how the catalog reaches Meta. Never the access token (only its
     * last 4 characters, like the panel's «termina en»); connecting and disconnecting happen only in the panel, because
     * they need the administrator's Facebook login.
     */
    private function metaConnection(): array
    {
        $connection = app(MetaConnectionStore::class)->get();

        return [
            'mode' => $connection->mode,
            'connected' => $connection->isConnected() && ! $connection->needsReconnect(),
            'needs_reconnect' => $connection->needsReconnect(),
            'business_id' => $connection->businessId,
            'catalog' => $connection->catalogId === null ? null : ['id' => $connection->catalogId, 'name' => $connection->catalogName],
            'product_feed_id' => $connection->productFeedId,
            'connected_at' => $connection->connectedAt?->format(DATE_ATOM),
            'token_ends_in' => $connection->tokenSuffix,
            'last_error' => $connection->lastError,
            'connect_location' => 'Only in the admin panel ('.$this->panelUrl(MetaCatalogConnector::KEY).'#meta-connection): «Conectar con Facebook» needs the administrator\'s Facebook login. The token is never available here.',
        ];
    }

    /**
     * Amiba chatbot (level 1): the widget handle, whether the chat is on the public pages now, and the exact origin to
     * allow in Amiba. The "Test" against Amiba's API runs only in the panel.
     */
    private function amibaChat(string $key): array
    {
        $chat = app(AmibaChat::class);
        $settings = $this->registry->settings($key);

        return [
            'handle' => AmibaChatConnector::validHandle($settings['handle'] ?? null) ? $settings['handle'] : null,
            'on_public_pages' => $chat->handle() !== null,
            'hide_on_checkout' => $chat->hideOnCheckout(),
            'loader_url' => AmibaChat::loaderUrl(),
            'site_origin' => AmibaChat::siteOrigin(),
            'allowed_origin_hint' => 'In Amiba, the widget\'s Web Chat node > Widget settings > Allowed origins must list site_origin exactly (or be empty, which allows any site).',
            'test_location' => 'Only in the admin panel ('.$this->panelUrl($key).'): the Test button asks Amiba for the widget.',
        ];
    }

    private function requirements(string $key, bool $unmetOnly = false): array
    {
        $requirements = $unmetOnly ? $this->registry->unmet($key) : $this->registry->get($key)->requirements();

        return array_values(array_map(fn (ConnectorRequirement $requirement) => [
            'key' => $requirement->key,
            'met' => $requirement->met,
            'message' => __($requirement->message, ['path' => app(ShopModule::class)->path()]),
            // An administrator can resolve the shop requirement through MCP: settings_update on shop_mode.
            'resolution' => ! $requirement->met && $requirement->action !== null && $requirement->key === ConnectorRequirement::SHOP_CATALOG
                ? 'settings_update {"key": "shop_mode", "value": "catalog"}'
                : null,
        ], $requirements));
    }

    /** Neutral item as the connector checks it; `null` brand/availability mean the connector default. */
    private function serializeItem(CatalogItem $item): array
    {
        return [
            'id' => $item->id,
            'group_id' => $item->groupId,
            'title' => $item->title,
            'description' => mb_strimwidth($item->description, 0, 500, '…'),
            'url' => $item->url,
            'image_media_id' => $item->imageMediaId,
            'additional_image_media_ids' => $item->additionalImageMediaIds,
            'price_amount' => $item->price?->amount,
            'sale_price_amount' => $item->salePrice?->amount,
            'availability' => $item->availability,
            'brand' => $item->brand,
            'variant_attributes' => $item->variantAttributes,
            'edit_url' => $item->editUrl,
        ];
    }

    private function serializeWarning(FeedWarning $warning): array
    {
        return [
            'item_id' => $warning->itemId,
            'title' => $warning->title,
            'code' => $warning->code,
            'skips' => $warning->skips,
            'message' => __($warning->message(), $warning->context),
            'edit_url' => $warning->editUrl,
        ];
    }
}
