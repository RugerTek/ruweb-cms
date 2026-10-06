@extends('admin.layouts.app')

@section('title', __('admin.editor_locked_title'))

@section('content')
    <div class="max-w-lg mx-auto p-6 rounded-lg border border-amber-500/20 bg-amber-500/10">
        <h1 class="text-lg font-semibold mb-3">{{ __('admin.editor_locked_title') }}</h1>
        <p class="text-sm mb-2">{{ $message }}</p>
        <p class="text-sm text-gray-400 mb-5">{{ __('admin.editor_locked_help') }}</p>
        <div class="flex gap-4 text-sm">
            <a href="{{ request()->fullUrl() }}" class="text-sky-400">{{ __('admin.editor_retry') }}</a>
            <a href="{{ $backUrl }}" class="text-gray-300">{{ __('admin.back_to_previous_page') }}</a>
        </div>
    </div>
@endsection
