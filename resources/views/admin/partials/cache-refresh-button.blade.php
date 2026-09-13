@if(auth()->user()?->isAdmin())
<form method="POST"
      action="{{ route('admin.cache.refresh') }}"
      data-confirm="{{ __('admin.cache_refresh_confirm') }}"
      data-confirm-title="{{ __('admin.cache_refresh') }}"
      data-confirm-label="{{ __('admin.cache_refresh') }}"
      data-confirm-tone="primary"
      class="flex-none">
    @csrf
    <button type="submit"
            title="{{ __('admin.cache_refresh_title') }}"
            class="inline-flex items-center gap-2 px-3 py-1.5 bg-amber-500/10 hover:bg-amber-500/20 text-amber-300 border border-amber-500/20 rounded-md text-xs font-medium transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        <span>{{ __('admin.cache_refresh') }}</span>
    </button>
</form>
@endif
