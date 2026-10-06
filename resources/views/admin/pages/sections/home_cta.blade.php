@php $sectionData = $contentJson['sections'][$sectionKey] ?? []; @endphp
@include('admin.partials._section-copy', ['preset' => 'short-description'])
<div data-field-wrapper>
    <label class="block text-xs font-medium text-gray-500 mb-1">{{ __('admin.field_button_label') }}</label>
    <input type="text" name="content_json[sections][{{ $sectionKey }}][button_label]" value="{{ $sectionData['button_label'] ?? '' }}"
           class="w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 focus:ring-2 focus:ring-sky-500/50">
</div>
@include('admin.partials._link-destination', [
    'pageName' => "content_json[sections][{$sectionKey}][button_page]", 'pageValue' => $sectionData['button_page'] ?? null,
    'urlName' => "content_json[sections][{$sectionKey}][button_url]", 'urlValue' => $sectionData['button_url'] ?? null,
    'newTabName' => "content_json[sections][{$sectionKey}][button_new_tab]", 'newTabValue' => $sectionData['button_new_tab'] ?? false,
    'pages' => \App\Support\LinkDestination::pageOptions(),
])
