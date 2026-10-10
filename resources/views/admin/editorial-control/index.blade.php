@extends('admin.layouts.app')

@section('title', __('admin.editorial_control'))

@php
    $baseQuery = array_filter(['type' => $selectedType !== 'all' ? $selectedType : null]);
    $issueOptions = [
        'all' => __('admin.editorial_issue_filter_all'),
        'featured_image' => __('admin.editorial_issue_featured_image_title'),
        'seo_title' => __('admin.editorial_issue_seo_title_title'),
        'seo_description' => __('admin.editorial_issue_seo_description_title'),
    ];
@endphp

@section('content')
<div class="mb-6">
    <div>
        <h1 class="text-lg font-semibold text-white">{{ __('admin.editorial_control') }}</h1>
        <p class="text-sm text-gray-500 mt-0.5">{{ __('admin.editorial_control_subtitle') }}</p>
    </div>
</div>

<div class="mb-5 border-b border-white/[0.08]">
    <div class="flex flex-wrap gap-1">
        <a href="{{ route('admin.editorial-control.index', array_filter($baseQuery + ['tab' => 'content', 'issue' => $selectedIssue ?: null])) }}"
           class="px-4 py-3 text-sm font-medium border-b-2 transition-colors {{ $activeTab === 'content' ? 'border-sky-400 text-sky-300' : 'border-transparent text-gray-500 hover:text-gray-300' }}">
            {{ __('admin.editorial_content_tab') }}
        </a>
        @if($hasSecondaryPublicLocales)
            <a href="{{ route('admin.editorial-control.index', array_filter($baseQuery + ['tab' => 'translations', 'translation_state' => $translationState])) }}"
               class="px-4 py-3 text-sm font-medium border-b-2 transition-colors {{ $activeTab === 'translations' ? 'border-sky-400 text-sky-300' : 'border-transparent text-gray-500 hover:text-gray-300' }}">
                {{ __('admin.editorial_translations_tab') }}
            </a>
        @endif
    </div>
</div>

