{{--
    Administrable list of social links for a `social_links` setting
    (docs/layout-header-footer.md). Uses the generic repeater from
    resources/js/admin.js. Each row is a network selector plus its URL, laid
    out flat because the pair is one link (see "Typed Single-Value Items" in
    docs/live-editor-ui-ux.md). The client adds only the networks the business
    actually has; nothing is mandatory.
--}}
@php
    $rows = old('settings.' . $setting->key);
    if (! is_array($rows)) {
        $decoded = json_decode((string) $setting->value, true);
        $rows = is_array($decoded) ? $decoded : [];
    }
    $rows = array_values(array_filter($rows, 'is_array'));
@endphp
<div data-repeater
     data-repeater-key="{{ $setting->key }}"
     data-item-label="{{ __('admin.social_link_fallback_label') }}"
     data-confirm-remove="{{ __('admin.confirm_remove_social_link') }}"
     data-confirm-label="{{ __('admin.remove') }}">

    <div data-repeater-list class="space-y-2">
        @foreach ($rows as $index => $row)
            @include('admin.settings._social-link-item', ['key' => $setting->key, 'index' => $index, 'row' => $row])
        @endforeach
    </div>

    <p data-repeater-empty class="text-xs text-gray-600 py-3 {{ count($rows) ? 'hidden' : '' }}">{{ __('admin.social_links_empty') }}</p>

    <button type="button" data-repeater-add class="mt-3 inline-flex items-center gap-2 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-md transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        {{ __('admin.btn_add_social_link') }}
    </button>

    <template data-repeater-template>
        @include('admin.settings._social-link-item', ['key' => $setting->key, 'index' => '__INDEX__', 'row' => []])
    </template>
</div>
