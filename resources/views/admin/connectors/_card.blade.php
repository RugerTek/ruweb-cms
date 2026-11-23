{{--
    One connector card (Admin > Connectors grid and top of the detail). Receives the array built by
    Admin\ConnectorController::card(): $key, $connector, $status, $record, $unmet, $paused, $isMeta, $sourceCount,
    $groupCount, $included, $skipped, $newProductUrl. $detail = true (top of the detail page, whose h1 already
    names the connector) hides the name and the "View details" link.
--}}
@php
    $detail = $detail ?? false;
    $chip = match ($status) {
        \App\Connectors\ConnectorStatus::Connected => 'bg-emerald-500/10 text-emerald-400 ring-emerald-500/20',
        \App\Connectors\ConnectorStatus::Available => 'bg-sky-500/10 text-sky-400 ring-sky-500/20',
        default => 'bg-yellow-500/10 text-yellow-400 ring-yellow-500/20',
    };
    $shopPath = app(\App\Support\ShopModule::class)->path();
    // Under a minute, "a moment ago" instead of "0 seconds ago".
    $ago = fn (\Carbon\CarbonInterface $at): string => $at->diffInSeconds(now(), true) < 60 ? __('connectors.fetched_just_now') : $at->diffForHumans();
@endphp
<article class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5 flex flex-col gap-4" data-connector="{{ $key }}" data-status="{{ $status->value }}">
    <header class="flex items-start gap-3">
        <span class="shrink-0 w-11 h-11 rounded-lg bg-white/[0.04] text-sky-400 flex items-center justify-center">
            @include($connector->icon(), ['class' => 'w-6 h-6'])
        </span>
        <div class="flex-1 min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                @unless($detail)
                    <h2 class="text-sm font-semibold text-white">
                        <a href="{{ route('admin.connectors.show', ['connector' => $key]) }}" class="hover:text-sky-400 transition-colors">{{ __($connector->label()) }}</a>
                    </h2>
                @endunless
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium ring-1 {{ $chip }}" data-status-chip>{{ $paused ? __('connectors.status_paused') : __($status->label()) }}</span>
            </div>
            <p class="text-sm text-gray-400 mt-1">{{ __($connector->description()) }}</p>
        </div>
    </header>

    <div class="text-sm text-gray-300 space-y-3">
        @if($status === \App\Connectors\ConnectorStatus::NeedsAction)
            @if($paused && $isMeta)
                <p>{{ __('connectors.meta_paused_shop_off') }}</p>
            @endif
            @foreach($unmet as $requirement)
                @if(! ($paused && $isMeta && $requirement->key === \App\Connectors\ConnectorRequirement::SHOP_CATALOG && $requirement->action !== null))
                    <p>{{ $isMeta && $requirement->key === \App\Connectors\ConnectorRequirement::SHOP_CATALOG && $requirement->action !== null ? __('connectors.meta_needs_catalog') : __($requirement->message) }}</p>
                @endif
                @if($requirement->action && strtoupper($requirement->action->method) === 'GET')
                    {{-- A screen to go to (Bancard: Payments and shipping, Settings > Shop): a link, not a form. --}}
                    <a href="{{ $requirement->action->url($key) }}" class="inline-flex items-center gap-2 px-3 py-2 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors" data-connector-action="{{ $requirement->key }}">
                        {{ __($requirement->action->label) }}
                    </a>
                @elseif($requirement->action)
                    <form method="POST" action="{{ $requirement->action->url($key) }}"
                          @if($requirement->action->confirm)
                              data-confirm="{{ __($requirement->action->confirm, ['path' => $shopPath]) }}"
                              data-confirm-title="{{ __($requirement->action->label) }}"
                              data-confirm-label="{{ __($requirement->action->label) }}"
                              data-confirm-tone="primary"
                          @endif>
                        @csrf
                        @if(strtoupper($requirement->action->method) !== 'POST') @method($requirement->action->method) @endif
                        <button type="submit" class="inline-flex items-center gap-2 px-3 py-2 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
                            {{ __($requirement->action->label) }}
                        </button>
                    </form>
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
                            <a href="{{ $newProductUrl }}" class="text-sky-400 hover:text-sky-300 underline underline-offset-2">{{ __('connectors.action_new_product') }}</a>
                        @endif
                    </p>
                @endif
            @endif
            <form method="POST" action="{{ route('admin.connectors.activate', ['connector' => $key]) }}">
                @csrf
                <button type="submit" class="inline-flex items-center gap-2 px-3 py-2 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
                    {{ $isMeta ? __('connectors.meta_action_activate') : __('connectors.action_activate') }}
                </button>
            </form>
        @else
            @if($included !== null)
                <p>{{ trans_choice('connectors.included_count', $included, ['count' => $included]) }} · {{ trans_choice('connectors.skipped_count', (int) $skipped, ['count' => (int) $skipped]) }}</p>
            @endif
        @endif

        @if($record?->enabled)
            <div class="text-xs text-gray-400 space-y-1" data-fetch-log>
                @if($isMeta)
                    @if($record->last_meta_fetched_at)
                        <p class="text-sm text-gray-300">{{ __('connectors.last_meta_fetch', ['ago' => $ago($record->last_meta_fetched_at), 'date' => $record->last_meta_fetched_at->format('d/m/Y H:i')]) }}</p>
                    @else
                        <p class="text-sm text-yellow-300/90">{{ __('connectors.meta_never_fetched') }}</p>
                    @endif
                @endif
                @if($detail || ! $isMeta)
                    @if($record->last_fetched_at)
                        <p>{{ __('connectors.last_fetch', ['date' => $record->last_fetched_at->format('d/m/Y H:i'), 'agent' => $record->last_fetch_agent ?? '—']) }}</p>
                    @else
                        <p>{{ __('connectors.never_fetched') }}</p>
                    @endif
                @endif
            </div>
        @endif
    </div>

    @unless($detail)
        <footer class="mt-auto pt-3 border-t border-white/[0.06]">
            <a href="{{ route('admin.connectors.show', ['connector' => $key]) }}" class="inline-flex items-center gap-1 text-sm text-sky-400 hover:text-sky-300 transition-colors">
                {{ __('connectors.view_details') }}
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </a>
        </footer>
    @endunless
</article>
