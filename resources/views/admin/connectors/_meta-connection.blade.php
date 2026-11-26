{{--
    «Conexión con Meta» section of the Meta detail (plan-meta-v2, track 2b). Three states that exclude each other:
    manual (v1: the guide below explains how to paste the feed URL; «Conectar con Facebook» is offered when
    RUWEB_CONNECT_URL is set), connected (business, catalog, since when and by whom; change catalog, disconnect) and
    needs reconnect (Meta revoked the token: a direct «Volver a conectar»). While connected, a yellow warning
    when the daily check has not reached Meta for several days (the connection stays). Receives $unmet from the detail view; the
    connection is read from MetaConnectionStore (it never exposes the token, only its last 4 characters).
--}}
@php
    $metaConnection = app(\App\Connectors\Meta\Connect\MetaConnectionStore::class)->get();
    $metaUnreachableStreak = app(\App\Services\Connectors\Meta\MetaUnreachableStreak::class);
    $connectAvailable = \App\Http\Controllers\Admin\MetaConnectController::available();
    $connectBlocked = $unmet !== [];
    $commerceManagerUrl = \App\Http\Controllers\Admin\MetaConnectController::COMMERCE_MANAGER_URL;
    $anchor = fn (string $url, string $label): string => '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer" class="text-sky-400 hover:text-sky-300 underline underline-offset-2">'.e($label).'</a>';
    $withLink = fn (string $key, string $link, array $replace = []): string => str_replace('@@link@@', $link, e(__($key, $replace + ['link' => '@@link@@'])));
    $commerceLink = $anchor($commerceManagerUrl, __('connectors.guide_link_commerce_manager'));
    $connectedBy = $metaConnection->connectedBy !== null ? \App\Models\User::query()->find($metaConnection->connectedBy) : null;
    $connectedAt = $metaConnection->connectedAt !== null ? \Illuminate\Support\Carbon::instance($metaConnection->connectedAt)->setTimezone(config('app.timezone')) : null;
    $noCatalog = session('meta_connect_no_catalog');
    $primary = 'inline-flex items-center gap-2 px-3 py-2 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors';
