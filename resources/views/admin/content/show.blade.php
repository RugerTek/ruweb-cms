@extends('admin.layouts.app')
@section('title', __('editorial.history').' · '.$model->title)
@section('content')
<div data-content-status class="max-w-5xl mx-auto space-y-6">
    <header class="flex flex-wrap justify-between gap-4">
        <div><h1 class="text-lg font-semibold text-white">{{ $model->title }}</h1><p class="mt-2 text-sm text-gray-400">{{ __('admin.essential_content_hint') }}</p></div>
        <nav class="flex flex-wrap gap-4 text-sm text-sky-400">
            <a href="{{ route('admin.content.index', $type) }}">{{ __('admin.back_to_content_list') }}</a>
            <a href="{{ route('admin.content.preview', [$type, $model->id]) }}" target="_blank" rel="noopener">{{ __('admin.action_preview') }}</a>
            @if($model->isPublished())<a href="{{ $model->url() }}" target="_blank" rel="noopener">{{ __('admin.action_view') }}</a>@endif
        </nav>
    </header>
    <dl class="grid gap-4 sm:grid-cols-3 rounded-xl bg-[#141414] p-5 text-sm">
        <div><dt class="text-gray-500">{{ __('admin.col_status') }}</dt><dd class="mt-1 text-white">{{ __('admin.'.app(\App\Support\AdminCapabilities::class)->publicationState($model)) }}</dd></div>
        <div><dt class="text-gray-500">{{ __('admin.content_publication_date') }}</dt><dd class="mt-1 text-white">{{ $model->published_at?->format('d/m/Y H:i') ?? '—' }}</dd></div>
        <div><dt class="text-gray-500">{{ __('editorial.expires_at') }}</dt><dd class="mt-1 text-white">{{ $model->expires_at?->format('d/m/Y H:i') ?? '—' }}</dd></div>
    </dl>
    <section class="rounded-xl bg-[#141414] p-5 space-y-4">
        <h2 class="text-sm font-semibold text-white">{{ __('editorial.versions') }}</h2>
        @forelse($versions as $item)
            <div class="flex flex-wrap justify-between gap-3 border-b border-white/10 pb-3 text-sm text-gray-400">
                <span>#{{ $item->id }} · {{ $item->created_at }} @if($item->is_proposal)· {{ __('editorial.proposal') }}@endif</span>
                <a class="text-sky-400" href="{{ route('admin.content.show', [$type, $model->id, 'version' => $item->id]) }}">{{ __('editorial.compare') }}</a>
            </div>
        @empty<p class="text-sm text-gray-500">{{ __('editorial.no_versions') }}</p>@endforelse
        {{ $versions->links() }}
    </section>
    @if($version)
        <section class="rounded-xl bg-[#141414] p-5 space-y-4">
            <h2 class="text-sm font-semibold text-white">{{ __('editorial.compare') }} #{{ $version->id }}</h2>
            @forelse($diff as $field => $values)
                <article class="border-b border-white/10 pb-4"><h3 class="text-xs text-gray-500 break-words">{{ $field }}</h3>
                    <dl class="grid gap-4 sm:grid-cols-2 mt-3">@foreach(['before' => 'before', 'after' => 'current'] as $side => $label)
                        <div class="min-w-0"><dt class="text-xs text-gray-500">{{ __('editorial.'.$label) }}</dt><dd class="mt-2 whitespace-pre-wrap break-words text-sm text-gray-300">{{ is_scalar($values[$side]) ? $values[$side] : json_encode($values[$side], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</dd></div>
                    @endforeach</dl>
                </article>
            @empty<p class="text-sm text-gray-500">{{ __('editorial.no_differences') }}</p>@endforelse
        </section>
    @endif
</div>
@endsection
