@extends('admin.layouts.app')

@section('title', $product->title)

@section('content')
<div class="mb-6 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
    <div>
        <div class="flex items-center gap-2 text-sm text-gray-500 mb-2">
            <a href="{{ $editorBackHref }}" class="hover:text-gray-300 transition-colors">{{ $editorBackTitle }}</a>
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-gray-400">{{ __('shop.edit_product') }}</span>
        </div>
        <h1 class="text-lg font-semibold text-white">{{ $product->title }}</h1>
    </div>
    <div class="flex items-center gap-3 flex-none pt-1">
        {{-- Versions, pending proposals, reviews and shareable preview links live on the shared history screen, as for posts. --}}
        <a href="{{ route('admin.editorial.index', ['products', $product->id]) }}" class="text-xs text-gray-400 hover:text-gray-200">{{ __('editorial.history') }}</a>
        @include('admin.partials._language-switcher', [
            'model' => $product,
            'locales' => $locales,
            'editRoute' => 'admin.products.edit',
            'translateRoute' => 'admin.products.translate',
            'returnForm' => '#product-form',
        ])
        @if($product->isPublished())
            <a href="{{ $product->url() }}" target="_blank" class="text-xs text-gray-500 hover:text-gray-300 transition-colors hidden sm:inline">{{ __('admin.view_live') }}</a>
        @endif
    </div>
</div>

<div class="max-w-4xl">
    @include('admin.products._form', ['product' => $product])
</div>

@can('delete', $product)
    <form method="POST" action="{{ route('admin.products.destroy', $product) }}" class="max-w-4xl mt-8 pt-6 border-t border-white/[0.06]"
          data-confirm="{{ __('shop.confirm_delete_product') }}" data-confirm-label="{{ __('shop.delete_product') }}">
        @csrf
        @method('DELETE')
        <button type="submit" class="px-3 py-2 bg-red-500/10 hover:bg-red-500/20 text-red-400 text-sm font-medium rounded-md transition-colors">{{ __('shop.delete_product') }}</button>
    </form>
@endcan
@endsection
