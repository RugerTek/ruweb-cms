@extends('admin.layouts.app')

@section('title', __('admin.submissions'))

@section('content')
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6 gap-4">
    <div>
        <h1 class="text-lg font-semibold text-white">{{ __('admin.submissions') }}</h1>
        <p class="text-sm text-gray-500 mt-0.5">{{ __('admin.submissions_subtitle') }}</p>
    </div>
</div>

{{-- One tab per form registered in config('cms.forms') --}}
<div class="flex gap-1 mb-6 border-b border-white/[0.06] overflow-x-auto">
    @foreach($forms as $key => $config)
        <a href="{{ route('admin.submissions.index', ['form' => $key]) }}"
           class="px-3 py-2 text-sm font-medium border-b-2 whitespace-nowrap transition-colors {{ $key === $form ? 'border-sky-500 text-sky-400' : 'border-transparent text-gray-500 hover:text-gray-300' }}">
            {{ __('admin.forms.'.$key) }}
            @if(($unread[$key] ?? 0) > 0)
                <span class="ml-1 inline-flex items-center justify-center min-w-[1.25rem] px-1.5 py-0.5 rounded-full bg-emerald-500/15 text-emerald-400 text-xs">{{ $unread[$key] }}</span>
            @endif
        </a>
    @endforeach
</div>

<div class="flex flex-wrap gap-1 mb-4">
    @foreach([null => 'all', 'new' => 'submission_status_new', 'read' => 'submission_status_read'] as $value => $labelKey)
        <a href="{{ route('admin.submissions.index', array_filter(['form' => $form, 'status' => $value, 'search' => $filters['search'], 'from' => $filters['from'], 'to' => $filters['to']])) }}"
           class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors {{ $currentStatus === $value ? 'bg-sky-500/10 text-sky-400' : 'text-gray-500 hover:text-gray-300 hover:bg-white/[0.04]' }}">
            {{ __('admin.'.$labelKey) }}
            @if($value === null) <span class="text-gray-600 ml-1">{{ $totalCount }}</span>
            @elseif($value === 'new') <span class="text-gray-600 ml-1">{{ $unread[$form] ?? 0 }}</span>
            @endif
        </a>
    @endforeach
</div>

<form method="GET" class="flex flex-wrap gap-2 mb-6">
    @if($currentStatus)<input type="hidden" name="status" value="{{ $currentStatus }}">@endif
    <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="{{ __('admin.search_submissions_placeholder') }}"
           class="flex-1 min-w-[12rem] bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50">
    <input type="date" name="from" value="{{ $filters['from'] }}" title="{{ __('admin.filter_from') }}" class="bg-[#1a1a1a] border border-white/10 text-gray-300 text-sm rounded-md px-3 py-2">
    <input type="date" name="to" value="{{ $filters['to'] }}" title="{{ __('admin.filter_to') }}" class="bg-[#1a1a1a] border border-white/10 text-gray-300 text-sm rounded-md px-3 py-2">
    <button type="submit" class="px-3 py-2 bg-gray-800 border border-white/10 text-gray-400 text-sm rounded-md hover:bg-gray-700 transition-colors">{{ __('admin.search') }}</button>
    @if($filters['search'] || $filters['from'] || $filters['to'])
        <a href="{{ route('admin.submissions.index', ['form' => $form]) }}" class="px-3 py-2 text-gray-500 text-sm hover:text-gray-300">{{ __('admin.clear_filters') }}</a>
    @endif
</form>

@if($submissions->isEmpty())
    <div class="text-center py-20 text-gray-600">
        <svg class="w-12 h-12 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
        <p class="text-sm font-medium text-gray-500">{{ __('admin.no_submissions_yet') }}</p>
        <p class="text-sm text-gray-600 mt-1">{{ __('admin.no_submissions_hint') }}</p>
    </div>
@else
    <div class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr class="border-b border-white/[0.06]">
                    @include("admin.submissions.{$form}._columns")
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('admin.col_date') }}</th>
                    <th class="text-right text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('admin.col_actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/[0.04]">
                @foreach($submissions as $submission)
                    @php $url = route('admin.submissions.show', ['form' => $form, 'submission' => $submission->id]); @endphp
                    <tr class="hover:bg-white/[0.02] transition-colors {{ $submission->isRead() ? '' : 'bg-emerald-500/[0.03]' }}">
                        @include("admin.submissions.{$form}._row", ['submission' => $submission, 'url' => $url])
                        <td class="px-4 py-3 text-sm text-gray-400 whitespace-nowrap">
                            {{ $submission->created_at->format('d/m/Y H:i') }}
                            @unless($submission->isRead())
                                <span class="ml-2 inline-block w-2 h-2 rounded-full bg-emerald-400 align-middle" title="{{ __('admin.submission_status_new') }}"></span>
                            @endunless
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a href="{{ $url }}" class="text-sm text-sky-400 hover:text-sky-300">{{ __('admin.action_view') }}</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-6">{{ $submissions->links() }}</div>
@endif
@endsection
