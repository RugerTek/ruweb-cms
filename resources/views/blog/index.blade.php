@extends('layouts.public')

@section('content')
@php
    $blogShowRoute = fn ($post) => $post->url();
@endphp
<section class="py-14 sm:py-16">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="mb-10">
            <h1 class="text-3xl sm:text-4xl font-semibold tracking-tight text-gray-900">{{ __('public.blog_title') }}</h1>
            <p class="mt-2 text-gray-600">{{ __('public.blog_subtitle') }}</p>
        </div>

        @if($posts->isEmpty())
            <div class="rounded-xl border border-gray-200 p-8 text-center text-gray-500">
                {{ __('public.blog_empty') }}
            </div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($posts as $post)
                    <article class="border border-gray-200 rounded-xl overflow-hidden bg-white">
                        @if($post->featuredImage())
                            <a href="{{ $blogShowRoute($post) }}">
                                <x-responsive-img
                                    :media="$post->featuredImage()"
                                    sizes="(min-width: 1024px) 24rem, (min-width: 768px) 50vw, 100vw"
                                    :fallback-alt="$post->title"
                                    class="h-48 w-full object-cover"
                                />
                            </a>
                        @endif

                        <div class="p-5">
                            <p class="text-xs text-gray-500 mb-2">
                                {{ \App\Support\SiteTime::translatedFormat($post->published_at, 'M d, Y') }}
                            </p>
                            <h2 class="text-lg font-semibold text-gray-900 leading-snug">
                                <a href="{{ $blogShowRoute($post) }}" class="hover:text-sky-700 transition-colors">
                                    {{ $post->title }}
                                </a>
                            </h2>
                            @if($post->excerpt)
                                <p class="mt-2 text-sm text-gray-600 line-clamp-3">{{ $post->excerpt }}</p>
                            @endif
                            <a href="{{ $blogShowRoute($post) }}" class="inline-flex mt-4 text-sm font-medium text-sky-700 hover:text-sky-800">
                                {{ __('public.read_article') }} →
                            </a>
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="mt-10">
                {{ $posts->links() }}
            </div>
        @endif
    </div>
</section>
@endsection
