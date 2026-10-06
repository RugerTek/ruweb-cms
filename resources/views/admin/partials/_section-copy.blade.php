@php $sectionData = $contentJson['sections'][$sectionKey] ?? []; @endphp
@include('admin.partials._editorial-title', [
    'name' => "content_json[sections][{$sectionKey}][heading]", 'value' => $sectionData['heading'] ?? '',
])
<div data-field-wrapper>
    @php $bodyId = 'body-'.md5($sectionKey); @endphp
    <label for="{{ $bodyId }}" class="block text-xs font-medium text-gray-500 mb-1">{{ __('admin.field_section_body') }}</label>
    <textarea id="{{ $bodyId }}" name="content_json[sections][{{ $sectionKey }}][body]" data-rich-text="{{ $preset ?? 'section-body' }}" rows="4"
              class="w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2">{{ $sectionData['body'] ?? '' }}</textarea>
</div>
