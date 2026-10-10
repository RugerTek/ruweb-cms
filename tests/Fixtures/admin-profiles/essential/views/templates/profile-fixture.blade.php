@extends('layouts.public')

@section('content')
<article class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
    <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 mb-6">{!! \App\Support\EditorialTitle::render($page->meta()['title'] ?? $page->title) !!}</h1>

    @if($page->meta()['description'] ?? null)
        <p class="text-lg text-gray-600 mb-8">{{ $page->meta()['description'] }}</p>
    @endif

    @php $content = $page->sections()['content'] ?? []; @endphp

    @if(($content['is_visible'] ?? 1) && ($content['heading'] ?? null))
        <h2 class="text-2xl font-semibold text-gray-900 mb-4">{!! \App\Support\EditorialTitle::render($content['heading']) !!}</h2>
    @endif

    @if(($content['is_visible'] ?? 1) && ($content['body'] ?? null))
        <div class="prose prose-gray max-w-none">
            {!! \App\Support\SectionRichText::render($content['body'], 'section-body') !!}
        </div>
    @endif
    @php
        $testimonials = $page->sections()['testimonials'] ?? [];
        $items = collect($testimonials['items'] ?? [])->filter(fn ($item) => filled($item['quote'] ?? null));
        $portraits = \App\Models\Media::whereIn('id', $items->pluck('portrait_id')->filter())->get()->keyBy('id');
    @endphp
    @if(($testimonials['is_visible'] ?? 1) && $items->isNotEmpty())
        <section class="mt-12" @if($testimonials['heading'] ?? null) aria-labelledby="testimonials-heading" @endif>
            @if($testimonials['heading'] ?? null)
                <h2 id="testimonials-heading" class="text-2xl font-semibold mb-6">{!! \App\Support\EditorialTitle::render($testimonials['heading']) !!}</h2>
            @endif
            <div class="space-y-6">
                @foreach($items as $item)
                    <figure class="rounded-xl border border-gray-200 p-6">
                        <blockquote class="text-lg text-gray-700 whitespace-pre-line">{{ $item['quote'] }}</blockquote>
                        <figcaption class="mt-4 flex items-center gap-3">
                            <x-responsive-img :media="$portraits->get($item['portrait_id'] ?? null)" sizes="64px" alt="" class="h-16 w-16 rounded-full object-cover aspect-square" />
                            <div>
                                @if($item['author'] ?? null)<p class="font-semibold">{{ $item['author'] }}</p>@endif
                                @if($item['role'] ?? null)<p class="text-sm text-gray-600">{{ $item['role'] }}</p>@endif
                            </div>
                        </figcaption>
                    </figure>
                @endforeach
            </div>
        </section>
    @endif
</article>
@endsection
