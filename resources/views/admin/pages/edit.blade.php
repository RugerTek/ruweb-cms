@extends('admin.layouts.editor')

@section('editor-title', $page->title)

@section('editor-actions')
    @include('admin.partials._language-switcher', [
        'model' => $page,
        'locales' => $locales,
        'editRoute' => 'admin.pages.edit',
        'translateRoute' => 'admin.pages.translate',
    ])

    @if ($page->isPublished())
        <a href="{{ $page->url() }}" target="_blank"
           class="text-xs text-gray-500 hover:text-gray-300 transition-colors">
            {{ __('admin.view_live') }}
        </a>
    @endif
@endsection

@section('editor-form')
    @include('admin.pages._form', ['page' => $page, 'templates' => $templates])
@endsection

@section('editor-footer')
    @include('admin.partials._editor-footer', ['model' => $page])
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    initEditorEngine({
        previewUrl: '{{ route('admin.pages.preview', $page) }}',
        savedMsg: '{{ __('admin.saved_success') }}',
        errorMsg: '{{ __('admin.save_error') }}',
    });
});
</script>
@endpush
