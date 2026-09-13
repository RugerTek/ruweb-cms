@extends('admin.layouts.app')

@section('title', __('admin.header'))

@section('content')
<div class="mb-6 flex items-start justify-between gap-4">
    <div>
        <h1 class="text-lg font-semibold text-white">{{ __('admin.header') }}</h1>
        <p class="text-sm text-gray-500 mt-0.5">{{ __('admin.header_subtitle') }}</p>
    </div>
    <button type="submit" form="header-form"
            class="shrink-0 px-4 py-2 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
        {{ __('admin.btn_save_changes') }}
    </button>
</div>

@php
    $ctaEnabled = (bool) old('settings.header_cta_enabled', $settings->get('header_cta_enabled')?->value);
@endphp

<form id="header-form" method="POST" action="{{ route('admin.header.update') }}" class="space-y-4">
    @csrf
    @method('PUT')

    {{-- Logo: the same site_logo as Settings > General, edited from both places (docs/layout-header-footer.md). --}}
    @if ($logoSetting)
        @include('admin.settings._field', [
            'setting' => $logoSetting,
            'label' => __('admin.layout_logo_label'),
            'help' => __('admin.layout_logo_help'),
        ])
    @endif

    {{-- Call to action: a switch reveals the button fields only while it is on. --}}
    <div class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-4 space-y-4">
        <label class="flex items-center justify-between gap-4 cursor-pointer">
            <span>
                <span class="block text-sm font-medium text-gray-300">{{ __('admin.settings_fields.header_cta_enabled.label') }}</span>
                <span class="block text-xs text-gray-500 mt-0.5">{{ __('admin.settings_fields.header_cta_enabled.help') }}</span>
            </span>
            <span class="relative inline-flex items-center shrink-0">
                <input type="hidden" name="settings[header_cta_enabled]" value="0">
                <input type="checkbox" name="settings[header_cta_enabled]" value="1" @checked($ctaEnabled)
                       data-toggle-controls="header-cta" class="sr-only peer">
                <span class="w-9 h-5 bg-gray-700 peer-focus:ring-2 peer-focus:ring-sky-500/50 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-sky-600"></span>
            </span>
        </label>

        <div data-toggle-target="header-cta" class="space-y-4 border-t border-white/[0.06] pt-4 {{ $ctaEnabled ? '' : 'hidden' }}">
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-1" for="header-cta-label">{{ __('admin.settings_fields.header_cta_label.label') }}</label>
                <input type="text" id="header-cta-label" name="settings[header_cta_label]"
                       value="{{ old('settings.header_cta_label', $settings->get('header_cta_label')?->value) }}"
                       maxlength="60"
                       class="w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50"
                       placeholder="{{ __('admin.settings_fields.header_cta_label.help') }}">
                @error('settings.header_cta_label')
                    <p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>
                @enderror
            </div>

            @include('admin.partials._link-destination', [
                'label' => __('admin.settings_fields.header_cta_page.label'),
                'pageName' => 'settings[header_cta_page]',
                'pageValue' => $settings->get('header_cta_page')?->value,
                'urlName' => 'settings[header_cta_url]',
                'urlValue' => $settings->get('header_cta_url')?->value,
                'newTabName' => 'settings[header_cta_new_tab]',
                'newTabValue' => (bool) old('settings.header_cta_new_tab', $settings->get('header_cta_new_tab')?->value),
                'pages' => $pages,
            ])
            @error('settings.header_cta_page')
                <p class="text-xs text-red-400">{{ $message }}</p>
            @enderror
            @error('settings.header_cta_url')
                <p class="text-xs text-red-400">{{ $message }}</p>
            @enderror
        </div>
    </div>
</form>

{{-- Parts of the header that are edited elsewhere: no duplicated forms, just the way there. --}}
<div class="mt-8">
    <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">{{ __('admin.layout_shortcuts_title') }}</h2>
    <div class="space-y-3">
        @include('admin.layout._shortcut-card', [
            'title' => __('admin.header_links_title'),
            'description' => __('admin.header_links_description'),
            'badge' => $menu?->name,
            'href' => $menu ? route('admin.menus.edit', $menu) : route('admin.menus.index'),
            'cta' => $menu ? __('admin.layout_edit_menu') : __('admin.layout_create_menu'),
        ])
        @include('admin.layout._shortcut-card', [
            'title' => __('admin.layout_contact_title'),
            'description' => __('admin.layout_contact_description'),
            'badge' => implode(' · ', $contactSummary) ?: null,
            'href' => route('admin.settings.index', ['tab' => 'contact']),
            'cta' => __('admin.layout_edit_contact'),
        ])
    </div>
</div>
@endsection
