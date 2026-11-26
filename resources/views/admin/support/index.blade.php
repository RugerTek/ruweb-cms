@extends('admin.layouts.app')

@section('title', __('support.title'))

@section('content')
<div class="mb-6">
    <h1 class="text-lg font-semibold text-white">{{ __('support.title') }}</h1>
    <p class="text-sm text-gray-500 mt-0.5">{{ __('support.subtitle') }}</p>
    <p class="text-xs text-gray-600 mt-2 max-w-3xl">{{ __('support.intro') }} {{ __('support.retention_note', ['days' => $retentionDays]) }}</p>
</div>

<div class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-4 mb-6 flex flex-col sm:flex-row sm:items-center gap-4" data-support-access>
    <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2">
            <h2 class="text-sm font-semibold text-white">{{ __('support.access.title') }}</h2>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $access['allowed'] ? 'bg-emerald-500/10 text-emerald-400' : 'bg-gray-500/10 text-gray-400' }}">{{ $access['allowed'] ? __('support.access.on') : __('support.access.off') }}</span>
        </div>
        <p class="text-xs text-gray-500 mt-1 max-w-3xl">{{ $access['allowed'] ? __('support.access.help_on') : __('support.access.help_off') }}</p>
        @if ($access['changed_at'])
            <p class="text-xs text-gray-600 mt-1">{{ __($access['allowed'] ? 'support.access.changed_on' : 'support.access.changed_off', ['name' => $access['changed_by'], 'date' => $access['changed_at']->timezone(config('app.timezone'))->format('d/m/Y H:i')]) }}</p>
        @endif
        @unless ($canChangeAccess)
            <p class="text-xs text-amber-400/80 mt-1">{{ __('support.access.only_client_admins') }}</p>
        @endunless
        @error('confirm')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
    </div>
    @if ($canChangeAccess)
        <form method="POST" action="{{ route('admin.support-activity.access') }}" class="shrink-0"
              data-confirm="{{ $access['allowed'] ? __('support.access.confirm_off') : __('support.access.confirm_on') }}"
              data-confirm-title="{{ __('support.access.title') }}"
              data-confirm-label="{{ $access['allowed'] ? __('support.access.turn_off') : __('support.access.turn_on') }}"
              data-confirm-tone="{{ $access['allowed'] ? 'danger' : 'primary' }}">
            @csrf
            <input type="hidden" name="allowed" value="{{ $access['allowed'] ? 0 : 1 }}">
            <input type="hidden" name="confirm" value="1">
            <button type="submit" class="px-3 py-1.5 rounded-md text-sm font-medium {{ $access['allowed'] ? 'bg-white/[0.06] text-gray-200 hover:bg-white/[0.1]' : 'bg-sky-500 text-white hover:bg-sky-400' }} transition-colors">{{ $access['allowed'] ? __('support.access.turn_off') : __('support.access.turn_on') }}</button>
        </form>
    @endif
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
                            @if ($session->reason)
                                <p class="text-xs text-gray-300 mt-1 max-w-xs">{{ __('support.reason_line', ['reason' => $session->reason]) }}</p>
                            @endif
                            @if ($session->ticket)
                                <p class="text-xs text-gray-500 mt-0.5">{{ __('support.ticket_line', ['ticket' => $session->ticket]) }}</p>
                            @endif
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
