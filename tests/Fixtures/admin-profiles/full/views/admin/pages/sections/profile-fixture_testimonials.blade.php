@php
    $sectionData = $contentJson['sections'][$sectionKey] ?? [];
    $items = array_values($sectionData['items'] ?? []);
@endphp
@include('admin.partials._editorial-title', [
    'name' => "content_json[sections][{$sectionKey}][heading]", 'value' => $sectionData['heading'] ?? '',
])
<div data-repeater data-repeater-key="items" data-item-label="{{ __('admin.testimonial_item') }}" data-confirm-remove="{{ __('admin.testimonial_confirm_remove') }}" data-confirm-label="{{ __('admin.remove') }}">
    <input type="hidden" name="content_json[sections][{{ $sectionKey }}][items]" value="" data-empty-repeater>
    <div data-repeater-list class="space-y-2">
        @foreach($items as $index => $item)
            @include('admin.pages.sections._testimonial-item', compact('sectionKey', 'index', 'item'))
        @endforeach
    </div>
    <p data-repeater-empty class="text-xs text-gray-500 {{ count($items) ? 'hidden' : '' }}">{{ __('admin.testimonial_empty') }}</p>
    <button type="button" data-repeater-add class="mt-3 inline-flex items-center gap-2 px-3 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-width="2" d="M12 4v16m8-8H4" /></svg>
        {{ __('admin.testimonial_add') }}
    </button>
    <template data-repeater-template>
        @include('admin.pages.sections._testimonial-item', ['sectionKey' => $sectionKey, 'index' => '__INDEX__', 'item' => []])
    </template>
</div>