@endphp
<section id="meta-connection" class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5 space-y-4" aria-labelledby="meta-connection-title" data-meta-connection="{{ $metaConnection->needsReconnect() ? 'needs_reconnect' : $metaConnection->mode }}">
    <h2 id="meta-connection-title" class="text-sm font-semibold text-white">{{ __('connectors.meta_connection_title') }}</h2>

    @if($noCatalog)
        <div class="p-3.5 bg-yellow-500/10 border border-yellow-500/20 text-yellow-200 rounded-md text-sm space-y-2" role="alert" data-meta-no-catalog="{{ $noCatalog }}">
            <p class="font-medium">{{ __('connectors.meta_no_catalog_title') }}</p>
            <p>{{ $noCatalog === 'generic' ? __('connectors.meta_no_catalog_generic') : __('connectors.meta_no_catalog_none') }}</p>
            <p>{!! $withLink('connectors.meta_no_catalog_steps', $commerceLink) !!}</p>
        </div>
    @endif

    @if($metaConnection->needsReconnect())
        {{-- Meta revoked the token (190/102) or the catalog access: one direct button. --}}
        <div class="p-3.5 bg-red-500/10 border border-red-500/20 text-red-200 rounded-md text-sm space-y-2" role="alert">
            <p class="font-medium">{{ __('connectors.meta_reconnect_title') }}</p>
            <p>{{ __('connectors.meta_reconnect_text') }}</p>
        </div>
        @if($connectAvailable)
            <a href="{{ route('admin.connectors.meta.connect') }}" class="{{ $primary }}" data-meta-connect="reconnect">{{ __('connectors.meta_action_reconnect') }}</a>
        @else
            <p class="text-sm text-gray-400">{{ __('connectors.meta_connect_unavailable') }}</p>
        @endif
    @endif

    @if($metaConnection->isConnected())
        @unless($metaConnection->needsReconnect())
            <p class="text-sm text-emerald-300">{{ __('connectors.meta_mode_connected') }}</p>
            <p class="text-xs text-gray-500">{{ __('connectors.meta_connected_help') }}</p>
            @if($metaUnreachableStreak->warns($metaConnection))
                {{-- Meta has not answered the daily check for N days: warning only, the connection stays (MetaUnreachableStreak). --}}
                <div class="p-3.5 bg-yellow-500/10 border border-yellow-500/20 text-yellow-200 rounded-md text-sm" role="status" data-meta-unreachable="{{ $metaUnreachableStreak->days($metaConnection) }}">
                    {{ __('meta_health.error_unreachable_days', ['days' => $metaUnreachableStreak->days($metaConnection)]) }}
                </div>
            @endif
        @endunless
        <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-2 text-sm">
            <div>
                <dt class="text-xs text-gray-500">{{ __('connectors.meta_connected_catalog') }}</dt>
                <dd class="text-gray-200" data-meta-catalog>{{ $metaConnection->catalogName ?? $metaConnection->catalogId ?? __('connectors.meta_catalog_missing') }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">{{ __('connectors.meta_connected_business') }}</dt>
                <dd class="text-gray-200 font-mono text-xs">{{ $metaConnection->businessId }}</dd>
            </div>
            @if($connectedAt)
                <div>
                    <dt class="text-xs text-gray-500">{{ __('connectors.meta_connected_since') }}</dt>
                    <dd class="text-gray-200">{{ __('connectors.meta_connected_since_value', ['date' => $connectedAt->format('d/m/Y H:i'), 'user' => $connectedBy?->name ?? '—']) }}</dd>
                </div>
            @endif
            @if($metaConnection->tokenSuffix)
                <div>
                    <dt class="text-xs text-gray-500">{{ __('connectors.meta_connected_access') }}</dt>
                    <dd class="text-gray-200">{{ __('connectors.meta_connected_access_value', ['suffix' => $metaConnection->tokenSuffix]) }}</dd>
                </div>
            @endif
        </dl>

        <div class="flex flex-wrap items-start gap-3">
            @unless($metaConnection->needsReconnect())
                <a href="{{ route('admin.connectors.meta.catalog') }}" class="px-3 py-2 bg-[#1a1a1a] border border-white/10 hover:border-white/20 text-gray-300 text-sm font-medium rounded-md transition-colors">
                    {{ $metaConnection->catalogId === null ? __('connectors.meta_choose_catalog_title') : __('connectors.meta_action_change_catalog') }}
                </a>
            @endunless
            <form method="POST" action="{{ route('admin.connectors.meta.disconnect') }}" class="space-y-2"
                  data-confirm="{{ __('connectors.meta_disconnect_confirm') }}"
                  data-confirm-title="{{ __('connectors.meta_action_disconnect') }}"
                  data-confirm-label="{{ __('connectors.meta_action_disconnect') }}"
                  data-confirm-tone="danger">
                @csrf
                <button type="submit" class="px-3 py-2 border border-red-500/30 text-red-300 hover:bg-red-500/10 text-sm font-medium rounded-md transition-colors">
                    {{ __('connectors.meta_action_disconnect') }}
                </button>
                <label class="flex items-start gap-2 text-xs text-gray-400 max-w-md">
                    <input type="checkbox" name="delete_feed" value="1" class="mt-0.5 rounded border-white/20 bg-[#1a1a1a]">
                    <span>{{ __('connectors.meta_disconnect_delete_feed') }}</span>
                </label>
            </form>
        </div>
        <p class="text-xs text-gray-500">{{ __('connectors.meta_disconnect_confirm') }}</p>
    @else
        <div>
            <p class="text-sm text-gray-300">{{ __('connectors.meta_mode_manual') }}</p>
            <p class="text-xs text-gray-500 mt-1">{{ __('connectors.meta_mode_manual_help') }}</p>
        </div>

        <div class="text-sm text-gray-300 space-y-2">
            <p class="font-medium text-white">{{ __('connectors.meta_connect_requirement_title') }}</p>
            <p>{!! $withLink('connectors.meta_connect_requirement', $commerceLink) !!}</p>
            <ol class="list-decimal pl-5 space-y-1 text-gray-400">
                <li>{{ __('connectors.meta_connect_step_create') }}</li>
                <li>{{ __('connectors.meta_connect_step_dialog') }}</li>
            </ol>
            <ul class="list-disc pl-5 space-y-1 text-yellow-300/90">
                <li>{{ __('connectors.meta_connect_warning_create') }}</li>
                <li>{{ __('connectors.meta_connect_warning_portfolios') }}</li>
            </ul>
        </div>

        @if($connectAvailable && ! $connectBlocked)
            <form method="GET" action="{{ route('admin.connectors.meta.connect') }}"
                  data-confirm="{{ __('connectors.meta_connect_confirm') }}"
                  data-confirm-title="{{ __('connectors.meta_action_connect') }}"
                  data-confirm-label="{{ __('connectors.meta_action_connect') }}"
                  data-confirm-tone="primary">
                <button type="submit" class="{{ $primary }}" data-meta-connect="connect">{{ __('connectors.meta_action_connect') }}</button>
            </form>
        @else
            <div class="space-y-1">
                <button type="button" disabled aria-disabled="true" class="inline-flex items-center gap-2 px-3 py-2 bg-white/[0.04] text-gray-500 text-sm font-medium rounded-md cursor-not-allowed" data-meta-connect="disabled">{{ __('connectors.meta_action_connect') }}</button>
                <p class="text-xs text-gray-500">{{ $connectAvailable ? __('connectors.meta_needs_catalog') : __('connectors.meta_connect_unavailable') }}</p>
            </div>
        @endif
    @endif

    @if($metaConnection->isConnected() || session('meta_disconnected'))
        <p class="text-xs text-gray-400" data-meta-remove-app>{!! $withLink('connectors.meta_remove_app_help', $anchor(\App\Http\Controllers\Admin\MetaConnectController::BUSINESS_SETTINGS_URL, __('connectors.meta_remove_app_link'))) !!}</p>
    @endif
</section>
