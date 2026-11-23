<?php

namespace App\Http\Controllers\Admin;

use App\Connectors\Bancard\BancardConnector;
use App\Connectors\ConnectorRegistry;
use App\Connectors\ConnectorStatus;
use App\Connectors\FeedResult;
use App\Contracts\Connectors\Connector as ConnectorContract;
use App\Contracts\Connectors\FeedConnector;
use App\Exceptions\RevisionConflict;
use App\Http\Controllers\Controller;
use App\Models\Connector;
use App\Services\Connectors\ConnectorOperations;
use App\Support\AdminCapabilities;
use App\Support\ShopModule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin > Connectors (PLAN.md §4.1–4.4): the card grid and the detail of one connector. Thin over
 * ConnectorRegistry (read side) and ConnectorOperations (every write, shared with MCP). Routes live in
 * routes/connectors-admin.php under `role:admin`; the screen is operational, so Full and Essential render it alike.
 */
class ConnectorController extends Controller
{
    /** Items shown in the preview table and in "View XML" (PLAN.md §4.4). */
    public const PREVIEW_LIMIT = 20;

    /**
     * Official Meta help pages of the Commerce Manager guide (PLAN.md §4.6). A connector class may override any of
     * them with a public constant of the same name (MetaCatalogConnector keeps Meta's URLs, §4.4).
     */
    public const META_GUIDE_LINKS = [
        'COMMERCE_MANAGER_URL' => 'https://business.facebook.com/commerce_manager',
        'HELP_SCHEDULED_FEEDS_URL' => 'https://developers.facebook.com/documentation/ads-commerce/catalog/guides/scheduled-feeds/',
        'HELP_TROUBLESHOOT_URL' => 'https://business.facebook.com/business/help/2041876302542944',
    ];

    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly ConnectorOperations $operations,
    ) {}

    public function index(): View
    {
        $cards = [];
        foreach ($this->registry->all() as $key => $connector) {
            $cards[] = $this->card($key, $connector);
        }

        return view('admin.connectors.index', ['cards' => $cards]);
    }

    /**
     * The detail of a feed connector. Bancard publishes no feed: its keys, environment and switch live in Admin >
     * Payments and shipping, so its detail sends there (or back to the grid, whose card offers to turn the catalog on,
     * while that screen is not available).
     */
    public function show(string $connector): View|RedirectResponse
    {
        $definition = $this->registry->get($connector);
        if ($definition instanceof BancardConnector) {
            return app(ShopModule::class)->catalog()
                ? redirect()->route('admin.shop-payments.edit')->withFragment('bancard')
                : redirect()->route('admin.connectors.index');
        }
        $card = $this->card($connector, $definition, withPreview: true);
        $record = $card['record'];
        $feedUrl = $record?->feedUrl();

        return view('admin.connectors.show', $card + [
            'feedUrl' => $feedUrl,
            'tokenUnreadable' => $record?->token_hash !== null && $feedUrl === null,
            'fields' => $this->fields($definition),
            'settings' => $this->registry->settings($connector),
            'revision' => $this->operations->revision($record ?? new Connector(['key' => $connector, 'enabled' => false])),
            'currency' => (string) config('cms.shop.currency', 'PYG'),
            'xml' => $card['preview'] !== null && $definition instanceof FeedConnector ? $this->sampleDocument($definition, $card['preview'], $this->registry->settings($connector)) : null,
            'guideLinks' => $this->guideLinks($definition),
        ]);
    }

    public function activate(Request $request, string $connector): RedirectResponse
    {
        $this->operations->activate($request->user(), $connector);

        return redirect()->route('admin.connectors.show', ['connector' => $connector])->with('success', __('connectors.activated'));
    }

    public function deactivate(Request $request, string $connector): RedirectResponse
    {
        $this->operations->deactivate($request->user(), $connector);

        return redirect()->route('admin.connectors.show', ['connector' => $connector])->with('success', __('connectors.deactivated'));
    }

    public function updateSettings(Request $request, string $connector): RedirectResponse
    {
        $definition = $this->registry->get($connector);
        $settings = $request->only(array_keys($definition->defaults()));
        $revision = $request->filled('expected_revision') ? (string) $request->input('expected_revision') : null;

        try {
            $this->operations->updateSettings($request->user(), $connector, $settings, $revision);
        } catch (RevisionConflict) {
            return back()->withInput()->withErrors(['expected_revision' => __('connectors.revision_conflict')]);
        }

        return redirect()->route('admin.connectors.show', ['connector' => $connector])->with('success', __('connectors.settings_saved'));
    }

    public function rotateToken(Request $request, string $connector): RedirectResponse
    {
        $this->operations->rotateToken($request->user(), $connector);

        return redirect()->route('admin.connectors.show', ['connector' => $connector])->with('success', __('connectors.token_rotated'));
    }

    /**
     * "Activate product catalog" on a card: back to the card it was pressed on. The message follows what the
     * administrator has to do next: nothing (an active connector resumes), activate it, or load products first.
     */
    public function enableCatalog(Request $request, string $connector): RedirectResponse
    {
        $definition = $this->registry->get($connector);
        try {
            $this->operations->enableCatalog($request->user());
        } catch (ValidationException $exception) {
            return back()->withErrors(['connector' => $exception->validator->errors()->first()]);
        }

        $source = $definition instanceof FeedConnector ? $this->registry->source($definition->source()) : null;
        $message = match (true) {
            $this->registry->status($connector) === ConnectorStatus::Connected => __('connectors.catalog_resumed', ['connector' => __($definition->label())]),
            $source !== null && $source->count() > 0 => __('connectors.catalog_enabled_ready'),
            default => __('connectors.catalog_enabled'),
        };

        return back(fallback: route('admin.connectors.show', ['connector' => $connector]))->with('success', $message);
    }

    /**
     * What a card (and the top of the detail) shows. The live preview walks the whole source, so the grid only
     * runs it for an available connector (how many items would enter) or a connected one without stored totals.
     *
     * @return array<string, mixed>
     */
    private function card(string $key, ConnectorContract $connector, bool $withPreview = false): array
    {
        $status = $this->registry->status($key);
        $record = $this->registry->record($key);
        $unmet = $this->registry->unmet($key);
        $source = $connector instanceof FeedConnector ? $this->registry->source($connector->source()) : null;
        $sourceAvailable = $source?->available() ?? false;
        $preview = null;
        if ($sourceAvailable && ($withPreview || $status === ConnectorStatus::Available || ($status === ConnectorStatus::Connected && $record?->last_item_count === null))) {
            $preview = $this->operations->preview($key, $withPreview ? self::PREVIEW_LIMIT : 0);
        }
        $storedTotals = $record?->last_item_count !== null && ! $withPreview;

        return [
            'key' => $key,
            'connector' => $connector,
            'status' => $status,
            'record' => $record,
            'unmet' => $unmet,
            // An active connector whose requirement broke afterwards (the shop was turned off).
            'paused' => $status === ConnectorStatus::NeedsAction && (bool) $record?->enabled,
            'isMeta' => $key === 'meta',
            'sourceCount' => $sourceAvailable ? $source->count() : null,
            // Products, as the administrator counts them (the variants of one product are one).
            'groupCount' => $sourceAvailable ? $source->groupCount() : null,
            'preview' => $preview,
            'included' => $storedTotals ? $record->last_item_count : $preview?->included,
            'skipped' => $storedTotals ? (int) $record->last_skipped_count : $preview?->skipped,
            'newProductUrl' => $this->newProductUrl($source?->key()),
        ];
    }

    /** "New product" link for the empty catalog notice: only where the panel can create products (Full). */
    private function newProductUrl(?string $sourceKey): ?string
    {
        if ($sourceKey !== 'products' || ! Route::has('admin.products.create') || ! app(ShopModule::class)->catalog() || ! app(AdminCapabilities::class)->liveEditor()) {
            return null;
        }

        return route('admin.products.create');
    }

    /**
     * Settings form fields, one per default: a select when the rules restrict the value to a list (Rule::in or
     * `in:`), a text input otherwise. Labels and option texts come from lang/connectors.php when present.
     *
     * @return list<array{name: string, label: string, help: ?string, options: ?array<string, string>, required: bool}>
     */
    private function fields(ConnectorContract $connector): array
    {
        $rules = $connector->rules();
        $fields = [];
        foreach (array_keys($connector->defaults()) as $name) {
            $fieldRules = $rules[$name] ?? [];
            $fieldRules = is_string($fieldRules) ? explode('|', $fieldRules) : (array) $fieldRules;
            $values = null;
            foreach ($fieldRules as $rule) {
                $definition = $rule instanceof In ? (string) $rule : (is_string($rule) ? $rule : '');
                if (str_starts_with($definition, 'in:')) {
                    $values = str_getcsv(substr($definition, 3), ',', '"', '');
                }
            }
            $options = null;
            if ($values !== null) {
                $options = [];
                foreach ($values as $value) {
                    $optionKey = 'connectors.'.$name.'_'.str_replace([' ', '-'], '_', $value);
                    $options[$value] = __($optionKey) === $optionKey ? $value : __($optionKey);
                }
            }
            $labelKey = 'connectors.setting_'.$name;
            $helpKey = $labelKey.'_help';
            $fields[] = [
                'name' => $name,
                'label' => __($labelKey) === $labelKey ? ucfirst(str_replace('_', ' ', $name)) : __($labelKey),
                'help' => __($helpKey) === $helpKey ? null : __($helpKey),
                'options' => $options,
                'required' => in_array('required', $fieldRules, true),
            ];
        }

        return $fields;
    }

    /**
     * The preview sample written by the connector itself, as the platform would receive it ("View XML").
     *
     * @param  array<string, mixed>  $settings
     */
    private function sampleDocument(FeedConnector $connector, FeedResult $preview, array $settings): ?string
    {
        if ($preview->sample === []) {
            return null;
        }
        $stream = fopen('php://temp', 'w+b');
        try {
            $connector->write($preview->sample, $settings, $stream);
            rewind($stream);

            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    /** @return array<string, string> */
    private function guideLinks(ConnectorContract $connector): array
    {
        $links = [];
        foreach (self::META_GUIDE_LINKS as $name => $default) {
            $constant = $connector::class.'::'.$name;
            $links[$name] = defined($constant) && is_string(constant($constant)) ? constant($constant) : $default;
        }

        return $links;
    }
}
