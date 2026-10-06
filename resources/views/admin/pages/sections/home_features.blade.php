@include('admin.partials._section-copy', ['preset' => 'section-body'])
@php
    $sectionData = $contentJson['sections'][$sectionKey] ?? [];
    $logos = array_values(array_filter($sectionData['logos'] ?? [], fn ($logo) => filled($logo['image_id'] ?? null) || filled($logo['label'] ?? null)));
@endphp

{{-- Logo carousel: administrable repeater, one nested accordion per item --}}
<div class="logo-repeater border-t border-white/[0.08] pt-4"
     data-template-only="home"
     data-logo-repeater
     data-item-label="{{ __('admin.logo_item_fallback_label') }}">
    <div class="min-w-0 mb-3">
        <h4 class="text-xs font-semibold text-white uppercase tracking-wide">{{ __('admin.field_section_logos') }}</h4>
        <p class="text-xs text-gray-600 mt-0.5">{{ __('admin.field_section_logos_help') }}</p>
    </div>

    <input type="hidden" name="content_json[sections][{{ $sectionKey }}][logos]" value="" data-empty-repeater>
    <div data-logo-list class="space-y-2">
        @foreach ($logos as $index => $logo)
            @include('admin.pages.sections._logo-repeater-item', [
                'sectionKey' => $sectionKey,
                'index' => $index,
                'logo' => $logo,
            ])
        @endforeach
    </div>

    <p data-logo-empty class="text-xs text-gray-600 py-3 {{ count($logos) ? 'hidden' : '' }}">
        {{ __('admin.field_section_logos_empty') }}
    </p>

    <button type="button" data-logo-add
            class="mt-3 inline-flex items-center gap-2 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-md transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        {{ __('admin.btn_add_logo') }}
    </button>

    <template data-logo-template>
        @include('admin.pages.sections._logo-repeater-item', [
            'sectionKey' => $sectionKey,
            'index' => '__INDEX__',
            'logo' => [],
        ])
    </template>
</div>
