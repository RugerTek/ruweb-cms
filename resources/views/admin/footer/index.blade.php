@extends('admin.layouts.app')

@section('title', __('admin.footer'))

@section('content')
<div class="mb-6 flex items-start justify-between gap-4">
    <div>
        <h1 class="text-lg font-semibold text-white">{{ __('admin.footer') }}</h1>
        <p class="text-sm text-gray-500 mt-0.5">{{ __('admin.footer_subtitle') }}</p>
    </div>
    <button type="submit" form="footer-form"
            class="shrink-0 px-4 py-2 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
        {{ __('admin.btn_save_changes') }}
    </button>
</div>

@php
    $creditEnabled = (bool) old('settings.footer_credit_enabled', $settings->get('footer_credit_enabled')?->value);
@endphp

<form id="footer-form" method="POST" action="{{ route('admin.footer.update') }}" class="space-y-4">
    @csrf
    @method('PUT')

    {{-- Logo: shared with the header and Settings > General (docs/admin/layout.md). --}}
    @if ($logoSetting)
        @include('admin.settings._field', [
            'setting' => $logoSetting,
            'label' => __('admin.layout_logo_label'),
            'help' => __('admin.layout_logo_help'),
        ])
    @endif

    @if ($settings->has('footer_tagline'))
        @include('admin.settings._field', ['setting' => $settings->get('footer_tagline')])
    @endif

    @if ($settings->has('footer_copyright'))
        @include('admin.settings._field', ['setting' => $settings->get('footer_copyright')])
    @endif

    {{-- Credit line ("Developed by …"): a switch reveals the rich-text field only while it is on. --}}
    <div class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-4 space-y-4">
        <label class="flex items-center justify-between gap-4 cursor-pointer">
            <span>
                <span class="block text-sm font-medium text-gray-300">{{ __('admin.settings_fields.footer_credit_enabled.label') }}</span>
                <span class="block text-xs text-gray-500 mt-0.5">{{ __('admin.settings_fields.footer_credit_enabled.help') }}</span>
            </span>
            <span class="relative inline-flex items-center shrink-0">
                <input type="hidden" name="settings[footer_credit_enabled]" value="0">
                <input type="checkbox" name="settings[footer_credit_enabled]" value="1" @checked($creditEnabled)
                       data-toggle-controls="footer-credit" class="sr-only peer">
                <span class="w-9 h-5 bg-gray-700 peer-focus:ring-2 peer-focus:ring-sky-500/50 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-sky-600"></span>
            </span>
        </label>

        <div data-toggle-target="footer-credit" class="border-t border-white/[0.06] pt-4 {{ $creditEnabled ? '' : 'hidden' }}">
            @if ($settings->has('footer_credit'))
                <label class="block text-sm font-medium text-gray-300 mb-1">{{ __('admin.settings_fields.footer_credit.label') }}</label>
                <p class="text-xs text-gray-500 mb-2">{{ __('admin.settings_fields.footer_credit.help') }}</p>
                <textarea name="settings[footer_credit]" rows="2" data-rich-text="short-description"
                          class="w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2">{{ old('settings.footer_credit', $settings->get('footer_credit')?->value) }}</textarea>
                @error('settings.footer_credit')
                    <p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>
                @enderror
            @endif
        </div>
    </div>
</form>

<div class="mt-8">
    <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">{{ __('admin.layout_shortcuts_title') }}</h2>
    <div class="space-y-3">
        @include('admin.layout._shortcut-card', [
            'title' => __('admin.footer_links_title'),
            'description' => __('admin.footer_links_description'),
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
        @include('admin.layout._shortcut-card', [
            'title' => __('admin.footer_social_title'),
            'description' => __('admin.footer_social_description'),
            'badge' => $socialCount ? trans_choice('admin.social_links_count', $socialCount, ['count' => $socialCount]) : null,
            'href' => route('admin.settings.index', ['tab' => 'contact']),
            'cta' => __('admin.layout_edit_social'),
        ])
    </div>
</div>
@endsection
