{{--
    Meta v2: state of the catalog synced by API (App\Services\Connectors\Meta\MetaCatalogSync::status(), cached a few
    minutes). Included from the Meta detail; renders nothing in manual mode (v1). Track 2c (plan-meta-v2).
--}}
@php
    $metaSyncService = app(\App\Services\Connectors\Meta\MetaCatalogSync::class);
    $metaSync = $metaSyncService->status();
    $metaSyncTone = match ($metaSync->state) {
        \App\Services\Connectors\Meta\MetaSyncStatus::SYNCED => 'bg-emerald-500/10 text-emerald-300 ring-emerald-500/20',
        \App\Services\Connectors\Meta\MetaSyncStatus::WITH_ERRORS, \App\Services\Connectors\Meta\MetaSyncStatus::NEEDS_RECONNECT => 'bg-red-500/10 text-red-300 ring-red-500/20',
        default => 'bg-yellow-500/10 text-yellow-300 ring-yellow-500/20',
    };
    $metaSyncCommerceUrl = $metaSyncService->commerceManagerUrl();
@endphp
@if($metaSync->isConnected())
    <section id="meta-sync" class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5" aria-labelledby="meta-sync-title" data-meta-sync-state="{{ $metaSync->state }}">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="meta-sync-title" class="text-sm font-semibold text-white">{{ __('meta_sync.title') }}</h2>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ring-1 {{ $metaSyncTone }}">{{ __('meta_sync.state_'.$metaSync->state) }}</span>
        </div>
        <p class="text-xs text-gray-500 mt-1">{{ __('meta_sync.help') }}</p>

        @error('meta_sync')
            <p class="mt-3 p-3 bg-red-500/10 border border-red-500/20 text-red-300 rounded-md text-sm" role="alert">{{ $message }}</p>
        @enderror
        @if($metaSync->message && in_array($metaSync->state, [\App\Services\Connectors\Meta\MetaSyncStatus::NEEDS_RECONNECT, \App\Services\Connectors\Meta\MetaSyncStatus::NO_FEED, \App\Services\Connectors\Meta\MetaSyncStatus::UNAVAILABLE], true))
            <p class="mt-3 text-sm text-yellow-300/90">{{ $metaSync->message }}</p>
        @elseif($metaSync->state === \App\Services\Connectors\Meta\MetaSyncStatus::NO_FEED)
            <p class="mt-3 text-sm text-yellow-300/90">{{ __('meta_sync.no_feed_help') }}</p>
        @elseif($metaSync->state === \App\Services\Connectors\Meta\MetaSyncStatus::PROCESSING)
            <p class="mt-3 text-sm text-gray-300">{{ __('meta_sync.processing_help') }}</p>
        @endif

        <dl class="mt-4 grid gap-3 sm:grid-cols-3 text-sm">
            @if($metaSync->catalogName || $metaSync->catalogId)
                <div>
                    <dt class="text-xs text-gray-500">{{ __('meta_sync.catalog') }}</dt>
                    <dd class="text-gray-200">{{ $metaSync->catalogName ?? $metaSync->catalogId }}</dd>
                </div>
            @endif
            @if($metaSync->feedId !== null && ! in_array($metaSync->state, [\App\Services\Connectors\Meta\MetaSyncStatus::NEEDS_RECONNECT, \App\Services\Connectors\Meta\MetaSyncStatus::UNAVAILABLE, \App\Services\Connectors\Meta\MetaSyncStatus::NO_FEED], true))
                <div>
                    <dt class="text-xs text-gray-500">{{ __('meta_sync.last_read') }}</dt>
                    @if($metaSync->uploadFinishedAt)
                        @php $metaSyncRead = \Illuminate\Support\Carbon::instance($metaSync->uploadFinishedAt)->setTimezone(config('app.timezone')); @endphp
                        <dd class="text-gray-200">{{ __('meta_sync.last_read_value', ['date' => $metaSyncRead->format('d/m/Y H:i'), 'ago' => $metaSyncRead->diffForHumans()]) }}</dd>
                    @else
                        <dd class="text-gray-400">{{ __('meta_sync.never_read') }}</dd>
                    @endif
                </div>
                @if($metaSync->uploadFinishedAt)
                    <div>
                        <dt class="text-xs text-gray-500">{{ __('meta_sync.products_loaded') }}</dt>
                        <dd class="text-gray-200">{{ __('meta_sync.products_loaded_value', ['persisted' => number_format($metaSync->persistedItems, 0, ',', '.'), 'detected' => number_format($metaSync->detectedItems, 0, ',', '.')]) }}</dd>
                    </div>
                @endif
            @endif
        </dl>

        @if($metaSync->issues !== [])
            <div class="mt-5">
                <h3 class="text-xs font-semibold text-white">{{ __('meta_sync.issues_title') }}</h3>
                <p class="text-xs text-gray-500 mt-0.5 mb-2">{{ __('meta_sync.issues_help') }}</p>
                <ul class="space-y-2">
                    @foreach($metaSync->issues as $issue)
                        <li class="p-3 rounded-md ring-1 {{ $issue->isError() ? 'ring-red-500/20 bg-red-500/5' : 'ring-yellow-500/20 bg-yellow-500/5' }}">
                            <p class="text-sm {{ $issue->isError() ? 'text-red-300' : 'text-yellow-300' }}">
                                <span class="font-medium">{{ $issue->isError() ? __('meta_sync.issue_error') : __('meta_sync.issue_warning') }}:</span>
                                {{ $issue->summary }}
                            </p>
                            @if($issue->description)
                                <p class="text-xs text-gray-400 mt-1">{{ $issue->description }}</p>
                            @endif
                            @if($issue->count > 0)
                                <p class="text-xs text-gray-500 mt-1">{{ __('meta_sync.issue_affected', ['count' => $issue->count]) }}</p>
                            @endif
                            @if($issue->isItem())
                                <p class="text-xs text-gray-400 mt-1">
                                    {{ $issue->productTitle ?? __('meta_sync.issue_product', ['id' => $issue->retailerId]) }}
                                    @if($issue->editUrl)
                                        · <a href="{{ $issue->editUrl }}" class="text-sky-400 hover:text-sky-300 underline">{{ __('meta_sync.issue_edit') }}</a>
                                    @else
                                        · {{ __('meta_sync.issue_product_missing') }}
                                    @endif
                                </p>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if($metaSync->issuesTotal > count($metaSync->issues))
                    <p class="text-xs text-gray-500 mt-2">{{ __('meta_sync.issues_more', ['count' => $metaSync->issuesTotal - count($metaSync->issues)]) }}</p>
                @endif
            </div>
        @endif

        <div class="mt-5 flex flex-wrap items-center gap-3">
            @if($metaSync->canSyncNow())
                <form method="POST" action="{{ route('admin.connectors.meta.sync') }}">
                    @csrf
                    <button type="submit" class="px-3 py-2 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">{{ __('meta_sync.action_sync') }}</button>
                </form>
            @endif
            @if($metaSyncCommerceUrl)
                <a href="{{ $metaSyncCommerceUrl }}" target="_blank" rel="noopener noreferrer" class="text-sm text-sky-400 hover:text-sky-300 underline">{{ __('meta_sync.open_commerce_manager') }}</a>
            @endif
        </div>
        @if($metaSync->canSyncNow())
            <p class="text-xs text-gray-500 mt-2">{{ __('meta_sync.sync_help') }}</p>
        @endif
        @if($metaSync->checkedAt)
            <p class="text-[11px] text-gray-600 mt-3">{{ __('meta_sync.checked_at', ['time' => \Illuminate\Support\Carbon::instance($metaSync->checkedAt)->setTimezone(config('app.timezone'))->format('H:i')]) }}</p>
        @endif
    </section>
@endif
