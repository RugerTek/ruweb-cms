@php
    $customCodeTarget = request()->attributes->get('custom_code_target');
    $customCodeRenderer = app(\App\Support\CustomCodeRenderer::class);
    $customCodeEvaluation = $customCodeTarget && ! ($isEditorPreview ?? false) && $customCodeRenderer->enabled()
        ? $customCodeRenderer->evaluate($customCodeTarget) : null;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <base href="{{ url('/') }}">
    <x-seo-meta :page="$page ?? null" :is-editor-preview="$isEditorPreview ?? false" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
    {!! $customCodeRenderer->render($customCodeTarget, 'head_end', ($isEditorPreview ?? false), $customCodeEvaluation) !!}
</head>
<body class="min-h-screen flex flex-col bg-white text-gray-900 font-sans antialiased {{ auth()->check() && ! ($isEditorPreview ?? false) ? 'pt-9' : '' }}">
    {!! $customCodeRenderer->render($customCodeTarget, 'body_start', ($isEditorPreview ?? false), $customCodeEvaluation) !!}
    @php($metaPixelId = \App\Models\Setting::get('meta_pixel_id'))

    @if($metaPixelId && ! ($isEditorPreview ?? false))
        <noscript>
            <img height="1" width="1" style="display:none"
                 src="https://www.facebook.com/tr?id={{ urlencode($metaPixelId) }}&ev=PageView&noscript=1"
                 alt="">
        </noscript>
    @endif

    @include('partials.admin-bar')
    @if(($page->template_key ?? null) !== 'empty')
        @include('partials.header')
    @endif

    <main class="flex-1">
        @yield('content')
    </main>

    @if(($page->template_key ?? null) !== 'empty')
        @include('partials.footer')
    @endif

    @stack('scripts')
    {!! $customCodeRenderer->render($customCodeTarget, 'body_end', ($isEditorPreview ?? false), $customCodeEvaluation) !!}
</body>
</html>
