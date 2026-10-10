@extends('admin.layouts.app')
@section('title', __('custom_code.title'))
@section('content')
<div class="flex justify-between items-center mb-6">
    <h1 class="text-lg font-semibold text-white">{{ __('custom_code.title') }}</h1>
    <a href="{{ route('admin.custom-code.create') }}" class="px-4 py-2 rounded-md bg-sky-600 text-white">{{ __('custom_code.create') }}</a>
</div>
<p class="text-sm text-amber-300 mb-5">{{ __('custom_code.warning') }}</p>
<div class="rounded-xl bg-[#141414] p-5 space-y-4">
    @forelse($result['snippets'] as $snippet)
        <div class="flex justify-between gap-4 border-b border-white/10 pb-3">
            <a href="{{ route('admin.custom-code.edit', $snippet['id']) }}" class="text-sky-400">{{ $snippet['name'] }}</a>
            <span class="text-sm text-gray-400">{{ __('custom_code.state_'.$snippet['state']) }}</span>
        </div>
    @empty
        <p class="text-gray-400">{{ __('custom_code.empty') }}</p>
    @endforelse
</div>
@if($result['last_page'] > 1)
    <nav class="flex gap-3 mt-4" aria-label="{{ __('custom_code.title') }}">
        @for($page = 1; $page <= $result['last_page']; $page++)
            <a href="{{ route('admin.custom-code.index', ['page' => $page]) }}" class="text-sky-400" @if($page === $result['page']) aria-current="page" @endif>{{ $page }}</a>
        @endfor
    </nav>
@endif
@endsection
