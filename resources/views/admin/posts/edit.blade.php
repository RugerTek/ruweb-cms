@extends('admin.layouts.editor')

@section('editor-title', $post->title)

@section('editor-actions')
    <a href="{{ route('admin.editorial.index',['posts',$post->id]) }}" class="text-xs text-gray-400">{{ __('editorial.history') }}</a>
    @include('admin.partials._language-switcher', [
        'model' => $post,
        'locales' => $locales,
        'editRoute' => 'admin.posts.edit',
        'translateRoute' => 'admin.posts.translate',
    ])

    @if ($post->isPublished())
        <a href="{{ $post->url() }}" target="_blank"
           class="text-xs text-gray-500 hover:text-gray-300 transition-colors hidden sm:inline">
            {{ __('admin.view_live') }}
        </a>
    @endif
@endsection

@section('editor-form')
    @include('admin.posts._form', ['post' => $post])
@endsection

@section('editor-footer')
    @include('admin.partials._editor-footer', ['model' => $post])
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    @php($recoveryConfig = ['url'=>route('admin.editorial.draft',['posts',$post->id]),'fingerprint'=>app(\App\Services\Editorial\EditorialHistory::class)->fingerprint($post),'user'=>auth()->id(),'message'=>__('editorial.recovery'),'conflict'=>__('editorial.recovery_conflict'),'recover'=>__('editorial.recover'),'discard'=>__('editorial.discard')])
    initEditorEngine({
        recovery: @json($recoveryConfig),
        previewUrl: '{{ route('admin.posts.preview', $post) }}',
        stagedMsg: @json(__('editorial.staged')),
        savedMsg: '{{ __('admin.saved_success') }}',
        errorMsg: '{{ __('admin.save_error') }}',
    });
});
</script>
@endpush
