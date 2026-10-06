@props([
    'media' => null,
    'sizes' => '100vw',
    'alt' => null,
    'fallbackAlt' => '',
    'class' => '',
    'loading' => 'lazy',
    'decoding' => 'async',
])

@if($media)
    @php
        $resolvedAlt = filled($alt) ? $alt : ($media->localizedAlt() ?: $fallbackAlt);
    @endphp
    <img
        src="{{ $media->url() }}"
        @if($media->hasResponsiveVariants())
            srcset="{{ $media->srcset() }}"
            sizes="{{ $sizes }}"
        @endif
        alt="{{ $resolvedAlt }}"
        class="{{ $class }}"
        loading="{{ $loading }}"
        decoding="{{ $decoding }}"
        @if($media->width) width="{{ $media->width }}" @endif
        @if($media->height) height="{{ $media->height }}" @endif
    >
@endif
