{{--
    One connector card (Admin > Connectors grid and top of the detail). Receives the array built by
    Admin\ConnectorController::card(): $key, $connector, $status, $record, $unmet, $paused, $isMeta, $sourceCount,
    $groupCount, $included, $skipped, $newProductUrl, $detailUrl and $detailLabel (where the name and the footer link
    lead: the detail, or for Bancard every payment method; null shows no link). $detail = true (top of the detail page, whose h1 already
    names the connector) hides the name and the "View details" link.

    Layout: header (icon tile, name, status chip always top right), description, status lines, then ONE primary
    action pinned to the bottom of the body (the first one the state offers; any other is a secondary button) and a
    footer with the detail link as a quiet text link. The card fills its grid cell (h-full), so the cards of a row keep
    the same height and their buttons and footers line up.
--}}
@php
    $detail = $detail ?? false;
    $chip = $paused
        ? 'bg-white/[0.05] text-gray-300 ring-white/10'
        : match ($status) {
            \App\Connectors\ConnectorStatus::Connected => 'bg-emerald-500/10 text-emerald-300 ring-emerald-500/25',
            \App\Connectors\ConnectorStatus::Available => 'bg-sky-500/10 text-sky-300 ring-sky-500/25',
            \App\Connectors\ConnectorStatus::NeedsReconnect => 'bg-red-500/10 text-red-300 ring-red-500/25',
            default => 'bg-amber-500/10 text-amber-300 ring-amber-500/25',
        };
    $dot = $paused
        ? 'bg-gray-400'
        : match ($status) {
            \App\Connectors\ConnectorStatus::Connected => 'bg-emerald-400',
            \App\Connectors\ConnectorStatus::Available => 'bg-sky-400',
            \App\Connectors\ConnectorStatus::NeedsReconnect => 'bg-red-400',
            default => 'bg-amber-400',
        };
    // Icon tile tint per service: same size and shape for every connector, only the hue changes.
    $tile = match ($connector->icon()) {
        'admin.connectors.icons.meta' => 'bg-[#0866ff]/15 text-[#6ea6ff] ring-[#0866ff]/30',
        'admin.connectors.icons.bancard' => 'bg-emerald-500/10 text-emerald-300 ring-emerald-500/25',
        'admin.connectors.icons.amiba' => 'bg-violet-500/10 text-violet-300 ring-violet-500/25',
        default => 'bg-white/[0.05] text-gray-300 ring-white/10',
    };
    $primaryClass = 'inline-flex min-h-10 items-center justify-center gap-2 rounded-md bg-sky-600 px-3.5 py-2 text-sm font-medium text-white transition-colors hover:bg-sky-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-400';
    $secondaryClass = 'inline-flex min-h-10 items-center justify-center gap-2 rounded-md border border-white/10 bg-white/5 px-3.5 py-2 text-sm font-medium text-gray-200 transition-colors hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-400';
    $shopPath = app(\App\Support\ShopModule::class)->path();
    // Under a minute, "a moment ago" instead of "0 seconds ago".
    $ago = fn (\Carbon\CarbonInterface $at): string => $at->diffInSeconds(now(), true) < 60 ? __('connectors.fetched_just_now') : $at->diffForHumans();

    // Meta v2 (plan-meta-v2): connected with Facebook, or Meta revoked the access (direct «Volver a conectar»). Manual mode shows nothing new.
    $metaConnection = $connector instanceof \App\Connectors\Meta\MetaCatalogConnector
        ? app(\App\Connectors\Meta\Connect\MetaConnectionStore::class)->get()
        : null;

    // Actions in the order the state offers them: the first one is the primary button, any other is secondary.
    $actions = [];
    if ($status === \App\Connectors\ConnectorStatus::NeedsAction) {
        foreach ($unmet as $requirement) {
            if ($requirement->action) {
                $actions[] = ['requirement' => $requirement];
            }
        }
    } elseif ($status === \App\Connectors\ConnectorStatus::Available) {
        $actions[] = ['activate' => true];
    }
    if ($metaConnection?->needsReconnect() && ! $detail && \App\Http\Controllers\Admin\MetaConnectController::available()) {
        $actions[] = ['reconnect' => true];
    }
    $showDetailLink = ! $detail && $detailUrl;
