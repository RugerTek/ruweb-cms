@extends('admin.layouts.app')

@section('title', __('editorial.history').' · '.$model->title)

@section('content')
@php
    $primaryButton = 'inline-flex items-center justify-center rounded-md bg-sky-600 hover:bg-sky-500 px-3 py-2 text-sm font-medium text-white transition-colors focus:outline-none focus:ring-2 focus:ring-sky-400 focus:ring-offset-2 focus:ring-offset-[#141414] disabled:opacity-40 disabled:cursor-not-allowed';
    $secondaryButton = 'inline-flex items-center justify-center rounded-md border border-white/10 bg-white/[0.03] hover:bg-white/[0.06] px-3 py-2 text-sm font-medium text-gray-300 transition-colors focus:outline-none focus:ring-2 focus:ring-sky-500/50 disabled:opacity-40 disabled:cursor-not-allowed';
    $control = 'block w-full bg-[#1a1a1a] border border-white/10 rounded-md px-3 py-2 text-sm text-gray-200 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50';
    $liveEditor = auth()->user()->isEditor() && $model->status === 'published';
    $baseFingerprint = app(\App\Services\Editorial\EditorialHistory::class)->fingerprint($model);
@endphp
<div class="max-w-5xl mx-auto space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-lg font-semibold text-white">{{ __('editorial.history') }}</h1>
            <p class="text-sm text-gray-300 mt-1 break-words">{{ $model->title }}</p>
            <p class="text-sm text-gray-500 mt-1">{{ __('editorial.history_hint') }}</p>
        </div>
        <nav aria-label="{{ __('editorial.history') }}" class="flex flex-wrap gap-2">
            <a href="{{ (($model instanceof \App\Models\Page && ! app(\App\Support\AdminCapabilities::class)->pageVisualEditor($model->template_key)) || ! app(\App\Support\AdminCapabilities::class)->liveEditor() ? route('admin.'.$model->getTable().'.index') : app(\App\Support\AdminCapabilities::class)->contentUrl($model)) }}" class="{{ $secondaryButton }}">&larr; {{ __('editorial.back') }}</a>
            <a href="{{ route('admin.editorial.calendar') }}" class="{{ $secondaryButton }}">{{ __('editorial.calendar') }}</a>
        </nav>
    </header>

    @if($model instanceof \App\Models\Page && config('cms.templates.'.$model->template_key.'.visual_editor', true) === false)
        <p role="status" class="rounded-lg border border-sky-500/20 bg-sky-500/10 p-4 text-sm text-sky-200">{{ __('admin.composition_editor_notice') }}</p>
    @endif

    @if($errors->any())
        <div role="alert" class="rounded-lg border border-red-500/20 bg-red-500/10 p-4 text-sm text-red-300">
            <ul class="space-y-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <section aria-labelledby="editorial-versions" class="rounded-xl bg-[#141414] ring-1 ring-white/[0.06] overflow-hidden">
        <div class="px-5 py-4 border-b border-white/[0.06]">
            <h2 id="editorial-versions" class="text-sm font-semibold text-white">{{ __('editorial.versions') }}</h2>
            <p class="text-xs text-gray-500 mt-1.5">{{ __('editorial.restore_help') }}</p>
        </div>
        @if($versions->isEmpty())
            <p class="px-5 py-10 text-sm text-center text-gray-500">{{ __('editorial.no_versions') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-white/[0.02] border-b border-white/[0.06]">
                        <tr>
                            <th scope="col" class="px-5 py-3 text-xs font-medium text-gray-500">{{ __('editorial.version') }}</th>
                            <th scope="col" class="px-5 py-3 text-xs font-medium text-gray-500">{{ __('admin.col_date') }}</th>
                            <th scope="col" class="px-5 py-3 text-xs font-medium text-gray-500">{{ __('editorial.author') }}</th>
                            <th scope="col" class="px-5 py-3 text-xs font-medium text-gray-500 text-right">{{ __('editorial.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/[0.04]">
                        @foreach($versions as $item)
                            <tr class="{{ $version?->id === $item->id ? 'bg-sky-500/[0.06]' : 'hover:bg-white/[0.02]' }} transition-colors">
                                <th scope="row" class="px-5 py-4 font-medium text-gray-200 whitespace-nowrap">
                                    #{{ $item->id }}
                                    @if($item->is_proposal)<span class="ml-2 inline-flex rounded-md bg-amber-500/10 px-2 py-1 text-xs text-amber-400">{{ __('editorial.proposal') }}</span>@endif
                                </th>
                                <td class="px-5 py-4 text-gray-400 whitespace-nowrap"><time datetime="{{ $item->created_at }}">{{ \Illuminate\Support\Carbon::parse($item->created_at)->format('d/m/Y H:i') }}</time></td>
                                <td class="px-5 py-4 text-gray-300">{{ $authors->get($item->user_id)?->name ?? __('editorial.system') }}</td>
                                <td class="px-5 py-3">
                                    <div class="flex items-center justify-end gap-2 whitespace-nowrap">
                                        <a href="{{ route('admin.editorial.index', [$model->getTable(), $model->id, 'version' => $item->id]) }}" class="{{ $secondaryButton }}" @if($version?->id === $item->id) aria-current="true" @endif>{{ __('editorial.compare') }}</a>
                                        <form method="POST" data-confirm="{{ __('editorial.restore_confirm') }}" data-confirm-title="{{ __('editorial.restore') }}" data-confirm-label="{{ __('editorial.restore') }}" data-confirm-tone="danger" action="{{ route('admin.editorial.restore', [$model->getTable(), $model->id, $item->id]) }}">
                                            @csrf
                                            <input type="hidden" name="_editor_lock" value="{{ $editorLease['token'] ?? '' }}">
                                            <input type="hidden" name="_editor_base" value="{{ $baseFingerprint }}">
                                            <button type="submit" class="{{ $secondaryButton }}" @disabled($liveEditor) @if($liveEditor) title="{{ __('editorial.publish_forbidden') }}" @endif>{{ __('editorial.restore') }}</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($versions->hasPages())<div class="px-5 py-4 border-t border-white/[0.06]">{{ $versions->links() }}</div>@endif
        @endif
    </section>

    @if($version)
        <section aria-labelledby="editorial-comparison" class="rounded-xl bg-[#141414] ring-1 ring-white/[0.06] overflow-hidden">
            <div class="px-5 py-4 border-b border-white/[0.06]"><h2 id="editorial-comparison" class="text-sm font-semibold text-white">{{ __('editorial.compare') }} · {{ __('editorial.version') }} #{{ $version->id }}</h2></div>
            @if(empty($diff))
                <p class="px-5 py-8 text-sm text-gray-500">{{ __('editorial.no_differences') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-white/[0.02]"><tr>@foreach(['field', 'before', 'current'] as $heading)<th scope="col" class="px-5 py-3 text-xs font-medium text-gray-500">{{ __('editorial.'.$heading) }}</th>@endforeach</tr></thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            @foreach($diff as $field => $values)
                                <tr class="align-top">
                                    <th scope="row" class="px-5 py-4 text-xs font-medium text-gray-400 break-words max-w-48">{{ $field }}</th>
                                    @foreach(['before', 'after'] as $side)
                                        <td class="px-5 py-4 min-w-52 max-w-sm"><pre class="whitespace-pre-wrap break-words text-xs leading-6 font-sans {{ $side === 'before' ? 'text-gray-400' : 'text-gray-200' }}">{{ is_scalar($values[$side]) ? $values[$side] : json_encode($values[$side], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre></td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

    @if(count($stale))
        <section aria-labelledby="editorial-source-changes" class="rounded-xl bg-[#141414] ring-1 ring-white/[0.06] overflow-hidden">
            <div class="px-5 py-4 border-b border-white/[0.06]"><h2 id="editorial-source-changes" class="text-sm font-semibold text-white">{{ __('editorial.translation_changes') }}</h2></div>
            <div class="p-5 space-y-4">
                @foreach($stale as $field)
                    @php($change = app(\App\Services\Editorial\EditorialHistory::class)->sourceChange($model, $field))
                    <article class="rounded-lg border border-white/[0.08] p-4 space-y-4">
                        <h3 class="text-sm font-medium text-gray-200 break-words">{{ $field }}</h3>
                        <dl class="grid gap-4 sm:grid-cols-2">
                            @foreach(['before' => 'before', 'after' => 'current'] as $side => $label)
                                <div class="min-w-0"><dt class="text-xs text-gray-500 mb-2">{{ __('editorial.'.$label) }}</dt><dd class="text-sm text-gray-300 whitespace-pre-wrap break-words leading-6">{{ is_scalar($change[$side]) ? $change[$side] : json_encode($change[$side], JSON_UNESCAPED_UNICODE) }}</dd></div>
                            @endforeach
                        </dl>
                        <form method="POST" action="{{ route('admin.editorial.approve-field', [$model->getTable(), $model->id]) }}">
                            @csrf
                            <input type="hidden" name="_editor_lock" value="{{ $editorLease['token'] ?? '' }}">
                            <input type="hidden" name="field" value="{{ $field }}">
                            <input type="hidden" name="base_hash" value="{{ $change['hash'] }}">
                            <button type="submit" class="{{ $primaryButton }}" @disabled($liveEditor) @if($liveEditor) title="{{ __('editorial.publish_forbidden') }}" @endif>{{ __('editorial.approve_field') }}</button>
                        </form>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    <section aria-labelledby="editorial-review" class="rounded-xl bg-[#141414] ring-1 ring-white/[0.06] overflow-hidden">
        <div class="px-5 py-4 border-b border-white/[0.06]"><h2 id="editorial-review" class="text-sm font-semibold text-white">{{ __('editorial.review') }}</h2></div>
        <div class="p-5 space-y-6">
            <form method="POST" action="{{ route('admin.editorial.review', [$model->getTable(), $model->id]) }}" class="space-y-4">
                @csrf
                <div class="max-w-sm">
                    <label for="reviewer-id" class="block text-xs font-medium text-gray-400 mb-2">{{ __('editorial.reviewer') }}</label>
                    <select id="reviewer-id" name="reviewer_id" required class="{{ $control }}">@foreach($reviewers as $reviewer)<option value="{{ $reviewer->id }}" @selected((string) old('reviewer_id') === (string) $reviewer->id)>{{ $reviewer->name }}</option>@endforeach</select>
                    @error('reviewer_id')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="request-comment" class="block text-xs font-medium text-gray-400 mb-2">{{ __('editorial.comment') }}</label>
                    <textarea id="request-comment" name="comment" maxlength="10000" rows="3" class="{{ $control }}">{{ old('comment') }}</textarea>
                </div>
                <button type="submit" class="{{ $primaryButton }}">{{ __('editorial.request_review') }}</button>
            </form>
            <div class="space-y-4 border-t border-white/[0.06] pt-5">
                @forelse($reviews as $review)
                    <article class="rounded-lg border border-white/[0.08] p-4 space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="inline-flex rounded-md px-2 py-1 text-xs font-medium {{ $review->state === 'approved' ? 'bg-emerald-500/10 text-emerald-400' : ($review->state === 'changes_requested' ? 'bg-amber-500/10 text-amber-400' : 'bg-sky-500/10 text-sky-400') }}">{{ __('editorial.'.$review->state) }}</span>
                            <time class="text-xs text-gray-500" datetime="{{ $review->created_at }}">{{ \Illuminate\Support\Carbon::parse($review->created_at)->format('d/m/Y H:i') }}</time>
                        </div>
                        <p class="text-xs text-gray-500">{{ __('editorial.requested_by') }}: <span class="text-gray-300">{{ $authors->get($review->requested_by)?->name ?? __('editorial.system') }}</span> · {{ __('editorial.reviewer') }}: <span class="text-gray-300">{{ $authors->get($review->reviewer_id)?->name ?? __('editorial.system') }}</span></p>
                        @if($review->comment)<p class="text-sm text-gray-300 whitespace-pre-wrap break-words leading-6">{{ $review->comment }}</p>@endif
                        @if($review->version_id)<a class="inline-block text-sm text-sky-400 hover:text-sky-300 underline underline-offset-4" href="{{ route('admin.editorial.index', [$model->getTable(), $model->id, 'version' => $review->version_id]) }}">{{ __('editorial.compare_proposal') }}</a>@endif
                        @foreach($comments->get($review->id, collect()) as $comment)
                            <div class="rounded-md bg-white/[0.03] p-3">
                                <p class="text-xs text-gray-500">{{ $authors->get($comment->user_id)?->name ?? __('editorial.system') }} · <time datetime="{{ $comment->created_at }}">{{ \Illuminate\Support\Carbon::parse($comment->created_at)->format('d/m/Y H:i') }}</time></p>
                                <p class="mt-2 text-sm text-gray-300 whitespace-pre-wrap break-words leading-6">{{ $comment->body }}</p>
                            </div>
                        @endforeach
                        <form method="POST" action="{{ route('admin.editorial.comment', [$model->getTable(), $model->id, $review->id]) }}" class="space-y-3 border-t border-white/[0.06] pt-4">
                            @csrf
                            <label for="review-comment-{{ $review->id }}" class="block text-xs font-medium text-gray-400">{{ __('editorial.comment') }}</label>
                            <textarea id="review-comment-{{ $review->id }}" name="body" required maxlength="10000" rows="2" class="{{ $control }}"></textarea>
                            <button type="submit" class="{{ $secondaryButton }}">{{ __('editorial.add_comment') }}</button>
                        </form>
                        @if(auth()->id() === $review->reviewer_id && $review->state === 'pending')
                            <form method="POST" action="{{ route('admin.editorial.decide', [$model->getTable(), $model->id, $review->id]) }}" class="space-y-3 border-t border-white/[0.06] pt-4">
                                @csrf
                                <label for="decision-comment-{{ $review->id }}" class="block text-xs font-medium text-gray-400">{{ __('editorial.comment') }}</label>
                                <textarea id="decision-comment-{{ $review->id }}" name="comment" maxlength="10000" rows="3" class="{{ $control }}"></textarea>
                                <div class="flex flex-wrap gap-2"><button type="submit" name="state" value="approved" class="{{ $primaryButton }}">{{ __('editorial.approve') }}</button><button type="submit" name="state" value="changes_requested" class="{{ $secondaryButton }}">{{ __('editorial.request_changes') }}</button></div>
                            </form>
                        @endif
                    </article>
                @empty
                    <p class="text-sm text-gray-500">{{ __('editorial.no_reviews') }}</p>
                @endforelse
            </div>
        </div>
    </section>

    <section aria-labelledby="editorial-share" class="rounded-xl bg-[#141414] ring-1 ring-white/[0.06] overflow-hidden">
        <div class="px-5 py-4 border-b border-white/[0.06]"><h2 id="editorial-share" class="text-sm font-semibold text-white">{{ __('editorial.share') }}</h2></div>
        <div class="p-5 space-y-5">
            @if(session('previewUrl'))
                <div role="status" class="rounded-lg bg-sky-500/10 border border-sky-500/20 p-4">
                    <label for="created-preview-url" class="block text-xs font-medium text-sky-300 mb-2">{{ __('editorial.share_url') }}</label>
                    <input id="created-preview-url" class="{{ $control }} select-all" readonly value="{{ session('previewUrl') }}">
                </div>
            @endif
            <form method="POST" action="{{ route('admin.editorial.share', [$model->getTable(), $model->id]) }}" class="flex flex-wrap items-end gap-3">
                @csrf
                @if($version)<input type="hidden" name="version_id" value="{{ $version->id }}">@endif
                <div class="w-44">
                    <label for="preview-hours" class="block text-xs font-medium text-gray-400 mb-2">{{ __('editorial.hours') }}</label>
                    <input id="preview-hours" name="hours" type="number" min="1" max="168" value="{{ old('hours', 24) }}" required class="{{ $control }}">
                    @error('hours')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="{{ $primaryButton }}">{{ __('editorial.create_link') }}</button>
            </form>
            <div class="border-t border-white/[0.06] pt-4 space-y-3">
                <h3 class="text-xs font-medium text-gray-500">{{ __('editorial.active_links') }}</h3>
                @forelse($links as $link)
                    <form method="POST" action="{{ route('admin.editorial.revoke', [$model->getTable(), $model->id, $link->id]) }}" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-white/[0.08] px-4 py-3">
                        @csrf @method('DELETE')
                        <p class="text-sm text-gray-400">{{ __('editorial.expires_at') }}: <time datetime="{{ $link->expires_at }}">{{ \Illuminate\Support\Carbon::parse($link->expires_at)->format('d/m/Y H:i') }}</time></p>
                        <button type="submit" class="rounded-md border border-red-500/20 bg-red-500/10 hover:bg-red-500/20 px-3 py-2 text-sm font-medium text-red-400 transition-colors focus:outline-none focus:ring-2 focus:ring-red-400">{{ __('editorial.revoke') }}</button>
                    </form>
                @empty
                    <p class="text-sm text-gray-500">{{ __('editorial.no_links') }}</p>
                @endforelse
            </div>
        </div>
    </section>
</div>
@endsection
