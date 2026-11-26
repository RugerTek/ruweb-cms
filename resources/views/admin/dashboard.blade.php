@extends('admin.layouts.app')

@section('title', __('admin.dashboard'))

@section('content')
<div class="mb-6">
    <h1 class="text-lg font-semibold text-white">{{ __('admin.dashboard') }}</h1>
    <p class="text-sm text-gray-500 mt-0.5">{{ __('admin.welcome_back', ['name' => auth()->user()->name]) }}</p>
</div>

@include('admin.support.notice')

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
    <a href="{{ route('admin.editorial-control.index', ['issue' => 'featured_image']) }}#editorial-completeness"
       class="block bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5 hover:ring-white/[0.12] transition-all">
        <div class="text-2xl font-semibold text-white">{{ $editorialIssueCounts['featured_image']['count'] ?? 0 }}</div>
        <div class="text-sm text-gray-500 mt-1">{{ __('admin.dashboard_editorial_featured_image') }}</div>
    </a>
    <a href="{{ route('admin.editorial-control.index', ['issue' => 'seo_title']) }}#editorial-completeness"
       class="block bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5 hover:ring-white/[0.12] transition-all">
        <div class="text-2xl font-semibold text-white">{{ $editorialIssueCounts['seo_title']['count'] ?? 0 }}</div>
        <div class="text-sm text-gray-500 mt-1">{{ __('admin.dashboard_editorial_seo_title') }}</div>
    </a>
    <a href="{{ route('admin.editorial-control.index', ['issue' => 'seo_description']) }}#editorial-completeness"
       class="block bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5 hover:ring-white/[0.12] transition-all">
        <div class="text-2xl font-semibold text-white">{{ $editorialIssueCounts['seo_description']['count'] ?? 0 }}</div>
        <div class="text-sm text-gray-500 mt-1">{{ __('admin.dashboard_editorial_seo_description') }}</div>
    </a>
    @if($hasSecondaryPublicLocales)
        <a href="{{ route('admin.editorial-control.index', ['issue' => 'translations', 'translation_state' => 'pending']) }}#translations"
           class="block bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5 hover:ring-white/[0.12] transition-all">
            <div class="text-2xl font-semibold text-white">{{ $pendingTranslationsCount }}</div>
            <div class="text-sm text-gray-500 mt-1">{{ __('admin.dashboard_editorial_translations_pending') }}</div>
        </a>
    @else
        <div class="block bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5">
            <div class="text-2xl font-semibold text-gray-500">-</div>
            <div class="text-sm text-gray-500 mt-1">{{ __('admin.dashboard_editorial_translations_disabled') }}</div>
        </div>
    @endif
</div>

@if($hasSecondaryPublicLocales)
    <div class="mt-6 bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5">
        <div class="mb-4">
            <h2 class="text-sm font-semibold text-white">{{ __('admin.dashboard_translation_coverage_title') }}</h2>
            <p class="text-sm text-gray-500 mt-0.5">{{ __('admin.dashboard_translation_coverage_subtitle') }}</p>
        </div>

        <div class="space-y-4">
            @foreach($translationCoverage as $item)
                <div>
                    <div class="flex items-center justify-between gap-3 text-sm mb-1.5">
                        <div class="text-white font-medium">{{ $item['locale_name'] }} <span class="text-gray-500">({{ strtoupper($item['locale']) }})</span></div>
                        <div class="text-gray-400">{{ $item['published'] }}/{{ $item['total'] }} · {{ $item['percent'] }}%</div>
                    </div>
                    <div class="h-2 rounded-full bg-[#1a1a1a] overflow-hidden">
                        <div class="h-full bg-sky-500 rounded-full" style="width: {{ $item['percent'] }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif

