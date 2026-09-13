@extends('layouts.public')

@section('content')
@php
    $blogIndexHref = app()->getLocale() === \App\Models\Locale::baseCode()
        ? route('blog.index')
        : route('localized.blog.index', ['locale' => app()->getLocale()]);
@endphp
<article class="py-14 sm:py-16">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        <a href="{{ $blogIndexHref }}" class="inline-flex items-center text-sm text-gray-500 hover:text-gray-800 mb-6 transition-colors">
            ← {{ __('public.back_to_blog') }}
        </a>

        <header>
            <p class="text-sm text-gray-500 mb-2">{{ optional($post->published_at)?->translatedFormat('M d, Y') }}</p>
            <h1 class="text-3xl sm:text-4xl font-semibold tracking-tight text-gray-900">{{ $post->title }}</h1>
            @if($post->excerpt)
                <p class="mt-4 text-lg text-gray-600">{{ $post->excerpt }}</p>
            @endif
        </header>

        @if($post->featuredImage())
            <figure class="mt-8">
                <x-responsive-img
                    :media="$post->featuredImage()"
                    sizes="(min-width: 1024px) 768px, 100vw"
                    :fallback-alt="$post->title"
                    class="w-full rounded-xl object-cover"
                />
            </figure>
        @endif

        @if($post->content)
            <div class="prose prose-gray max-w-none mt-8">
                {!! \App\Helpers\ContentHelper::richText($post->content) !!}
            </div>
        @endif
    </div>
</article>
@endsection
