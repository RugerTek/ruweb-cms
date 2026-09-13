{{--
    Social icons from Settings > Contacto (docs/admin/layout.md).
    Renders nothing when no network is configured. Icons inherit the text color.

    <x-social-links class="flex gap-3 text-gray-500" icon-class="w-5 h-5" />
--}}
@props([
    'links' => null,
    'iconClass' => 'w-5 h-5',
    'linkClass' => 'hover:text-gray-900 transition-colors',
])
@php
    $links ??= \App\Support\BusinessContact::socialLinks();
@endphp
@if (count($links))
    <ul {{ $attributes->merge(['class' => 'flex items-center gap-3']) }} data-social-links>
        @foreach ($links as $link)
            <li>
                <a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer"
                   class="{{ $linkClass }}" title="{{ $link['label'] }}" aria-label="{{ $link['label'] }}">
                    <svg class="{{ $iconClass }}" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="{{ $link['icon'] }}"/>
                    </svg>
                </a>
            </li>
        @endforeach
    </ul>
@endif
