{{--
    Shortcut card for the Header / Footer screens (docs/layout-header-footer.md).
    A part of the layout that is edited elsewhere gets one of these instead of
    a duplicated form: it says where the data lives and links straight there.

    Params: $title, $description, $href, $cta. Optional: $badge (short text
    shown next to the title, e.g. the current menu name).
--}}
<div class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-4 flex flex-col sm:flex-row sm:items-center gap-3">
    <div class="min-w-0 flex-1">
        <div class="flex items-center gap-2">
            <h3 class="text-sm font-medium text-gray-300">{{ $title }}</h3>
            @if (! empty($badge))
                <span class="text-[11px] px-1.5 py-0.5 rounded bg-white/[0.06] text-gray-400 truncate">{{ $badge }}</span>
            @endif
        </div>
        <p class="text-xs text-gray-500 mt-0.5">{{ $description }}</p>
    </div>
    <a href="{{ $href }}"
       class="shrink-0 inline-flex items-center gap-2 px-3 py-2 bg-white/5 hover:bg-white/10 border border-white/10 text-white text-sm font-medium rounded-md transition-colors">
        {{ $cta }}
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
    </a>
</div>
