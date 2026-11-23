@extends('layouts.public')

@section('content')
@php
    // Prices, stock and variants live on the base-language row of the translation group.
    $commerce = $product->isBaseLocale() ? $product : ($product->baseTranslation() ?? $product);
    $variants = $commerce->has_variants ? $commerce->variants : collect();
    $requestedVariant = request()->query(\App\Http\Controllers\ShopController::VARIANT_QUERY);
    $selectedVariant = is_string($requestedVariant) && ctype_digit($requestedVariant)
        ? $variants->firstWhere('id', (int) $requestedVariant)
        : null;
    $displayPrice = $selectedVariant ? $selectedVariant->price_amount : $commerce->displayPrice();
    $compareAtPrice = $selectedVariant ? $selectedVariant->compare_at_price_amount : ($commerce->has_variants ? null : $commerce->compare_at_price_amount);
    $sku = $selectedVariant?->sku ?: $commerce->sku;
    $stock = $selectedVariant ? $selectedVariant->stock : ($commerce->has_variants ? null : $commerce->stock);
    $showStock = $commerce->track_stock && (! $commerce->has_variants || $selectedVariant);
    $inStock = $commerce->allow_backorder || (int) $stock > 0;
    $gallery = $product->mediaByCollection('gallery')->get();
    $hasMedia = $product->featuredImage() || $gallery->isNotEmpty();
    $brands = $product->taxonomies->where('type', \App\Models\Product::BRAND_TAXONOMY);
    $shopIndexRoute = app()->getLocale() === \App\Models\Locale::baseCode() ? 'shop.index' : 'localized.shop.index';
    $shopIndexHref = \Illuminate\Support\Facades\Route::has($shopIndexRoute)
        ? route($shopIndexRoute, $shopIndexRoute === 'shop.index' ? [] : ['locale' => app()->getLocale()])
        : null;
@endphp
<article class="py-14 sm:py-16">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        @if($shopIndexHref)
            <a href="{{ $shopIndexHref }}" class="mb-8 inline-flex items-center text-sm text-gray-500 hover:text-gray-800 transition-colors">
                ← {{ __('shop.back_to_shop') }}
            </a>
        @endif

        @if($isEditorPreview ?? false)
            <p class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800" role="status" data-shop-preview-notice>
                {{ __('shop.preview_notice') }}
            </p>
        @endif

        {{-- Without images the details take the whole width instead of leaving an empty column. --}}
        <div @class(['grid grid-cols-1 gap-10', 'lg:grid-cols-2' => $hasMedia, 'max-w-3xl' => ! $hasMedia]) data-shop-layout="{{ $hasMedia ? 'media' : 'details' }}">
            @if($hasMedia)
            <div>
                @if($product->featuredImage())
                    <x-responsive-img
                        :media="$product->featuredImage()"
                        sizes="(min-width: 1024px) 50vw, 100vw"
                        :fallback-alt="$product->title"
                        class="w-full rounded-xl object-cover"
                    />
                @endif

                @if($gallery->isNotEmpty())
                    <div class="mt-4 grid grid-cols-3 gap-3">
                        @foreach($gallery as $media)
                            <x-responsive-img
                                :media="$media"
                                sizes="12rem"
                                :fallback-alt="$product->title"
                                class="aspect-square rounded-lg object-cover"
                            />
                        @endforeach
                    </div>
                @endif
            </div>
            @endif

            <div>
                @if($brands->isNotEmpty())
                    <p class="mb-2 text-sm text-gray-500">
                        <span class="sr-only">{{ __('shop.brand') }}:</span>{{ $brands->pluck('name')->join(', ') }}
                    </p>
                @endif
                <h1 class="text-3xl font-semibold tracking-tight text-gray-900 sm:text-4xl">{{ $product->title }}</h1>
                @if($sku)
                    <p class="mt-2 text-sm text-gray-500" data-shop-sku>{{ __('shop.field_sku') }}: {{ $sku }}</p>
                @endif
                @if($product->excerpt)
                    <p class="mt-4 text-lg text-gray-600">{{ $product->excerpt }}</p>
                @endif

                @if($displayPrice)
                    <div class="mt-6" data-shop-price>
                        <p class="text-2xl font-semibold text-gray-900">
                            {{ $commerce->has_variants && ! $selectedVariant ? __('shop.from_price', ['price' => $displayPrice->format()]) : $displayPrice->format() }}
                        </p>
                        @if($compareAtPrice && $compareAtPrice->amount > $displayPrice->amount)
                            <p class="mt-1 text-sm text-gray-400 line-through">{{ __('shop.compare_at_price_label', ['price' => $compareAtPrice->format()]) }}</p>
                        @endif
                    </div>
                @endif

                @if($variants->isNotEmpty())
                    <div class="mt-6">
                        <p class="text-sm font-medium text-gray-700">{{ __('shop.choose_variant') }}</p>
                        <ul class="mt-2 space-y-2">
                            @foreach($variants as $variant)
                                @php $isSelected = $selectedVariant?->id === $variant->id; @endphp
                                <li>
                                    <a href="{{ $product->url() }}?{{ \App\Http\Controllers\ShopController::VARIANT_QUERY }}={{ $variant->id }}"
                                       @class(['flex items-center justify-between rounded-lg border px-3 py-2 text-sm transition-colors', 'border-gray-900 bg-gray-50' => $isSelected, 'border-gray-200 hover:border-gray-400' => ! $isSelected])
                                       @if($isSelected) aria-current="true" @endif
                                       data-shop-variant="{{ $variant->id }}">
                                        <span class="font-medium text-gray-800">{{ $variant->name }}</span>
                                        @if($variant->price_amount)
                                            <span class="text-gray-700">{{ $variant->price_amount->format() }}</span>
                                        @endif
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                        @if($selectedVariant && $selectedVariant->attributes_json)
                            <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                                @foreach($selectedVariant->attributes_json as $attribute => $value)
                                    <dt class="text-gray-500">{{ $attribute }}</dt>
                                    <dd class="text-gray-800">{{ is_scalar($value) ? $value : json_encode($value) }}</dd>
                                @endforeach
                            </dl>
                        @endif
                    </div>
                @endif

                @if($showStock)
                    <div class="mt-6">
                        @if($inStock)
                            <span class="inline-flex rounded-full bg-sky-50 px-3 py-1 text-sm font-medium text-sky-700">{{ __('shop.in_stock') }}</span>
                        @else
                            <span class="inline-flex rounded-full bg-gray-100 px-3 py-1 text-sm font-medium text-gray-500">{{ __('shop.out_of_stock') }}</span>
                        @endif
                    </div>
                @endif

                @if($product->description)
                    <div class="prose prose-gray mt-10 max-w-none">
                        {!! \App\Helpers\ContentHelper::richText($product->description) !!}
                    </div>
                @endif
            </div>
        </div>
    </div>
</article>
@endsection
