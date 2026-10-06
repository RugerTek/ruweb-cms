@php $sectionData = $contentJson['sections'][$sectionKey] ?? []; @endphp
@include('admin.partials._section-copy', ['preset' => 'short-description'])

<div>
    @include('admin.media._selector', [
        'name'  => "content_json[sections][{$sectionKey}][image_id]",
        'value' => $sectionData['image_id'] ?? null,
        'label' => __('admin.field_section_background_image'),
        'type'  => 'image',
    ])
</div>

