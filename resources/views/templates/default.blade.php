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
</article>
@endsection
