@extends('admin.layouts.app')

@section('title', __('admin.new_page_title'))

@section('content')
<div class="mb-6 flex items-start justify-between gap-4">
    <div>
        <div class="flex items-center gap-2 text-sm text-gray-500 mb-2">
            <a href="{{ route('admin.pages.index') }}" class="hover:text-gray-300 transition-colors">{{ __('admin.pages') }}</a>
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-gray-400">{{ __('admin.new_page_title') }}</span>
        </div>
        <h1 class="text-lg font-semibold text-white">{{ __('admin.new_page_title') }}</h1>
    </div>
    <div class="hidden sm:flex items-center gap-3 flex-none pt-1">
        <button type="button" id="save-draft-btn"
                class="px-3 py-1.5 bg-white/5 hover:bg-white/10 text-gray-300 text-sm font-medium rounded-md transition-colors">
            {{ __('admin.btn_save_draft') }}
        </button>
        <button type="button" id="publish-btn"
                class="px-3 py-1.5 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
            {{ __('admin.btn_publish') }}
        </button>
    </div>
</div>

@include('admin.pages._form', ['page' => null, 'templates' => $templates])

<div class="sm:hidden mt-6 flex flex-col gap-3">
    <button type="button" id="publish-btn-mobile"
            class="w-full px-4 py-2.5 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
        {{ __('admin.btn_publish') }}
    </button>
    <button type="button" id="save-draft-btn-mobile"
            class="w-full px-4 py-2.5 bg-white/5 hover:bg-white/10 text-gray-300 text-sm font-medium rounded-md transition-colors">
        {{ __('admin.btn_save_draft') }}
    </button>
</div>

@push('scripts')
<script>
    function submitWithStatus(status) {
        document.getElementById('status').value = status;
        document.getElementById('editor-form').requestSubmit();
    }
    ['publish-btn', 'publish-btn-mobile'].forEach(id => {
        document.getElementById(id).addEventListener('click', () => submitWithStatus('published'));
    });
    ['save-draft-btn', 'save-draft-btn-mobile'].forEach(id => {
        document.getElementById(id).addEventListener('click', () => submitWithStatus('draft'));
    });
</script>
@endpush
@endsection
