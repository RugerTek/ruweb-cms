@extends('admin.layouts.app')

@section('title', __('shop.module_title'))

@section('content')
{{-- Settings > Shop (docs/shop/catalog.md). Choosing a mode opens the confirmation; only the confirmed PUT switches it. --}}
<div class="mb-6">
    <h1 class="text-lg font-semibold text-white">{{ __('admin.settings') }}</h1>
    <p class="text-sm text-gray-500 mt-0.5">{{ __('admin.settings_subtitle') }}</p>
</div>

<div class="flex flex-wrap gap-1 mb-6 border-b border-white/[0.06]">
    <a href="{{ route('admin.settings.index') }}" class="px-3 py-2 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-300 transition-colors">{{ __('admin.settings') }}</a>
    <span class="px-3 py-2 text-sm font-medium border-b-2 border-sky-500 text-sky-400" aria-current="page">{{ __('shop.module_title') }}</span>
</div>

<section class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-6 max-w-3xl" data-shop-module="{{ $current }}">
    <h2 class="text-base font-semibold text-white">{{ __('shop.module_title') }}</h2>
    <p class="text-sm text-gray-500 mt-1">{{ __('shop.module_help') }}</p>
    <p class="text-sm text-gray-300 mt-4">
        {{ __('shop.module_current') }}
        <span class="ml-1 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $current === 'off' ? 'bg-white/[0.06] text-gray-400' : 'bg-sky-500/10 text-sky-400' }}">{{ __('shop.module_mode_'.$current) }}</span>
    </p>
    @if ($stored !== $current)
        <p class="text-xs text-amber-400 mt-2">{{ __('shop.module_limited', ['limit' => __('shop.module_mode_'.$limit)]) }}</p>
    @endif

    @if ($limit === 'off')
        <p class="text-sm text-gray-400 mt-6">{{ __('shop.module_disabled_by_config') }}</p>
    @else
        <form method="GET" action="{{ route('admin.settings.shop') }}" class="mt-6 space-y-3">
            @foreach ($choices as $choice)
                @php $mode = $choice['mode']; @endphp
                <label class="flex items-start gap-3 rounded-lg ring-1 ring-white/[0.06] p-4 {{ $choice['available'] ? 'cursor-pointer hover:bg-white/[0.02]' : 'opacity-60' }}">
                    <input type="radio" name="mode" value="{{ $mode }}" class="mt-1"
                           @checked(($pending ?? $current) === $mode) @disabled(! $choice['available'])>
                    <span>
                        <span class="block text-sm font-medium text-white">
                            {{ __('shop.module_mode_'.$mode) }}
                            @unless ($choice['available'])
                                <span class="ml-2 px-1.5 py-0.5 rounded text-[11px] bg-white/[0.06] text-gray-400">{{ __('shop.module_coming_soon') }}</span>
                            @endunless
                        </span>
                        <span class="block text-xs text-gray-500 mt-0.5">{{ __('shop.module_mode_'.$mode.'_help') }}</span>
                    </span>
                </label>
            @endforeach
            <button type="submit" class="px-4 py-2 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
                {{ __('shop.module_continue') }}
            </button>
        </form>
    @endif

    @error('mode')
        <p class="text-sm text-red-400 mt-4">{{ $message }}</p>
    @enderror
</section>

@if ($pending)
    <section class="mt-6 bg-[#141414] ring-1 ring-amber-500/30 rounded-xl p-6 max-w-3xl" data-shop-module-confirm="{{ $pending }}">
        <h2 class="text-base font-semibold text-white">{{ __('shop.module_confirm_title', ['mode' => __('shop.module_mode_'.$pending)]) }}</h2>
        <p class="text-sm text-gray-400 mt-2">{{ __('shop.module_confirm_'.$pending) }}</p>
        @if ($pending === 'off')
            <p class="text-sm text-gray-300 mt-2">{{ trans_choice('shop.module_confirm_off_kept', $productCount, ['count' => $productCount]) }}</p>
            @if ($pausedConnectors !== [])
                <div class="mt-3 p-3 rounded-md bg-amber-500/10 ring-1 ring-amber-500/20" data-shop-module-connectors>
                    <p class="text-sm font-medium text-amber-300">{{ __('shop.module_confirm_off_connectors', ['connectors' => implode(', ', $pausedConnectors)]) }}</p>
                    <p class="text-xs text-amber-200/80 mt-1">{{ __('shop.module_confirm_off_connectors_help') }}</p>
                </div>
            @endif
        @endif
        <form method="POST" action="{{ route('admin.settings.shop.update') }}" class="mt-4 flex items-center gap-3">
            @csrf
            @method('PUT')
            <input type="hidden" name="mode" value="{{ $pending }}">
            <input type="hidden" name="confirm" value="1">
            <button type="submit" class="px-4 py-2 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
                {{ __('shop.module_confirm_button') }}
            </button>
            <a href="{{ route('admin.settings.shop') }}" class="text-sm text-gray-400 hover:text-gray-200">{{ __('shop.btn_cancel') }}</a>
        </form>
    </section>
@endif
@endsection
