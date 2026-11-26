@extends('admin.layouts.app')

@section('title', __('support.detail_title', ['name' => $session->operator_name]))

@section('content')
@php
    $tz = config('app.timezone');
    $state = $session->state();
@endphp
<div class="mb-6">
    <a href="{{ route('admin.support-activity.index') }}" class="inline-flex items-center gap-1 text-xs text-gray-500 hover:text-gray-300 transition-colors">{{ __('support.back') }}</a>
    <h1 class="text-lg font-semibold text-white mt-2">{{ __('support.detail_title', ['name' => $session->operator_name]) }}</h1>
    <p class="text-sm text-gray-500 mt-0.5">{{ $session->operator_email }} · {{ __('support.roles.'.$session->role) }}</p>
</div>

<div class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-4 mb-6">
    <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
        <div class="sm:col-span-2 lg:col-span-3">
            <dt class="text-xs text-gray-500">{{ __('support.reason') }}</dt>
            <dd class="text-gray-200">{{ $session->reason ?? __('support.reason_missing') }}@if ($session->ticket) <span class="text-gray-500">· {{ __('support.ticket_line', ['ticket' => $session->ticket]) }}</span>@endif</dd>
        </div>
        <div>
            <dt class="text-xs text-gray-500">{{ __('support.col_state') }}</dt>
            <dd class="text-gray-200">{{ __('support.states.'.$state) }}@if ($session->end_reason) · {{ __('support.end_reasons.'.$session->end_reason) }}@endif</dd>
        </div>
        <div>
            <dt class="text-xs text-gray-500">{{ __('support.started_at') }}</dt>
            <dd class="text-gray-200">{{ $session->started_at->timezone($tz)->format('d/m/Y H:i:s') }}</dd>
        </div>
        <div>
            <dt class="text-xs text-gray-500">{{ $session->ended_at ? __('support.ended_at') : __('support.expires_at') }}</dt>
            <dd class="text-gray-200">{{ $session->endedOrExpiresAt()->timezone($tz)->format('d/m/Y H:i:s') }}</dd>
        </div>
        <div>
            <dt class="text-xs text-gray-500">{{ __('support.col_duration') }}</dt>
            <dd class="text-gray-200">{{ __('support.duration_minutes', ['minutes' => $session->durationMinutes()]) }}</dd>
        </div>
        <div>
            <dt class="text-xs text-gray-500">{{ __('support.ip') }}</dt>
            <dd class="text-gray-200">{{ $session->ip }}</dd>
        </div>
        <div>
            <dt class="text-xs text-gray-500">{{ __('support.issuer') }}</dt>
            <dd class="text-gray-200">{{ $session->issuer }}</dd>
        </div>
        <div class="sm:col-span-2 lg:col-span-3">
            <dt class="text-xs text-gray-500">{{ __('support.user_agent') }}</dt>
            <dd class="text-gray-400 text-xs break-all">{{ $session->user_agent }}</dd>
        </div>
    </dl>
</div>

<h2 class="text-sm font-semibold text-white mb-3">{{ __('support.entries') }}</h2>
@if ($entries->isEmpty())
    <p class="text-sm text-gray-500">{{ __('support.entries_empty') }}</p>
@else
    <div class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl overflow-hidden">
        <table class="w-full">
            <thead>
                <tr class="border-b border-white/[0.06]">
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('support.col_when') }}</th>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('support.col_action') }}</th>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3 hidden md:table-cell">{{ __('support.col_fields') }}</th>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3 hidden sm:table-cell">{{ __('support.col_status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/[0.04]">
                @foreach ($entries as $entry)
                    <tr class="align-top">
                        <td class="px-4 py-2 text-xs text-gray-500 whitespace-nowrap">{{ $entry->at->timezone($tz)->format('H:i:s') }}</td>
                        <td class="px-4 py-2">
                            <p class="text-sm {{ $entry->isWrite() ? 'text-gray-200' : 'text-gray-500' }}">{{ $summary->describe($entry) }}</p>
                            <p class="text-xs text-gray-600 mt-0.5 break-all">{{ $entry->method }} {{ $entry->path }}@if ($entry->route_name) · {{ $entry->route_name }}@endif</p>
                        </td>
                        <td class="px-4 py-2 hidden md:table-cell text-xs text-gray-400 break-all">{{ implode(', ', $entry->changed_fields ?? []) }}</td>
                        <td class="px-4 py-2 hidden sm:table-cell text-xs {{ ($entry->status ?? 0) >= 400 ? 'text-red-400' : 'text-gray-500' }}">{{ $entry->status }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if ($entries->count() >= \App\Http\Controllers\Admin\SupportActivityController::DETAIL_LIMIT)
        <p class="text-xs text-gray-500 mt-2">{{ __('support.detail_limit_note', ['count' => \App\Http\Controllers\Admin\SupportActivityController::DETAIL_LIMIT]) }}</p>
    @endif
@endif
@endsection