<form method="GET" class="mb-5 flex flex-wrap items-end gap-3 rounded-xl bg-[#141414] ring-1 ring-white/[0.06] px-4 py-3">
    <input type="hidden" name="tab" value="{{ $activeTab }}">
    <div>
        <label for="type" class="block text-xs font-medium uppercase tracking-wide text-gray-500 mb-1.5">{{ __('admin.type') }}</label>
        <select name="type" id="type"
                class="bg-[#101010] border border-white/10 text-sm text-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50">
            <option value="all" {{ $selectedType === 'all' ? 'selected' : '' }}>{{ __('admin.editorial_type_all') }}</option>
            <option value="page" {{ $selectedType === 'page' ? 'selected' : '' }}>{{ __('admin.editorial_type_page') }}</option>
            <option value="post" {{ $selectedType === 'post' ? 'selected' : '' }}>{{ __('admin.editorial_type_post') }}</option>
        </select>
    </div>

    @if($activeTab === 'content')
        <div>
            <label for="issue" class="block text-xs font-medium uppercase tracking-wide text-gray-500 mb-1.5">{{ __('admin.editorial_problem') }}</label>
            <select name="issue" id="issue"
                    class="bg-[#101010] border border-white/10 text-sm text-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50">
                @foreach($issueOptions as $value => $label)
                    <option value="{{ $value }}" {{ ($selectedIssue ?: 'all') === $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if($activeTab === 'translations')
        <div>
            <label for="translation_state" class="block text-xs font-medium uppercase tracking-wide text-gray-500 mb-1.5">{{ __('admin.col_status') }}</label>
            <select name="translation_state" id="translation_state"
                    class="bg-[#101010] border border-white/10 text-sm text-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50">
                <option value="all" {{ $translationState === 'all' ? 'selected' : '' }}>{{ __('admin.editorial_translation_filter_all') }}</option>
                <option value="pending" {{ $translationState === 'pending' ? 'selected' : '' }}>{{ __('admin.editorial_translation_filter_pending') }}</option>
                <option value="outdated" {{ $translationState === 'outdated' ? 'selected' : '' }}>{{ __('admin.editorial_translation_filter_outdated') }}</option>
            </select>
        </div>
    @endif

    <button type="submit"
            class="inline-flex items-center gap-2 px-3 py-2 rounded-md bg-white/5 border border-white/10 text-sm text-gray-300 hover:bg-white/10 transition-colors">
        {{ __('admin.search') }}
    </button>
</form>

@if($activeTab === 'content')
    <div class="grid gap-3 md:grid-cols-3 mb-5">
        @foreach($completionIssues as $issue)
            <a href="{{ route('admin.editorial-control.index', array_filter($baseQuery + ['tab' => 'content', 'issue' => $issue['key']])) }}"
               class="block rounded-xl border p-4 transition-colors {{ $issue['selected'] ? 'border-sky-500/40 bg-sky-500/[0.06]' : 'border-white/[0.06] bg-[#141414] hover:bg-white/[0.03]' }}">
                <div class="text-2xl font-semibold text-white">{{ $issue['count'] }}</div>
                <div class="mt-1 text-sm font-medium text-gray-200">{{ $issue['title'] }}</div>
                <div class="mt-1 text-xs text-gray-500">{{ $issue['description'] }}</div>
            </a>
        @endforeach
    </div>

    <div id="editorial-completeness" class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl overflow-hidden">
        <div class="px-5 py-4 border-b border-white/[0.06] flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-sm font-semibold text-white">{{ __('admin.editorial_completeness_title') }}</h2>
                <p class="text-sm text-gray-500 mt-0.5">{{ __('admin.editorial_completeness_subtitle') }}</p>
            </div>
            <div class="text-xs font-medium text-gray-500">
                {{ trans_choice('admin.editorial_rows_count', $editorialRows->count(), ['count' => $editorialRows->count()]) }}
            </div>
        </div>

        @if($editorialRows->isEmpty())
            <div class="px-5 py-8 text-sm text-gray-500">
                {{ __('admin.editorial_issue_clear') }}
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px]">
                    <thead class="bg-white/[0.02] text-xs font-medium uppercase tracking-wider text-gray-500">
                        <tr>
                            <th scope="col" class="px-5 py-3 text-left">{{ __('admin.editorial_col_content') }}</th>
                            <th scope="col" class="px-5 py-3 text-left">{{ __('admin.type') }}</th>
                            <th scope="col" class="px-5 py-3 text-left">{{ __('admin.editorial_col_featured_image') }}</th>
                            <th scope="col" class="px-5 py-3 text-left">{{ __('admin.editorial_col_seo_title') }}</th>
                            <th scope="col" class="px-5 py-3 text-left">{{ __('admin.editorial_col_seo_description') }}</th>
                            <th scope="col" class="px-5 py-3 text-right">{{ __('admin.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/[0.04]">
                        @foreach($editorialRows as $row)
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-5 py-4">
                                    <div class="text-sm font-medium text-white">{{ $row['label'] }}</div>
                                    <div class="text-xs text-gray-500 mt-1">
                                        {{ trans_choice('admin.editorial_issue_count', $row['issue_count'], ['count' => $row['issue_count']]) }}
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-sm text-gray-400">{{ $row['type_label'] }}</td>
                                @foreach(['featured_image', 'seo_title', 'seo_description'] as $issueKey)
                                    <td class="px-5 py-4">
                                        @if($row['issues'][$issueKey])
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-500/10 text-yellow-300">
                                                {{ __('admin.editorial_status_missing') }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-300">
                                                {{ __('admin.editorial_status_ok') }}
                                            </span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="px-5 py-4 text-right">
                                    <a href="{{ $row['edit_url'] }}?return={{ urlencode(request()->fullUrl()) }}"
                                       class="inline-flex items-center px-3 py-1.5 rounded-md bg-white/5 border border-white/10 text-xs font-medium text-sky-300 hover:bg-white/10 transition-colors">
                                        {{ __(app(\App\Support\AdminCapabilities::class)->liveEditor() ? 'admin.action_edit' : 'admin.history') }}
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endif

@if($activeTab === 'translations' && $hasSecondaryPublicLocales)
    <div id="translations" class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl overflow-hidden">
        <div class="px-5 py-4 border-b border-white/[0.06] flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-sm font-semibold text-white">{{ __('admin.editorial_translations_title') }}</h2>
                <p class="text-sm text-gray-500 mt-0.5">{{ __('admin.editorial_translations_subtitle') }}</p>
            </div>
            <div class="text-xs font-medium text-gray-500">
                {{ __('admin.editorial_translation_filter_pending') }}: {{ $pendingTranslations->count() }}
                <span class="mx-2 text-gray-700">/</span>
                {{ __('admin.editorial_translation_filter_outdated') }}: {{ $outdatedTranslations->count() }}
            </div>
        </div>

        @php($translationRows = $pendingTranslations->concat($outdatedTranslations)->values())

        @if($translationRows->isEmpty())
            <div class="px-5 py-8 text-sm text-gray-500">
                {{ __('admin.editorial_translations_clear') }}
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px]">
                    <thead class="bg-white/[0.02] text-xs font-medium uppercase tracking-wider text-gray-500">
                        <tr>
                            <th scope="col" class="px-5 py-3 text-left">{{ __('admin.editorial_col_content') }}</th>
                            <th scope="col" class="px-5 py-3 text-left">{{ __('admin.type') }}</th>
                            <th scope="col" class="px-5 py-3 text-left">{{ __('admin.language') }}</th>
                            <th scope="col" class="px-5 py-3 text-left">{{ __('admin.col_status') }}</th>
                            <th scope="col" class="px-5 py-3 text-right">{{ __('admin.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/[0.04]">
                        @foreach($translationRows as $item)
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-5 py-4 text-sm font-medium text-white">{{ $item['label'] }}</td>
                                <td class="px-5 py-4 text-sm text-gray-400">{{ $item['type_label'] }}</td>
                                <td class="px-5 py-4">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-white/[0.04] text-gray-300">
                                        {{ strtoupper($item['locale']) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $item['state'] === 'outdated' ? 'bg-yellow-500/10 text-yellow-300' : 'bg-sky-500/10 text-sky-300' }}">
                                        {{ $item['state_label'] }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-right">
                                    @if($item['action'])
                                        @if($item['action']['method'] === 'post')
                                            <form method="POST" action="{{ $item['action']['url'] }}" class="inline">
                                                @csrf
                                                <button type="submit" class="inline-flex items-center px-3 py-1.5 rounded-md bg-white/5 border border-white/10 text-xs font-medium text-sky-300 hover:bg-white/10 transition-colors">
                                                    {{ app(\App\Support\AdminCapabilities::class)->liveEditor() ? $item['action']['label'] : __('admin.history') }}
                                                </button>
                                            </form>
                                        @else
                                            <a href="{{ $item['action']['url'] }}"
                                               class="inline-flex items-center px-3 py-1.5 rounded-md bg-white/5 border border-white/10 text-xs font-medium text-sky-300 hover:bg-white/10 transition-colors">
                                                {{ app(\App\Support\AdminCapabilities::class)->liveEditor() ? $item['action']['label'] : __('admin.history') }}
                                            </a>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endif
@endsection
