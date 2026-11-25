@extends('admin.layouts.app')

@section('title', __('support.title'))

@section('content')
<div class="mb-6">
    <h1 class="text-lg font-semibold text-white">{{ __('support.title') }}</h1>
    <p class="text-sm text-gray-500 mt-0.5">{{ __('support.subtitle') }}</p>
    <p class="text-xs text-gray-600 mt-2 max-w-3xl">{{ __('support.intro') }} {{ __('support.retention_note', ['days' => $retentionDays]) }}</p>
</div>

@if ($sessions->isEmpty())
    <div class="text-center py-20 text-gray-600">
        <p class="text-sm font-medium text-gray-500">{{ __('support.empty') }}</p>
        <p class="text-sm text-gray-600 mt-1">{{ __('support.empty_hint') }}</p>
    </div>
@else
    <div class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl overflow-hidden">
        <table class="w-full">
            <thead>
                <tr class="border-b border-white/[0.06]">
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('support.col_operator') }}</th>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('support.col_started') }}</th>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3 hidden md:table-cell">{{ __('support.col_duration') }}</th>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('support.col_changes') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/[0.04]">
                @foreach ($sessions as $session)
                    @php $state = $session->state(); @endphp
                    <tr class="align-top hover:bg-white/[0.02] transition-colors">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.support-activity.show', $session->id) }}" class="text-sm text-white hover:text-sky-400 transition-colors">{{ $session->operator_name }}</a>
                            <p class="text-xs text-gray-500 mt-0.5">{{ $session->operator_email }}</p>
                            <p class="text-xs text-gray-600 mt-0.5">{{ __('support.roles.'.$session->role) }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <p class="text-sm text-gray-300">{{ $session->started_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</p>
                            <span class="inline-flex items-center mt-1 px-2 py-0.5 rounded-full text-xs font-medium {{ $state === 'active' ? 'bg-amber-500/10 text-amber-400' : 'bg-gray-500/10 text-gray-400' }}">{{ __('support.states.'.$state) }}</span>
                        </td>
                        <td class="px-4 py-3 hidden md:table-cell text-sm text-gray-400">{{ __('support.duration_minutes', ['minutes' => $session->durationMinutes()]) }}</td>
                        <td class="px-4 py-3">
                            <p class="text-xs text-gray-500">{{ trans_choice('support.changes_count', $session->changes_count, ['count' => $session->changes_count]) }} · {{ trans_choice('support.views_count', $session->views_count, ['count' => $session->views_count]) }}</p>
                            @if ($session->audits->isNotEmpty())
                                <ul class="mt-1 space-y-0.5">
                                    @foreach ($session->audits->take(\App\Http\Controllers\Admin\SupportActivityController::CHANGES_PREVIEW) as $audit)
                                        <li class="text-sm text-gray-300">{{ $summary->describe($audit) }}</li>
                                    @endforeach
                                </ul>
                                @if ($session->audits->count() > \App\Http\Controllers\Admin\SupportActivityController::CHANGES_PREVIEW)
                                    <p class="text-xs text-gray-500 mt-1">{{ __('support.more_changes', ['count' => $session->audits->count() - \App\Http\Controllers\Admin\SupportActivityController::CHANGES_PREVIEW]) }}</p>
                                @endif
                            @endif
                            <a href="{{ route('admin.support-activity.show', $session->id) }}" class="inline-block mt-1 text-xs text-sky-400 hover:text-sky-300">{{ __('support.view_detail') }}</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $sessions->links() }}</div>
@endif
@endsection
