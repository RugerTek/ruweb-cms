@extends('admin.layouts.app')

@section('title', __($connector->label()))

@section('content')
{{--
    Detail of the Meta Pixel connector (Admin\ConnectorController::showMetaPixel()): status card, settings (pixel ID,
    access token shown only as "ends in …", test events code), test event button, the events the site sends and the
    privacy note. The token is never printed: the field is always empty and typing replaces the saved one.
--}}
@php
    $input = 'w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50';
    $link = 'text-sky-400 hover:text-sky-300 underline underline-offset-2';
@endphp
<div class="mb-6">
    <a href="{{ route('admin.connectors.index') }}" class="inline-flex items-center gap-1 text-xs text-gray-500 hover:text-gray-300 transition-colors">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        {{ __('connectors.back_to_index') }}
    </a>
    <h1 class="text-lg font-semibold text-white mt-2">{{ __($connector->label()) }}</h1>
</div>

@if($errors->any())
    <div class="mb-4 p-3.5 bg-red-500/10 border border-red-500/20 text-red-300 rounded-md text-sm" role="alert">
        @foreach($errors->all() as $error)
            <p>{{ $error }}</p>
        @endforeach
    </div>
@endif

<div class="max-w-4xl space-y-4" data-meta-pixel-detail>
    {{-- 1. Status: activate here when the pixel ID is saved --}}
    @include('admin.connectors._card', ['detail' => true])

    {{-- 2. Settings --}}
    <section class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5" aria-labelledby="pixel-settings-title">
        <h2 id="pixel-settings-title" class="text-sm font-semibold text-white">{{ __('meta_pixel.settings_title') }}</h2>
        <p class="text-xs text-gray-500 mt-1 mb-4">{{ __('meta_pixel.settings_help') }}</p>
        <form method="POST" action="{{ route('admin.connectors.meta-pixel.update', ['connector' => $key]) }}" class="space-y-4" autocomplete="off">
            @csrf
            @method('PUT')
            <input type="hidden" name="expected_revision" value="{{ $revision }}">
            <div>
                <label for="pixel-id" class="block text-xs text-gray-400 mb-1">{{ __('meta_pixel.field_pixel_id') }}</label>
                <input type="text" id="pixel-id" name="pixel_id" inputmode="numeric" value="{{ old('pixel_id', $settings['pixel_id'] ?? '') }}" class="{{ $input }} font-mono" placeholder="123456789012345">
                <p class="text-xs text-gray-500 mt-1">{!! __('meta_pixel.field_pixel_id_help', ['link' => '<a href="'.e($links['events_manager']).'" target="_blank" rel="noopener" class="'.$link.'">'.e(__('meta_pixel.link_events_manager')).'</a>']) !!}</p>
                @error('pixel_id')<p class="text-red-400 text-xs mt-1" role="alert">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="access-token" class="block text-xs text-gray-400 mb-1">{{ __('meta_pixel.field_access_token') }}</label>
                @if($tokenSuffix !== null)
                    <p class="text-sm text-emerald-400 mb-2" data-token-status="saved">{{ __('meta_pixel.token_saved', ['suffix' => $tokenSuffix]) }}</p>
                @elseif($tokenUnusable)
                    <p class="text-sm text-yellow-300/90 mb-2" data-token-status="unusable">{{ __('meta_pixel.token_unusable') }}</p>
                @else
                    <p class="text-sm text-gray-400 mb-2" data-token-status="missing">{{ __('meta_pixel.token_missing') }}</p>
                @endif
                <input type="password" id="access-token" name="access_token" value="" class="{{ $input }} font-mono" autocomplete="new-password"
                       placeholder="{{ $tokenSuffix !== null ? __('meta_pixel.token_placeholder_replace') : __('meta_pixel.token_placeholder') }}">
                <p class="text-xs text-gray-500 mt-1">{!! __('meta_pixel.field_access_token_help', ['link' => '<a href="'.e($links['access_token']).'" target="_blank" rel="noopener" class="'.$link.'">'.e(__('meta_pixel.link_meta_guide')).'</a>']) !!}</p>
                <p class="text-xs text-yellow-300/90 mt-1" data-meta-pixel-no-meta-integration>{{ __('meta_pixel.field_access_token_no_meta_integration') }}</p>
                @error('access_token')<p class="text-red-400 text-xs mt-1" role="alert">{{ $message }}</p>@enderror
                @if($tokenSuffix !== null || $tokenUnusable)
                    <label class="mt-2 inline-flex items-center gap-2 text-xs text-gray-400">
                        <input type="checkbox" name="forget_access_token" value="1" class="rounded border-white/20 bg-[#1a1a1a]">
                        {{ __('meta_pixel.token_forget') }}
                    </label>
                @endif
            </div>
            <div>
                <label for="test-event-code" class="block text-xs text-gray-400 mb-1">{{ __('meta_pixel.field_test_event_code') }}</label>
                <input type="text" id="test-event-code" name="test_event_code" value="{{ old('test_event_code', $settings['test_event_code'] ?? '') }}" class="{{ $input }} font-mono" placeholder="TEST12345">
                <p class="text-xs text-gray-500 mt-1">{{ __('meta_pixel.field_test_event_code_help') }}</p>
                @error('test_event_code')<p class="text-red-400 text-xs mt-1" role="alert">{{ $message }}</p>@enderror
            </div>
            <button type="submit" class="px-3 py-2 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">{{ __('connectors.action_save') }}</button>
        </form>
    </section>

    {{-- 3. Test event --}}
    <section class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5" aria-labelledby="pixel-test-title">
        <h2 id="pixel-test-title" class="text-sm font-semibold text-white">{{ __('meta_pixel.test_title') }}</h2>
        <p class="text-xs text-gray-500 mt-1 mb-3">{!! __('meta_pixel.test_help', ['link' => '<a href="'.e($links['test_events']).'" target="_blank" rel="noopener" class="'.$link.'">'.e(__('meta_pixel.link_test_events')).'</a>']) !!}</p>
        <form method="POST" action="{{ route('admin.connectors.meta-pixel.test', ['connector' => $key]) }}">
            @csrf
            <button type="submit" class="px-3 py-2 bg-[#1a1a1a] border border-white/10 hover:border-white/20 text-gray-300 text-sm font-medium rounded-md transition-colors" data-meta-pixel-test>
                {{ __('meta_pixel.action_test') }}
            </button>
        </form>
        @error('test_event')<p class="text-red-400 text-xs mt-2" role="alert">{{ $message }}</p>@enderror
    </section>

    {{-- 4. What the site sends --}}
    <section class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5" aria-labelledby="pixel-events-title">
        <h2 id="pixel-events-title" class="text-sm font-semibold text-white">{{ __('meta_pixel.events_title') }}</h2>
        <p class="text-xs text-gray-500 mt-1 mb-3">{{ __('meta_pixel.events_help') }}</p>
        <ul class="text-sm text-gray-300 space-y-1.5">
            @foreach($events as $event)
                <li><span class="font-mono text-xs text-sky-300">{{ $event }}</span> — {{ __('meta_pixel.event_'.$event) }}</li>
            @endforeach
        </ul>
        @unless($shopOn)
            <p class="text-xs text-gray-500 mt-3">{{ __('meta_pixel.events_without_shop') }}</p>
        @endunless
        <p class="text-xs text-gray-500 mt-3">{{ __('meta_pixel.events_not_measured') }}</p>
    </section>

    {{-- 5. Privacy --}}
    <section class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5" aria-labelledby="pixel-privacy-title">
        <h2 id="pixel-privacy-title" class="text-sm font-semibold text-white">{{ __('meta_pixel.privacy_title') }}</h2>
        <p class="text-sm text-gray-300 mt-2">{{ __('meta_pixel.privacy_help') }}</p>
    </section>

    {{-- 6. Deactivate --}}
    @if($record?->enabled)
        <section class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5">
            <form method="POST" action="{{ route('admin.connectors.deactivate', ['connector' => $key]) }}"
                  data-confirm="{{ __('meta_pixel.confirm_deactivate') }}"
                  data-confirm-title="{{ __('connectors.action_deactivate') }}"
                  data-confirm-label="{{ __('connectors.action_deactivate') }}"
                  data-confirm-tone="danger">
                @csrf
                <button type="submit" class="px-3 py-2 border border-red-500/30 text-red-300 hover:bg-red-500/10 text-sm font-medium rounded-md transition-colors">
                    {{ __('connectors.action_deactivate') }}
                </button>
            </form>
            <p class="text-xs text-gray-500 mt-2">{{ __('meta_pixel.confirm_deactivate') }}</p>
        </section>
    @endif
</div>
@endsection