@if(auth()->user()?->isAdmin())
    <div class="mt-6 bg-[#141414] ring-1 ring-white/[0.06] rounded-xl overflow-hidden">
        <div class="flex flex-col gap-3 px-5 py-4 border-b border-white/[0.06] sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-sm font-semibold text-white">{{ __('admin.dashboard_setup_title') }}</h2>
                <p class="text-sm text-gray-500 mt-0.5">{{ __('admin.dashboard_setup_subtitle') }}</p>
            </div>
            <form method="GET" class="flex items-center gap-2 text-sm text-gray-400">
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input type="checkbox"
                           name="show_completed"
                           value="1"
                           onchange="this.form.submit()"
                           {{ $showCompleted ? 'checked' : '' }}
                           class="h-4 w-4 rounded border-white/10 bg-[#1a1a1a] text-sky-500 focus:ring-sky-500/50">
                    <span>{{ __('admin.dashboard_show_completed') }}</span>
                </label>
            </form>
        </div>

        <table class="w-full">
            <thead>
                <tr class="border-b border-white/[0.06]">
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-5 py-3">{{ __('admin.col_title') }}</th>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-5 py-3 hidden md:table-cell">{{ __('admin.dashboard_task_status') }}</th>
                    <th class="text-right text-xs font-medium text-gray-500 uppercase tracking-wider px-5 py-3">{{ __('admin.col_actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/[0.04]">
                @forelse($pendingSetupTasks as $task)
                    <tr class="hover:bg-white/[0.02] transition-colors">
                        <td class="px-5 py-4 align-top">
                            <div class="text-sm font-medium text-white">{{ $task['title'] }}</div>
                            <div class="text-sm text-gray-500 mt-1">{{ $task['description'] }}</div>
                        </td>
                        <td class="px-5 py-4 align-top hidden md:table-cell">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-500/10 text-yellow-400">
                                {{ $task['status_label'] }}
                            </span>
                        </td>
                        <td class="px-5 py-4 align-top text-right">
                            @if($task['href'] && $task['action'])
                                <a href="{{ $task['href'] }}"
                                   class="inline-flex items-center gap-2 px-3 py-1.5 rounded-md bg-white/5 border border-white/10 text-sm text-gray-300 hover:bg-white/10 transition-colors">
                                    {{ $task['action'] }}
                                </a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-5 py-6 text-sm text-gray-500">
                            {{ __('admin.dashboard_no_pending_tasks') }}
                        </td>
                    </tr>
                @endforelse

                @if($showCompleted && $completedSetupTasks->isNotEmpty())
                    <tr class="bg-[#111111]">
                        <td colspan="3" class="px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-500">
                            {{ __('admin.dashboard_completed_section') }}
                        </td>
                    </tr>
                    @foreach($completedSetupTasks as $task)
                        <tr class="hover:bg-white/[0.02] transition-colors">
                            <td class="px-5 py-4 align-top">
                                <div class="text-sm font-medium text-white">{{ $task['title'] }}</div>
                                <div class="text-sm text-gray-500 mt-1">{{ $task['description'] }}</div>
                            </td>
                            <td class="px-5 py-4 align-top hidden md:table-cell">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-sky-500/10 text-sky-400">
                                    {{ $task['status_label'] }}
                                </span>
                            </td>
                            <td class="px-5 py-4 align-top text-right">
                                @if($task['href'] && $task['action'])
                                    <a href="{{ $task['href'] }}"
                                       class="inline-flex items-center gap-2 px-3 py-1.5 rounded-md bg-white/5 border border-white/10 text-sm text-gray-300 hover:bg-white/10 transition-colors">
                                        {{ $task['action'] }}
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                @endif
            </tbody>
        </table>
    </div>
@endif

<div class="mt-6 bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-sm font-semibold text-white">{{ __('admin.media_health') }}</h2>
        <a href="{{ route('admin.media.health') }}" class="text-xs text-sky-400 hover:text-sky-300 transition-colors">
            {{ __('admin.view_details') }}
        </a>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 text-sm">
        <div class="bg-[#1a1a1a] rounded-lg p-3">
            <div class="text-gray-500">{{ __('admin.total_media') }}</div>
            <div class="text-white font-semibold mt-1">{{ $mediaHealth['total_media'] }}</div>
        </div>
        <div class="bg-[#1a1a1a] rounded-lg p-3">
            <div class="text-gray-500">{{ __('admin.raster_images') }}</div>
            <div class="text-white font-semibold mt-1">{{ $mediaHealth['raster_images'] }}</div>
        </div>
        <div class="bg-[#1a1a1a] rounded-lg p-3">
            <div class="text-gray-500">{{ __('admin.webp_coverage') }}</div>
            <div class="text-white font-semibold mt-1">{{ $mediaHealth['webp_coverage_percent'] }}%</div>
        </div>
        <div class="bg-[#1a1a1a] rounded-lg p-3">
            <div class="text-gray-500">{{ __('admin.responsive_coverage') }}</div>
            <div class="text-white font-semibold mt-1">{{ $mediaHealth['responsive_coverage_percent'] }}%</div>
        </div>
        <div class="bg-[#1a1a1a] rounded-lg p-3">
            <div class="text-gray-500">{{ __('admin.bytes_saved') }}</div>
            <div class="text-white font-semibold mt-1">{{ number_format($mediaHealth['bytes_saved'] / 1048576, 2) }} MB</div>
        </div>
        <div class="bg-[#1a1a1a] rounded-lg p-3">
            <div class="text-gray-500">{{ __('admin.missing_files') }}</div>
            <div class="text-white font-semibold mt-1">{{ $mediaHealth['missing_files'] }}</div>
        </div>
    </div>
</div>
@endsection
