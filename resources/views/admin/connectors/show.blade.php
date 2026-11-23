@extends('admin.layouts.app')

@section('title', __($connector->label()))

@section('content')
@php
    $input = 'w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50';
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

<div class="max-w-4xl space-y-4">
    {{-- 1. Status, totals and the fetch log --}}
    @include('admin.connectors._card', ['detail' => true])

    {{-- 2. Feed URL: copy and regenerate --}}
    @if($record?->token_hash !== null)
        <section class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5" aria-labelledby="feed-url-title">
            <h2 id="feed-url-title" class="text-sm font-semibold text-white">{{ __('connectors.feed_url') }}</h2>
            <p class="text-xs text-gray-500 mt-1 mb-3">{{ __('connectors.feed_url_help') }}</p>
            @if($feedUrl)
                <div class="flex flex-col sm:flex-row gap-2">
                    <label for="feed-url" class="sr-only">{{ __('connectors.feed_url') }}</label>
                    <input type="text" id="feed-url" readonly value="{{ $feedUrl }}" class="{{ $input }} font-mono text-xs text-gray-300 select-all" onclick="this.select()">
                    <button type="button" data-copy-target="feed-url" data-copied="{{ __('connectors.copied') }}"
                            class="shrink-0 inline-flex items-center justify-center gap-2 px-3 py-2 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                        <span data-copy-label aria-live="polite">{{ __('connectors.action_copy') }}</span>
                    </button>
                </div>
            @elseif($tokenUnreadable)
                <p class="text-sm text-yellow-300/90">{{ __('connectors.token_unreadable') }}</p>
            @endif
            <form method="POST" action="{{ route('admin.connectors.rotate', ['connector' => $key]) }}" class="mt-3"
                  data-confirm="{{ __('connectors.confirm_rotate') }}"
                  data-confirm-title="{{ __('connectors.action_rotate') }}"
                  data-confirm-label="{{ __('connectors.action_rotate') }}"
                  data-confirm-tone="danger">
                @csrf
                <button type="submit" class="px-3 py-2 bg-[#1a1a1a] border border-white/10 hover:border-white/20 text-gray-300 text-sm font-medium rounded-md transition-colors">
                    {{ __('connectors.action_rotate') }}
                </button>
            </form>
        </section>
    @endif

    {{-- 3. Step by step guide (Meta) --}}
    @if($isMeta)
        @include('admin.connectors._meta-guide')
    @endif

    {{-- 4. Defaults the feed uses when a product does not say --}}
    @if($fields !== [])
        <section class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5" aria-labelledby="settings-title">
            <h2 id="settings-title" class="text-sm font-semibold text-white">{{ __('connectors.settings_title') }}</h2>
            <p class="text-xs text-gray-500 mt-1 mb-4">{{ __('connectors.settings_help') }}</p>
            <form method="POST" action="{{ route('admin.connectors.settings', ['connector' => $key]) }}" class="space-y-4">
                @csrf
                @method('PUT')
                <input type="hidden" name="expected_revision" value="{{ $revision }}">
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach($fields as $field)
                        @php $value = old($field['name'], $settings[$field['name']] ?? ''); @endphp
                        <div>
                            <label for="setting-{{ $field['name'] }}" class="block text-xs text-gray-400 mb-1">{{ $field['label'] }}</label>
                            @if($field['options'] !== null)
                                <select id="setting-{{ $field['name'] }}" name="{{ $field['name'] }}" class="{{ $input }}">
                                    @foreach($field['options'] as $optionValue => $optionLabel)
                                        <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input type="text" id="setting-{{ $field['name'] }}" name="{{ $field['name'] }}" value="{{ $value }}" class="{{ $input }}" @required($field['required'])>
                            @endif
                            @if($field['help'])
                                <p class="text-xs text-gray-500 mt-1">{{ $field['help'] }}</p>
                            @endif
                            @error($field['name'])<p class="text-red-400 text-xs mt-1" role="alert">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                    @if($isMeta)
                        <div>
                            <label for="setting-currency" class="block text-xs text-gray-400 mb-1">{{ __('connectors.setting_currency') }}</label>
                            <input type="text" id="setting-currency" value="{{ $currency }}" readonly disabled class="{{ $input }} text-gray-500">
                            <p class="text-xs text-gray-500 mt-1">{{ __('connectors.setting_currency_help', ['currency' => $currency]) }}</p>
                        </div>
                    @endif
                </div>
                <button type="submit" class="px-3 py-2 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">{{ __('connectors.action_save') }}</button>
            </form>
        </section>
    @endif

    {{-- 5. Preview: sample, XML, skipped items and notices --}}
    @include('admin.connectors._preview')

    {{-- 6. Deactivate --}}
    @if($record?->enabled)
        <section class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5">
            <form method="POST" action="{{ route('admin.connectors.deactivate', ['connector' => $key]) }}"
                  data-confirm="{{ __('connectors.confirm_deactivate') }}"
                  data-confirm-title="{{ $isMeta ? __('connectors.meta_action_deactivate') : __('connectors.action_deactivate') }}"
                  data-confirm-label="{{ $isMeta ? __('connectors.meta_action_deactivate') : __('connectors.action_deactivate') }}"
                  data-confirm-tone="danger">
                @csrf
                <button type="submit" class="px-3 py-2 border border-red-500/30 text-red-300 hover:bg-red-500/10 text-sm font-medium rounded-md transition-colors">
                    {{ $isMeta ? __('connectors.meta_action_deactivate') : __('connectors.action_deactivate') }}
                </button>
            </form>
            <p class="text-xs text-gray-500 mt-2">{{ __('connectors.confirm_deactivate') }}</p>
        </section>
    @endif
</div>
@endsection

@push('scripts')
<script>
// "Copy" buttons (feed URL): clipboard API, with a selection fallback for plain http sites.
document.querySelectorAll('[data-copy-target]').forEach((button) => {
    const label = button.querySelector('[data-copy-label]');
    const original = label.textContent;
    button.addEventListener('click', async () => {
        const input = document.getElementById(button.dataset.copyTarget);
        let copied = false;
        try {
            await navigator.clipboard.writeText(input.value);
            copied = true;
        } catch (error) {
            input.select();
            copied = document.execCommand('copy');
        }
        if (copied) {
            label.textContent = button.dataset.copied;
            button.classList.add('text-emerald-400');
            setTimeout(() => {
                label.textContent = original;
                button.classList.remove('text-emerald-400');
            }, 2000);
        }
    });
});
</script>
@endpush
