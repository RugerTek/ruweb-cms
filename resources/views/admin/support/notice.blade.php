{{-- Home notice of recent RügerTek support entries (docs/operations/support-access.md#client-notice): who, when and why,
     for the client's administrators only, during SupportNotices::DAYS days or until they dismiss it. --}}
@inject('supportNotices', 'App\Services\SupportAccess\SupportNotices')
@php $supportEntries = $supportNotices->for(auth()->user()); @endphp
@if ($supportEntries->isNotEmpty())
    @php $latestEntry = $supportEntries->first(); @endphp
    <div class="mb-6 p-4 rounded-xl bg-amber-500/10 ring-1 ring-amber-500/20 flex flex-col sm:flex-row sm:items-start gap-3" data-support-notice>
        <div class="flex-1 min-w-0 text-sm text-amber-100">
            <p>
                {{ __('support.notice.entered', ['name' => $latestEntry->operator_name, 'when' => $supportNotices->when($latestEntry->started_at)]) }}
                @if ($latestEntry->reason)
                    — {{ __('support.notice.reason', ['reason' => $latestEntry->reason]) }}
                @endif
                @if ($latestEntry->ticket)
                    <span class="text-amber-200/70">({{ __('support.ticket_line', ['ticket' => $latestEntry->ticket]) }})</span>
                @endif
            </p>
            @if ($supportEntries->count() > 1)
                <p class="text-xs text-amber-200/70 mt-1">{{ trans_choice('support.notice.more', $supportEntries->count() - 1, ['count' => $supportEntries->count() - 1, 'days' => \App\Services\SupportAccess\SupportNotices::DAYS]) }}</p>
            @endif
            <a href="{{ $supportEntries->count() > 1 ? route('admin.support-activity.index') : route('admin.support-activity.show', $latestEntry->id) }}" class="inline-block mt-1 text-xs font-medium text-amber-300 hover:text-amber-200 underline underline-offset-2">{{ __('support.notice.link') }}</a>
        </div>
        <form method="POST" action="{{ route('admin.support-activity.notice.dismiss') }}" class="shrink-0">
            @csrf
            <button type="submit" class="text-xs text-amber-200/80 hover:text-amber-100 transition-colors">{{ __('support.notice.dismiss') }}</button>
        </form>
    </div>
@endif