@endphp
<article class="flex h-full flex-col rounded-xl bg-[#141414] ring-1 ring-white/[0.06] transition-shadow hover:ring-white/[0.12] focus-within:ring-white/[0.14]" data-connector="{{ $key }}" data-status="{{ $status->value }}">
    <div class="flex flex-1 flex-col p-5">
        <header class="flex {{ $detail ? 'items-center' : 'items-start' }} gap-3.5">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg ring-1 ring-inset {{ $tile }}">
                @include($connector->icon(), ['class' => 'w-6 h-6'])
            </span>
            <div class="min-w-0 flex-1 {{ $detail ? '' : 'pt-0.5' }}">
                @unless($detail)
                    <h3 class="text-[15px] font-semibold leading-snug text-white">
                        @if($detailUrl)
                            <a href="{{ $detailUrl }}" class="rounded-sm transition-colors hover:text-sky-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-400">{{ __($connector->label()) }}</a>
                        @else
                            {{ __($connector->label()) }}
                        @endif
                    </h3>
                @else
                    <p class="text-sm leading-relaxed text-gray-400">{{ __($connector->description()) }}</p>
                @endunless
            </div>
            <span class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $chip }}" data-status-chip>
                <span class="h-1.5 w-1.5 rounded-full {{ $dot }}" aria-hidden="true"></span>{{ $paused ? __('connectors.status_paused') : __($status->label()) }}
            </span>
        </header>

        @unless($detail)
            <p class="mt-3 text-sm leading-relaxed text-gray-400">{{ __($connector->description()) }}</p>
        @endunless

        <div class="mt-4 space-y-2 text-sm leading-relaxed text-gray-200">
            @if($status === \App\Connectors\ConnectorStatus::NeedsAction)
                @if($paused && $isMeta)
                    <p>{{ __('connectors.meta_paused_shop_off') }}</p>
                @endif
                @foreach($unmet as $requirement)
                    @if(! ($paused && $isMeta && $requirement->key === \App\Connectors\ConnectorRequirement::SHOP_CATALOG && $requirement->action !== null))
                        <p class="flex gap-2.5">
                            <span class="mt-[0.55rem] h-1.5 w-1.5 shrink-0 rounded-full bg-amber-400/80" aria-hidden="true"></span>
                            <span>{{ $isMeta && $requirement->key === \App\Connectors\ConnectorRequirement::SHOP_CATALOG && $requirement->action !== null ? __('connectors.meta_needs_catalog') : __($requirement->message) }}</span>
                        </p>
                    @endif
                @endforeach
            @elseif($status === \App\Connectors\ConnectorStatus::Available)
                @if($sourceCount !== null)
                    <p>
                        {{ trans_choice('connectors.published_count', $groupCount, ['count' => $groupCount]) }}
                        @if($sourceCount !== $groupCount) ({{ trans_choice('connectors.feed_items_count', $sourceCount, ['count' => $sourceCount]) }}) @endif
                        @if($included !== null) · {{ trans_choice('connectors.would_include', $included, ['count' => $included]) }} @endif
                    </p>
                    @if($sourceCount === 0)
                        <p class="text-gray-400">
                            {{ __('connectors.no_products') }}
                            @if($newProductUrl)
                                <a href="{{ $newProductUrl }}" class="text-sky-400 underline underline-offset-2 hover:text-sky-300">{{ __('connectors.action_new_product') }}</a>
                            @endif
                        </p>
                    @endif
                @endif
            @else
                @if($included !== null)
                    <p>{{ trans_choice('connectors.included_count', $included, ['count' => $included]) }} · {{ trans_choice('connectors.skipped_count', (int) $skipped, ['count' => (int) $skipped]) }}</p>
                @endif
            @endif

            @if($record?->enabled && $key === \App\Connectors\MetaPixel\MetaPixelConnector::KEY && $status === \App\Connectors\ConnectorStatus::Connected)
                @php($capi = app(\App\Services\Marketing\MetaPixel::class)->accessToken() !== null)
                <p data-meta-pixel-status="{{ $capi ? 'browser_and_server' : 'browser' }}">{{ $capi ? __('meta_pixel.card_browser_and_server') : __('meta_pixel.card_browser_only') }}</p>
            @endif

            @if($record?->enabled && $key === \App\Connectors\AmibaChat\AmibaChatConnector::KEY && $status === \App\Connectors\ConnectorStatus::Connected)
                @php($amibaHidden = app(\App\Services\AmibaChat\AmibaChat::class)->hideOnCheckout())
                <p data-amiba-chat-status="{{ $amibaHidden ? 'not_on_checkout' : 'every_page' }}">{{ $amibaHidden ? __('amiba_chat.card_live_not_checkout') : __('amiba_chat.card_live') }}</p>
            @endif

            @if($metaConnection?->needsReconnect())
                <p class="text-red-300" data-meta-card="needs_reconnect">{{ __('connectors.meta_card_reconnect') }}</p>
            @elseif($metaConnection?->isConnected())
                <p class="text-emerald-300" data-meta-card="connected">{{ $metaConnection->catalogId === null ? __('connectors.meta_card_connected_no_catalog') : __('connectors.meta_card_connected', ['catalog' => $metaConnection->catalogName ?? $metaConnection->catalogId]) }}</p>
            @endif

            {{-- Fetch log: only connectors that publish a feed (Bancard, the Meta Pixel and the Amiba chatbot have none). --}}
            @if($record?->enabled && $connector instanceof \App\Contracts\Connectors\FeedConnector)
                <div class="space-y-1 pt-1 text-xs leading-relaxed text-gray-400" data-fetch-log>
                    @if($isMeta)
                        @if($record->last_meta_fetched_at)
                            <p>{{ __('connectors.last_meta_fetch', ['ago' => $ago($record->last_meta_fetched_at), 'date' => $record->last_meta_fetched_at->format('d/m/Y H:i')]) }}</p>
                        @else
                            <p class="text-amber-300">{{ __('connectors.meta_never_fetched') }}</p>
                        @endif
                    @endif
                    @if($detail || ! $isMeta)
                        @if($record->last_fetched_at)
                            <p class="break-words">{{ __('connectors.last_fetch', ['date' => $record->last_fetched_at->format('d/m/Y H:i'), 'agent' => $record->last_fetch_agent ?? '—']) }}</p>
                        @else
                            <p>{{ __('connectors.never_fetched') }}</p>
                        @endif
                    @endif
                </div>
            @endif
        </div>

        @if($actions !== [])
            <div class="mt-auto flex flex-wrap items-center gap-3 pt-5">
                @foreach($actions as $i => $action)
                    @php($buttonClass = $i === 0 ? $primaryClass : $secondaryClass)
                    @if(isset($action['requirement']))
                        @php($requirement = $action['requirement'])
                        @if(strtoupper($requirement->action->method) === 'GET')
                            {{-- A screen to go to (Bancard: Payments and shipping, Settings > Shop): a link, not a form. --}}
                            <a href="{{ $requirement->action->url($key) }}" class="{{ $buttonClass }}" data-connector-action="{{ $requirement->key }}">
                                {{ __($requirement->action->label) }}
                            </a>
                        @else
                            <form method="POST" action="{{ $requirement->action->url($key) }}"
                                  @if($requirement->action->confirm)
                                      data-confirm="{{ __($requirement->action->confirm, ['path' => $shopPath]) }}"
                                      data-confirm-title="{{ __($requirement->action->label) }}"
                                      data-confirm-label="{{ __($requirement->action->label) }}"
                                      data-confirm-tone="primary"
                                  @endif>
                                @csrf
                                @if(strtoupper($requirement->action->method) !== 'POST') @method($requirement->action->method) @endif
                                <button type="submit" class="{{ $buttonClass }}">
                                    {{ __($requirement->action->label) }}
                                </button>
                            </form>
                        @endif
                    @elseif(isset($action['activate']))
                        <form method="POST" action="{{ route('admin.connectors.activate', ['connector' => $key]) }}">
                            @csrf
                            <button type="submit" class="{{ $buttonClass }}">
                                {{ $isMeta ? __('connectors.meta_action_activate') : __('connectors.action_activate') }}
                            </button>
                        </form>
                    @else
                        <a href="{{ route('admin.connectors.meta.connect') }}" class="{{ $buttonClass }}" data-meta-connect="reconnect">{{ __('connectors.meta_action_reconnect') }}</a>
                    @endif
                @endforeach
            </div>
        @endif
    </div>

    @if($showDetailLink)
        <footer class="border-t border-white/[0.06] px-5 py-2">
            <a href="{{ $detailUrl }}" class="inline-flex items-center gap-1 min-h-10 rounded-sm text-sm font-medium text-gray-300 transition-colors hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-400" data-connector-detail>
                {{ __($detailLabel) }}
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </a>
        </footer>
    @endif
</article>
