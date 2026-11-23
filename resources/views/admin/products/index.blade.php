@extends('admin.layouts.app')

@section('title', __('shop.products'))

@section('content')
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6 gap-4">
    <h1 class="text-lg font-semibold text-white">{{ __('shop.products') }}</h1>

    @can('create', \App\Models\Product::class)
        <a href="{{ route('admin.products.create') }}" class="inline-flex items-center gap-2 px-3 py-1.5 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            {{ __('shop.new_product') }}
        </a>
    @endcan
</div>

<div class="flex gap-1 mb-6 border-b border-white/[0.06]">
    @foreach(['' => ['shop.all', $totalCount], 'published' => ['shop.published', $publishedCount], 'draft' => ['shop.draft', $draftCount]] as $key => [$label, $count])
        <a href="{{ route('admin.products.index', array_filter(['status' => $key, 'brand' => request('brand')])) }}"
           class="px-3 py-2 text-sm font-medium border-b-2 transition-colors {{ (string) $currentStatus === (string) $key ? 'border-sky-500 text-sky-400' : 'border-transparent text-gray-500 hover:text-gray-300' }}">
            {{ __($label) }} <span class="text-xs text-gray-600 ml-1">{{ $count }}</span>
        </a>
    @endforeach
</div>

<div class="mb-6">
    <form method="GET" class="flex flex-col sm:flex-row gap-2">
        @if($currentStatus)
            <input type="hidden" name="status" value="{{ $currentStatus }}">
        @endif
        <label for="product-search" class="sr-only">{{ __('shop.search') }}</label>
        <input type="text" id="product-search" name="search" value="{{ request('search') }}" placeholder="{{ __('shop.search_placeholder') }}"
               class="flex-1 bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50">
        @if($brands->isNotEmpty())
            <label for="product-brand" class="sr-only">{{ __('shop.filter_by_brand') }}</label>
            <select id="product-brand" name="brand" class="bg-[#1a1a1a] border border-white/10 text-gray-300 text-sm rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-500/50">
                <option value="">{{ __('shop.brands') }} · {{ __('shop.filter_all') }}</option>
                @foreach($brands as $brand)
                    <option value="{{ $brand->id }}" @selected((string) request('brand') === (string) $brand->id)>{{ $brand->name }}</option>
                @endforeach
            </select>
        @endif
        <button type="submit" class="px-3 py-2 bg-gray-800 border border-white/10 text-gray-400 text-sm rounded-md hover:bg-gray-700 transition-colors">
            {{ __('shop.search') }}
        </button>
    </form>
</div>

@if($products->isEmpty())
    <div class="text-center py-20 text-gray-600">
        <svg class="w-12 h-12 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
        </svg>
        <p class="text-sm font-medium text-gray-500">{{ __('shop.no_products_yet') }}</p>
        <p class="text-sm text-gray-600 mt-1">{{ __('shop.no_products_hint') }}</p>
        @can('create', \App\Models\Product::class)
            <a href="{{ route('admin.products.create') }}" class="mt-4 inline-flex items-center gap-2 px-3 py-1.5 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
                {{ __('shop.new_product') }}
            </a>
        @endcan
    </div>
@else
    <div class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl overflow-hidden">
        <table class="w-full">
            <thead>
                <tr class="border-b border-white/[0.06]">
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('shop.col_title') }}</th>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3 hidden sm:table-cell">{{ __('shop.col_price') }}</th>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3 hidden lg:table-cell">{{ __('shop.col_stock') }}</th>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3 hidden md:table-cell">{{ __('admin.language') }}</th>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('shop.col_status') }}</th>
                    <th class="text-right text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('shop.col_actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/[0.04]">
                @foreach($products as $product)
                    @php
                        $price = $product->displayPrice();
                        $stock = $product->has_variants ? $product->variants->sum('stock') : $product->stock;
                    @endphp
                    <tr class="hover:bg-white/[0.02] transition-colors">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.products.edit', $product) }}" class="text-sm text-white hover:text-sky-400 transition-colors">{{ $product->title }}</a>
                            <p class="text-xs text-gray-600 mt-0.5">
                                {{ $product->url() }}
                                @if($product->sku) · {{ __('shop.col_sku') }} {{ $product->sku }} @endif
                                @if($product->has_variants) · {{ __('shop.variants') }}: {{ $product->variants->count() }} @endif
                            </p>
                        </td>
                        <td class="px-4 py-3 hidden sm:table-cell">
                            <span class="text-sm text-gray-400">
                                @if($price === null)
                                    —
                                @elseif($product->has_variants)
                                    {{ __('shop.from_price', ['price' => \App\Helpers\MoneyHelper::format($price)]) }}
                                @else
                                    {{ \App\Helpers\MoneyHelper::format($price) }}
                                @endif
                            </span>
                        </td>
                        <td class="px-4 py-3 hidden lg:table-cell">
                            <span class="text-sm text-gray-500">{{ $product->track_stock ? $stock : '—' }}</span>
                        </td>
                        <td class="px-4 py-3 hidden md:table-cell">
                            <div class="flex items-center gap-1">
                                @foreach(($locales ?? collect()) as $locale)
                                    @php
                                        $translation = $locale->code === $product->locale ? $product : $product->translations->firstWhere('locale', $locale->code);
                                        $state = $translation ? $product->derivedTranslationState($locale->code) : 'missing';
                                        $badgeColor = $state === 'published'
                                            ? 'bg-sky-500/10 text-sky-400'
                                            : ($state === 'missing' ? 'bg-white/[0.04] text-gray-600' : 'bg-yellow-500/10 text-yellow-400');
                                    @endphp
                                    @if($translation)
                                        <a href="{{ route('admin.products.edit', $translation) }}"
                                           title="{{ __('admin.edit_translation', ['lang' => $locale->name]) }}"
                                           class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium transition-colors hover:ring-1 hover:ring-white/20 {{ $badgeColor }}">
                                            {{ strtoupper($locale->code) }}
                                        </a>
                                    @else
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium {{ $badgeColor }}">{{ strtoupper($locale->code) }}</span>
                                    @endif
                                @endforeach
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            @if($product->status === 'published')
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-sky-500/10 text-sky-400">{{ __('shop.badge_published') }}</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-500/10 text-yellow-400">{{ __('shop.badge_draft') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if($product->isPublished())
                                    <a href="{{ $product->url() }}" target="_blank" rel="noopener" class="text-gray-600 hover:text-gray-400 transition-colors" title="{{ __('admin.action_view') }}">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                        </svg>
                                    </a>
                                @endif
                                <a href="{{ route('admin.products.edit', $product) }}" class="text-gray-600 hover:text-gray-400 transition-colors" title="{{ __('admin.action_edit') }}">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                </a>
                                @can('delete', $product)
                                    <form method="POST" action="{{ route('admin.products.destroy', $product) }}" data-confirm="{{ __('shop.confirm_delete_product') }}" data-confirm-label="{{ __('shop.delete_product') }}" class="flex items-center">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="flex items-center text-gray-600 hover:text-red-400 transition-colors" title="{{ __('shop.delete_product') }}">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                        </button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-6">
        {{ $products->links() }}
    </div>
@endif
@endsection
