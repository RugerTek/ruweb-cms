@extends('admin.layouts.app')

@section('title', __('shop.new_product'))

@section('content')
<div class="mb-6">
    <div class="flex items-center gap-2 text-sm text-gray-500 mb-2">
        <a href="{{ $editorBackHref }}" class="hover:text-gray-300 transition-colors">{{ $editorBackTitle }}</a>
        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        <span class="text-gray-400">{{ __('shop.new_product') }}</span>
    </div>
    <h1 class="text-lg font-semibold text-white">{{ __('shop.new_product') }}</h1>
</div>

<div class="max-w-4xl">
    @include('admin.products._form', ['product' => null])
</div>
@endsection
