{{-- Bar of a RügerTek support session (EnforceSupportSession shares $supportSession): only the operator sees it. --}}
@if(isset($supportSession) && auth()->user()?->isSupport())
    <div class="{{ $barClass ?? '' }} flex items-center justify-center gap-3 px-3 py-1 bg-amber-500/90 text-xs font-medium text-black" data-support-bar>
        <span class="truncate">{{ __('support.bar', ['name' => $supportSession->operator_name, 'time' => $supportSession->expires_at->timezone(config('app.timezone'))->format('H:i')]) }}</span>
        <form method="POST" action="{{ route('admin.support.exit') }}" class="shrink-0">
            @csrf
            <button type="submit" class="underline underline-offset-2 hover:no-underline">{{ __('support.exit') }}</button>
        </form>
    </div>
@endif
