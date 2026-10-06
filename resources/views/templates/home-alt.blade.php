@extends('layouts.public')

@section('content')
@php
    $sections = $page->sections();
    $hero = $sections['hero'] ?? [];
    $features = $sections['features'] ?? [];
    $cta = $sections['cta'] ?? [];
    $heroBackground = isset($hero['image_id']) ? \App\Models\Media::find((int) $hero['image_id']) : null;
@endphp

@if(($hero['is_visible'] ?? 1) && ($hero['heading'] ?? null))
<section class="relative overflow-hidden py-20 sm:py-28 bg-gray-900">
    @if($heroBackground)
        <div class="absolute inset-0">
            <x-responsive-img
                :media="$heroBackground"
                sizes="100vw"
                alt=""
                class="h-full w-full object-cover"
            />
            <div class="absolute inset-0 bg-black/55"></div>
        </div>
    @endif
    <div class="relative max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        <h1 class="text-4xl sm:text-5xl font-bold text-white tracking-tight max-w-4xl">{!! \App\Support\EditorialTitle::render($hero['heading']) !!}</h1>
        @if($hero['body'] ?? null)
            <div class="mt-6 text-lg sm:text-xl text-gray-300 max-w-3xl">{!! \App\Support\SectionRichText::render($hero['body'], 'short-description') !!}</div>
        @endif
    </div>
</section>
@endif

@if(($features['is_visible'] ?? 1) && ($features['heading'] ?? $features['body'] ?? null))
<section class="py-16 sm:py-20 bg-white">
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        @if($features['heading'] ?? null)
            <h2 class="text-3xl font-bold text-gray-900 mb-4">{!! \App\Support\EditorialTitle::render($features['heading']) !!}</h2>
        @endif
        @if($features['body'] ?? null)
            <div class="prose prose-gray max-w-none">
                {!! \App\Support\SectionRichText::render($features['body'], 'section-body') !!}
            </div>
        @endif
    </div>
</section>
@endif

@if(($cta['is_visible'] ?? 1) && (($cta['heading'] ?? $cta['body'] ?? null) || ($cta['button_label'] ?? null)))
<section class="py-16 sm:py-20 bg-sky-600">
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        @if($cta['heading'] ?? null)
            <h2 class="text-3xl font-bold text-white mb-4">{!! \App\Support\EditorialTitle::render($cta['heading']) !!}</h2>
        @endif
        @if($cta['body'] ?? null)
            <div class="text-lg text-sky-100 max-w-3xl">{!! \App\Support\SectionRichText::render($cta['body'], 'short-description') !!}</div>
        @endif
        @php $buttonUrl = \App\Support\LinkDestination::resolve($cta['button_page'] ?? null, $cta['button_url'] ?? null, $page->locale); @endphp
        @if($buttonUrl && filled($cta['button_label'] ?? null))
            <a href="{{ $buttonUrl }}" @if($cta['button_new_tab'] ?? false) target="_blank" rel="noopener noreferrer" @endif
               class="inline-flex mt-6 rounded-md bg-white text-gray-900 px-5 py-3 font-semibold">{{ $cta['button_label'] }}</a>
        @endif
    </div>
</section>
@endif
@endsection
