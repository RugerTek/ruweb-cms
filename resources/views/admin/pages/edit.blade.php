@extends('admin.layouts.editor')

@section('editor-title', $page->title)

@section('editor-actions')
    <a href="{{ route('admin.editorial.index',['pages',$page->id]) }}" class="text-xs text-gray-400">{{ __('editorial.history') }}</a>
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
    @php($recoveryConfig = ['url'=>route('admin.editorial.draft',['pages',$page->id]),'fingerprint'=>app(\App\Services\Editorial\EditorialHistory::class)->fingerprint($page),'user'=>auth()->id(),'message'=>__('editorial.recovery'),'conflict'=>__('editorial.recovery_conflict'),'recover'=>__('editorial.recover'),'discard'=>__('editorial.discard')])
    initEditorEngine({
        recovery: @json($recoveryConfig),
        previewUrl: '{{ route('admin.pages.preview', $page) }}',
        stagedMsg: @json(__('editorial.staged')),
        savedMsg: '{{ __('admin.saved_success') }}',
        errorMsg: '{{ __('admin.save_error') }}',
    });
});
</script>
@endpush
