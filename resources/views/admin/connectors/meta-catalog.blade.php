@extends('admin.layouts.app')

@section('title', __('connectors.meta_choose_catalog_title'))

{{--
    Catalog choice after «Conectar con Facebook» (plan-meta-v2, track 2b) when the client shared several `commerce`
    catalogs, or later from «Cambiar catálogo». Receives $catalogs (list of MetaCatalog, commerce only), $connection
    and $commerceManagerUrl from Admin\MetaConnectController::chooseCatalog().
--}}
@section('content')
@php
    $link = '<a href="'.e($commerceManagerUrl).'" target="_blank" rel="noopener noreferrer" class="text-sky-400 hover:text-sky-300 underline underline-offset-2">'.e(__('connectors.guide_link_commerce_manager')).'</a>';
    $hint = str_replace('@@link@@', $link, e(__('connectors.meta_choose_catalog_hint', ['link' => '@@link@@'])));
@endphp
<div class="mb-6">
    <a href="{{ route('admin.connectors.show', ['connector' => 'meta']) }}" class="inline-flex items-center gap-1 text-xs text-gray-500 hover:text-gray-300 transition-colors">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        {{ __('connectors.meta_label') }}
    </a>
    <h1 class="text-lg font-semibold text-white mt-2">{{ __('connectors.meta_choose_catalog_title') }}</h1>
</div>

@if($errors->any())
    <div class="mb-4 p-3.5 bg-red-500/10 border border-red-500/20 text-red-300 rounded-md text-sm" role="alert">
        @foreach($errors->all() as $error)
            <p>{{ $error }}</p>
        @endforeach
    </div>
@endif

<section class="max-w-2xl bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5 space-y-4">
    <p class="text-sm text-gray-300">{{ __('connectors.meta_choose_catalog_help') }}</p>
    @if($catalogs === [])
        <p class="text-sm text-yellow-300/90">{{ __('connectors.meta_no_catalog_title') }}</p>
    @else
        <form method="POST" action="{{ route('admin.connectors.meta.catalog.select') }}" class="space-y-3">
            @csrf
            <fieldset class="space-y-2">
                <legend class="sr-only">{{ __('connectors.meta_connected_catalog') }}</legend>
                @foreach($catalogs as $catalog)
                    <label class="flex items-center gap-3 p-3 rounded-md border border-white/10 hover:border-white/20 cursor-pointer" data-meta-catalog-option="{{ $catalog->id }}">
                        <input type="radio" name="catalog_id" value="{{ $catalog->id }}" class="text-sky-500 bg-[#1a1a1a] border-white/20" @checked($catalog->id === $connection->catalogId || ($connection->catalogId === null && $loop->first)) required>
                        <span class="flex-1 min-w-0">
                            <span class="block text-sm text-white">{{ $catalog->name }}</span>
                            @if($catalog->productCount !== null)
                                <span class="block text-xs text-gray-500">{{ trans_choice('connectors.meta_catalog_products', $catalog->productCount, ['count' => $catalog->productCount]) }}</span>
                            @endif
                        </span>
                    </label>
                @endforeach
            </fieldset>
            <button type="submit" class="inline-flex items-center gap-2 px-3 py-2 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">{{ __('connectors.meta_action_use_catalog') }}</button>
        </form>
    @endif
    <p class="text-xs text-gray-500">{!! $hint !!}</p>
</section>
@endsection
