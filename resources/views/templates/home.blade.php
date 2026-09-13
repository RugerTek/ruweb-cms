@extends('layouts.public')

@section('content')
@php
    $sections = $page->sections();
    $hero = $sections['hero'] ?? [];
    $features = $sections['features'] ?? [];
    $cta = $sections['cta'] ?? [];
    $heroBackground = isset($hero['image_id']) ? \App\Models\Media::find((int) $hero['image_id']) : null;

    $logoItems = collect($features['logos'] ?? [])
        ->map(fn ($logo) => [
            'media' => filled($logo['image_id'] ?? null) ? \App\Models\Media::find((int) $logo['image_id']) : null,
            'label' => $logo['label'] ?? null,
        ])
        ->filter(fn ($logo) => $logo['media'] !== null)
        ->values();

    // The track is rendered twice so the translation loops seamlessly; when there are
    // only a couple of logos it is repeated further so the strip never runs out of items.
    $trackRepeats = $logoItems->isEmpty() ? 0 : (int) max(2, ceil(8 / $logoItems->count()));
@endphp

{{-- Hero --}}
@if(($hero['is_visible'] ?? 1) && ($hero['heading'] ?? null))
<section class="relative overflow-hidden py-20 sm:py-28 {{ $heroBackground ? 'bg-gray-900' : 'bg-gray-50' }}">
    @if($heroBackground)
        <div class="absolute inset-0">
            <x-responsive-img
                :media="$heroBackground"
                sizes="100vw"
                alt=""
                class="h-full w-full object-cover"
            />
            <div class="absolute inset-0 bg-black/45"></div>
        </div>
    @endif
    <div class="relative max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
        <h1 class="text-4xl sm:text-5xl font-bold tracking-tight {{ $heroBackground ? 'text-white' : 'text-gray-900' }}">{{ $hero['heading'] }}</h1>
        @if($hero['body'] ?? null)
            <p class="mt-6 text-lg sm:text-xl max-w-2xl mx-auto {{ $heroBackground ? 'text-gray-100' : 'text-gray-600' }}">{{ $hero['body'] }}</p>
        @endif
    </div>
</section>
@endif

{{-- Features --}}
@if(($features['is_visible'] ?? 1) && (($features['heading'] ?? $features['body'] ?? null) || $logoItems->isNotEmpty()))
<section class="py-16 sm:py-20">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        @if($features['heading'] ?? null)
            <h2 class="text-3xl font-bold text-gray-900 text-center mb-4">{{ $features['heading'] }}</h2>
        @endif
        @if($features['body'] ?? null)
            <div class="prose prose-gray max-w-none text-center">
                {!! nl2br(e($features['body'])) !!}
            </div>
        @endif
    </div>

    @if($logoItems->isNotEmpty())
        <div class="logo-marquee mt-10 sm:mt-12" role="group" aria-label="{{ $features['heading'] ?? '' }}">
            <div class="logo-marquee-track" style="--logo-marquee-duration: {{ max(18, $logoItems->count() * $trackRepeats * 3) }}s; --logo-marquee-copies: {{ $trackRepeats }}">
                @for($repeat = 0; $repeat < $trackRepeats; $repeat++)
                    @foreach($logoItems as $logo)
                        <div class="logo-marquee-item" @if($repeat > 0) aria-hidden="true" @endif>
                            <x-responsive-img
                                :media="$logo['media']"
                                sizes="(max-width: 640px) 40vw, (max-width: 1024px) 22vw, 160px"
                                :alt="$logo['label'] ?: ($logo['media']->alt ?? '')"
                                class="h-full w-full object-contain"
                            />
                        </div>
                    @endforeach
                @endfor
            </div>
        </div>
    @endif
</section>
@endif

{{-- CTA --}}
@if(($cta['is_visible'] ?? 1) && ($cta['heading'] ?? $cta['body'] ?? null))
<section class="bg-gray-900 py-16 sm:py-20">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
        @if($cta['heading'] ?? null)
            <h2 class="text-3xl font-bold text-white mb-4">{{ $cta['heading'] }}</h2>
        @endif
        @if($cta['body'] ?? null)
            <p class="text-lg text-gray-300 max-w-2xl mx-auto">{{ $cta['body'] }}</p>
        @endif
    </div>
</section>
@endif
@endsection
