@extends('admin.layouts.app')

@section('title', __($connector->label()))

@section('content')
{{--
    Detail of the Amiba chatbot connector (Admin\ConnectorController::showAmibaChat()): status card, widget code form
    (the handle alone or the whole snippet pasted from Amiba), "Test" against Amiba's API, the guide to find the code
    and allow this site in Amiba, and Deactivate. The handle is public (it travels in the HTML of every page).
--}}
@php
    $input = 'w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50';
    $test = session('amiba_chat_test');
    $handle = (string) ($settings['handle'] ?? '');
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

<div class="max-w-4xl space-y-4" data-amiba-chat-detail>
    {{-- 1. Status: activate here once the widget code is saved --}}
    @include('admin.connectors._card', ['detail' => true])

    {{-- 2. Widget code --}}
    <section class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5" aria-labelledby="amiba-settings-title">
        <h2 id="amiba-settings-title" class="text-sm font-semibold text-white">{{ __('amiba_chat.settings_title') }}</h2>
        <p class="text-xs text-gray-500 mt-1 mb-4">{{ __('amiba_chat.settings_help') }}</p>
        <form method="POST" action="{{ route('admin.connectors.amiba-chat.update', ['connector' => $key]) }}" class="space-y-4" autocomplete="off">
            @csrf
            @method('PUT')
            <input type="hidden" name="expected_revision" value="{{ $revision }}">
            <div>
                <label for="amiba-handle" class="block text-xs text-gray-400 mb-1">{{ __('amiba_chat.field_handle') }}</label>
                <textarea id="amiba-handle" name="handle" rows="3" spellcheck="false" class="{{ $input }} font-mono" placeholder="{{ __('amiba_chat.field_handle_placeholder') }}">{{ old('handle', $handle) }}</textarea>
                <p class="text-xs text-gray-500 mt-1">{{ __('amiba_chat.field_handle_help') }}</p>
                @error('handle')<p class="text-red-400 text-xs mt-1" role="alert">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="inline-flex items-start gap-2 text-sm text-gray-300">
                    <input type="checkbox" name="hide_on_checkout" value="1" class="mt-0.5 rounded border-white/20 bg-[#1a1a1a]" @checked(old('hide_on_checkout', $settings['hide_on_checkout'] ?? false)) data-amiba-hide-on-checkout>
                    <span>{{ __('amiba_chat.field_hide_on_checkout') }}</span>
                </label>
                <p class="text-xs text-gray-500 mt-1 ml-6">{{ $shopCheckout ? __('amiba_chat.field_hide_on_checkout_help') : __('amiba_chat.field_hide_on_checkout_no_checkout') }}</p>
            </div>
            <button type="submit" class="px-3 py-2 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">{{ __('connectors.action_save') }}</button>
        </form>
        @if(\App\Connectors\AmibaChat\AmibaChatConnector::validHandle($handle))
            <div class="mt-4">
                <p class="text-xs text-gray-400 mb-1">{{ __('amiba_chat.snippet_title') }}</p>
                <pre class="text-xs text-gray-300 bg-[#1a1a1a] border border-white/10 rounded-md px-3 py-2 overflow-x-auto" data-amiba-snippet>{{ '<script src="'.$loaderUrl.'" data-amiba-widget="'.$handle.'" async></script>' }}</pre>
            </div>
        @endif
    </section>

    {{-- 3. Test against Amiba --}}
    <section class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5" aria-labelledby="amiba-test-title">
        <h2 id="amiba-test-title" class="text-sm font-semibold text-white">{{ __('amiba_chat.test_title') }}</h2>
        <p class="text-xs text-gray-500 mt-1 mb-3">{{ __('amiba_chat.test_help') }}</p>
        <form method="POST" action="{{ route('admin.connectors.amiba-chat.test', ['connector' => $key]) }}">
            @csrf
            <button type="submit" class="px-3 py-2 bg-[#1a1a1a] border border-white/10 hover:border-white/20 text-gray-300 text-sm font-medium rounded-md transition-colors" data-amiba-test>
                {{ __('amiba_chat.action_test') }}
            </button>
        </form>
        @error('test')<p class="text-red-400 text-xs mt-2" role="alert">{{ $message }}</p>@enderror
        @if(is_array($test))
            <div class="mt-3 p-3 rounded-md text-sm {{ $test['ok'] ? 'bg-emerald-500/10 border border-emerald-500/20 text-emerald-300' : 'bg-yellow-500/10 border border-yellow-500/20 text-yellow-200' }}" role="status" data-amiba-test-result="{{ $test['outcome'] }}">
                <p>{{ $test['message'] }}</p>
                @if($test['outcome'] === \App\Services\AmibaChat\AmibaChatTestResult::ORIGIN_NOT_ALLOWED)
                    <p class="mt-2">{{ __('amiba_chat.test_add_this') }}</p>
                    <p class="mt-1 font-mono text-white" data-amiba-origin>{{ $test['origin'] }}</p>
                @endif
            </div>
        @endif
    </section>

    {{-- 4. Where the code comes from, and allowing this site in Amiba --}}
    <section class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5" aria-labelledby="amiba-guide-title" data-amiba-guide>
        <h2 id="amiba-guide-title" class="text-sm font-semibold text-white">{{ __('amiba_chat.guide_title') }}</h2>
        <ol class="list-decimal ml-5 mt-3 text-sm text-gray-300 space-y-2">
            <li>{{ __('amiba_chat.guide_step_open') }}</li>
            <li>{{ __('amiba_chat.guide_step_copy') }}</li>
            <li>{{ __('amiba_chat.guide_step_paste') }}</li>
            <li>
                {{ __('amiba_chat.guide_step_origin') }}
                <span class="block mt-1 font-mono text-white" data-amiba-site-origin>{{ $siteOrigin }}</span>
                <span class="block mt-1 text-xs text-gray-500">{{ __('amiba_chat.guide_step_origin_help') }}</span>
            </li>
            <li>{{ __('amiba_chat.guide_step_test') }}</li>
        </ol>
        <p class="text-xs text-gray-500 mt-3">{{ __('amiba_chat.guide_where') }}</p>
    </section>

    {{-- 5. Deactivate --}}
    @if($record?->enabled)
        <section class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5">
            <form method="POST" action="{{ route('admin.connectors.deactivate', ['connector' => $key]) }}"
                  data-confirm="{{ __('amiba_chat.confirm_deactivate') }}"
                  data-confirm-title="{{ __('connectors.action_deactivate') }}"
                  data-confirm-label="{{ __('connectors.action_deactivate') }}"
                  data-confirm-tone="danger">
                @csrf
                <button type="submit" class="px-3 py-2 border border-red-500/30 text-red-300 hover:bg-red-500/10 text-sm font-medium rounded-md transition-colors">
                    {{ __('connectors.action_deactivate') }}
                </button>
            </form>
            <p class="text-xs text-gray-500 mt-2">{{ __('amiba_chat.confirm_deactivate') }}</p>
        </section>
    @endif
</div>
@endsection
