@extends('admin.layouts.app')

@section('title', __('admin.posts'))

@section('content')
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6 gap-4">
    <h1 class="text-lg font-semibold text-white">{{ __('admin.posts') }}</h1>

    @if(app(\App\Support\AdminCapabilities::class)->liveEditor())
<a href="{{ route('admin.posts.create') }}" class="inline-flex items-center gap-2 px-3 py-1.5 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        {{ __('admin.new_post') }}
    </a>
@endif
</div>

<div class="flex gap-1 mb-6 border-b border-white/[0.06]">
    <a href="{{ route('admin.posts.index') }}"
       class="px-3 py-2 text-sm font-medium border-b-2 transition-colors {{ !$currentStatus ? 'border-sky-500 text-sky-400' : 'border-transparent text-gray-500 hover:text-gray-300' }}">
        {{ __('admin.all') }} <span class="text-xs text-gray-600 ml-1">{{ $totalCount }}</span>
    </a>
    <a href="{{ route('admin.posts.index', ['status' => 'published']) }}"
       class="px-3 py-2 text-sm font-medium border-b-2 transition-colors {{ $currentStatus === 'published' ? 'border-sky-500 text-sky-400' : 'border-transparent text-gray-500 hover:text-gray-300' }}">
        {{ __('admin.published') }} <span class="text-xs text-gray-600 ml-1">{{ $publishedCount }}</span>
    </a>
    <a href="{{ route('admin.posts.index', ['status' => 'draft']) }}"
       class="px-3 py-2 text-sm font-medium border-b-2 transition-colors {{ $currentStatus === 'draft' ? 'border-sky-500 text-sky-400' : 'border-transparent text-gray-500 hover:text-gray-300' }}">
        {{ __('admin.draft') }} <span class="text-xs text-gray-600 ml-1">{{ $draftCount }}</span>
    </a>
</div>

<div class="mb-6">
    <form method="GET" class="flex gap-2">
        @if($currentStatus)
            <input type="hidden" name="status" value="{{ $currentStatus }}">
        @endif
        <input
            type="text"
            name="search"
            value="{{ request('search') }}"
            placeholder="{{ __('admin.search_placeholder') }}"
            class="flex-1 bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50"
        >
        <button type="submit" class="px-3 py-2 bg-gray-800 border border-white/10 text-gray-400 text-sm rounded-md hover:bg-gray-700 transition-colors">
            {{ __('admin.search') }}
        </button>
    </form>
</div>

@if ($posts->isEmpty())
    <div class="text-center py-20 text-gray-600">
        <svg class="w-12 h-12 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/>
        </svg>
        <p class="text-sm font-medium text-gray-500">{{ __('admin.no_posts_yet') }}</p>
        <p class="text-sm text-gray-600 mt-1">{{ __('admin.no_posts_hint') }}</p>
        @if(app(\App\Support\AdminCapabilities::class)->liveEditor())
<a href="{{ route('admin.posts.create') }}" class="mt-4 inline-flex items-center gap-2 px-3 py-1.5 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
            {{ __('admin.new_post') }}
        </a>
@endif
    </div>
@else
    <div class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl overflow-hidden">
        <table class="w-full">
            <thead>
                <tr class="border-b border-white/[0.06]">
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('admin.col_title') }}</th>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3 hidden sm:table-cell">{{ __('admin.col_slug') }}</th>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3 hidden md:table-cell">{{ __('admin.language') }}</th>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('admin.col_status') }}</th>
                    <th class="text-right text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('admin.col_actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/[0.04]">
                @foreach ($posts as $post)
                    <tr class="hover:bg-white/[0.02] transition-colors">
                        <td class="px-4 py-3">
                            <a href="{{ app(\App\Support\AdminCapabilities::class)->contentUrl($post) }}" class="text-sm text-white hover:text-sky-400 transition-colors">
                                {{ $post->title }}
                            </a>
                        </td>
                        <td class="px-4 py-3 hidden sm:table-cell">
                            <span class="text-sm text-gray-500">/{{ $post->slug }}</span>
                        </td>
                        <td class="px-4 py-3 hidden md:table-cell">
                            <div class="flex items-center gap-1">
                                @foreach(($locales ?? collect()) as $locale)
                                    @php
                                        $state = $post->derivedTranslationState($locale->code);
                                        $translation = $post->translations->firstWhere('locale', $locale->code);
                                        $badgeColor = $state === 'published'
                                            ? 'bg-sky-500/10 text-sky-400'
                                            : ($state === 'missing' ? 'bg-white/[0.04] text-gray-600' : 'bg-yellow-500/10 text-yellow-400');
                                    @endphp
                                    @if($translation)
                                        <a href="{{ app(\App\Support\AdminCapabilities::class)->contentUrl($translation) }}"
                                           title="{{ __('admin.edit_translation', ['lang' => $locale->name]) }}"
                                           class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium transition-colors hover:ring-1 hover:ring-white/20 {{ $badgeColor }}">
                                            {{ strtoupper($locale->code) }}
                                        </a>
                                    @else
                                        <form method="POST" action="{{ route('admin.posts.translate', [$post, $locale->code]) }}" class="inline-flex">
                                            @csrf
                                            <button type="submit"
                                                    title="{{ __('admin.create_translation', ['lang' => $locale->name]) }}"
                                                    class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium transition-colors hover:bg-white/[0.08] hover:text-gray-300 {{ $badgeColor }}">
                                                +{{ strtoupper($locale->code) }}
                                            </button>
                                        </form>
                                    @endif
                                @endforeach
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            @if ($post->status === 'published')
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-sky-500/10 text-sky-400">{{ __('admin.badge_published') }}</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-500/10 text-yellow-400">{{ __('admin.badge_draft') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if ($post->isPublished())
                                    <a href="{{ $post->url() }}" target="_blank" class="text-gray-600 hover:text-gray-400 transition-colors" title="{{ __('admin.action_view') }}">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                        </svg>
                                    </a>
                                @endif
                                <a href="{{ app(\App\Support\AdminCapabilities::class)->contentUrl($post) }}" class="text-gray-600 hover:text-gray-400 transition-colors" title="{{ __('admin.action_edit') }}">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                </a>
                                <form method="POST" action="{{ route('admin.posts.destroy', $post) }}" onsubmit="return confirm('{{ __('admin.confirm_delete_post') }}')" class="flex items-center">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="flex items-center text-gray-600 hover:text-red-400 transition-colors" title="{{ __('admin.btn_delete_post') }}">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-6">
        {{ $posts->links() }}
    </div>
@endif
@endsection
