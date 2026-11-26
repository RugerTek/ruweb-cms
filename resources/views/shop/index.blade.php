@extends('layouts.public')

@section('content')
@php
    $shopIndexHref = app()->getLocale() === \App\Models\Locale::baseCode()
        ? route('shop.index')
        : route('localized.shop.index', ['locale' => app()->getLocale()]);
@endphp
<section class="py-14 sm:py-16">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="mb-8 flex flex-col gap-5 md:flex-row md:items-end md:justify-between">
            <div>
                <h1 class="text-3xl sm:text-4xl font-semibold tracking-tight text-gray-900">{{ __('shop.catalog_title') }}</h1>
                <p class="mt-2 text-gray-600">{{ __('shop.catalog_subtitle') }}</p>
            </div>
            <form method="GET" action="{{ $shopIndexHref }}" class="flex w-full items-center gap-2 md:w-80" role="search">
                @foreach($activeFilters as $type => $slug)
                    <input type="hidden" name="{{ $type }}" value="{{ $slug }}">
                @endforeach
                <label for="shop-search" class="sr-only">{{ __('shop.search_products') }}</label>
                <input
                    id="shop-search"
                    type="search"
                    name="q"
                    value="{{ $search }}"
                    placeholder="{{ __('shop.search_products_placeholder') }}"
                    class="min-w-0 flex-1 rounded-md border border-gray-200 px-3 py-2 text-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-500/20">
                <button type="submit" class="rounded-md bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-gray-800">
                    {{ __('shop.search') }}
                </button>
            </form>
        </div>

        @foreach($filters as $type => $terms)
            <nav class="mb-6 flex flex-wrap items-center gap-2" aria-label="{{ \Illuminate\Support\Facades\Lang::has('shop.filter_by_'.$type) ? __('shop.filter_by_'.$type) : $type }}">
                @if(\Illuminate\Support\Facades\Lang::has('shop.filter_by_'.$type))
                    <span class="mr-1 text-sm text-gray-500">{{ __('shop.filter_by_'.$type) }}:</span>
                @endif
                <a href="{{ request()->fullUrlWithoutQuery([$type, 'page']) }}"
                   @class(['rounded-full border px-3 py-1 text-sm transition-colors', 'border-gray-900 bg-gray-900 text-white' => ! isset($activeFilters[$type]), 'border-gray-200 text-gray-700 hover:border-gray-400' => isset($activeFilters[$type])])
                   @if(! isset($activeFilters[$type])) aria-current="true" @endif>
                    {{ __('shop.filter_all') }}
                </a>
                @foreach($terms as $term)
                    @php $isActive = ($activeFilters[$type] ?? null) === $term->slug; @endphp
                    <a href="{{ request()->fullUrlWithQuery([$type => $term->slug, 'page' => null]) }}"
                       @class(['rounded-full border px-3 py-1 text-sm transition-colors', 'border-gray-900 bg-gray-900 text-white' => $isActive, 'border-gray-200 text-gray-700 hover:border-gray-400' => ! $isActive])
                       @if($isActive) aria-current="true" @endif>
                        {{ $term->name }}
                    </a>
                @endforeach
            </nav>
        @endforeach

        @if($products->isEmpty())
            <div class="rounded-xl border border-gray-200 p-8 text-center text-gray-500">
                {{ ($activeFilters || $search !== '') ? __('shop.no_products_found') : __('shop.catalog_empty') }}
            </div>
        @else
            <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
                @foreach($products as $product)
                    @php
                        $commerce = $product->isBaseLocale() ? $product : ($product->baseTranslation() ?? $product);
                        $displayPrice = $commerce->displayPrice();
                    @endphp
                    <article class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                        @if($product->featuredImage())
                            <a href="{{ $product->url() }}" class="block bg-gray-50">
                                <x-responsive-img
                                    :media="$product->featuredImage()"
                                    sizes="(min-width: 1024px) 24rem, (min-width: 768px) 50vw, 100vw"
                                    :fallback-alt="$product->title"
                                    class="h-56 w-full object-cover"
                                />
                            </a>
                        @else
                            {{-- Same slot height as a photo, so a row mixing products with and without image stays even. --}}
                            <a href="{{ $product->url() }}" class="flex h-56 items-center justify-center bg-gray-50 text-gray-300" tabindex="-1" aria-hidden="true" data-shop-image-placeholder>
                                <svg class="h-10 w-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                                </svg>
                            </a>
                        @endif
                        <div class="p-5">
                            <div class="flex items-start justify-between gap-4">
                                <h2 class="text-lg font-semibold leading-snug text-gray-900">
                                    <a href="{{ $product->url() }}" class="hover:text-sky-700 transition-colors">{{ $product->title }}</a>
                                </h2>
                                @if($displayPrice)
                                    <p class="shrink-0 text-right text-sm font-semibold text-gray-900">
                                        {{ $commerce->has_variants ? __('shop.from_price', ['price' => $displayPrice->format()]) : $displayPrice->format() }}
                                    </p>
                                @endif
                            </div>
                            @if($product->excerpt)
                                <p class="mt-2 line-clamp-3 text-sm text-gray-600">{{ $product->excerpt }}</p>
                            @endif
                            <a href="{{ $product->url() }}" class="mt-4 inline-flex text-sm font-medium text-sky-700 hover:text-sky-800">
                                {{ __('shop.view_product') }} →
                            </a>
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="mt-10">
                {{ $products->links() }}
            </div>
        @endif
    </div>
</section>
@endsection
