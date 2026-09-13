{{--
    One social link row: network selector + URL, flat, with move and delete
    controls (docs/admin/layout.md). Inside a generic [data-repeater];
    `$key` is the setting key so names re-index as settings[<key>][<index>][...].
--}}
@php
    $networks = \App\Support\SocialNetworks::all();
    $network = $row['network'] ?? array_key_first($networks);
    $url = $row['url'] ?? '';
@endphp
<div class="flex flex-col sm:flex-row sm:items-center gap-2 rounded-lg border border-white/[0.08] bg-[#141414] px-2 py-1.5" data-repeater-item>
    <div class="flex items-center gap-2 sm:w-44 shrink-0">
        <span class="text-gray-500 shrink-0" aria-hidden="true">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor" data-social-icon>
                <path d="{{ \App\Support\SocialNetworks::iconPath($network) }}"/>
            </svg>
        </span>
        <label class="sr-only" for="social-network-{{ $index }}">{{ __('admin.social_link_network') }}</label>
        <select id="social-network-{{ $index }}"
                name="settings[{{ $key }}][{{ $index }}][network]"
                data-social-network
                class="min-w-0 flex-1 bg-[#1a1a1a] border border-white/10 text-gray-300 text-sm rounded-md px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50">
            @foreach ($networks as $networkKey => $definition)
                <option value="{{ $networkKey }}" data-icon="{{ $definition['icon'] }}" data-placeholder="{{ $definition['placeholder'] }}" @selected($networkKey === $network)>{{ $definition['label'] }}</option>
            @endforeach
        </select>
    </div>
    <label class="sr-only" for="social-url-{{ $index }}">{{ __('admin.social_link_url') }}</label>
    <input type="url" id="social-url-{{ $index }}" data-repeater-label-input
           name="settings[{{ $key }}][{{ $index }}][url]"
           value="{{ $url }}"
           placeholder="{{ \App\Support\SocialNetworks::placeholder($network) }}"
           class="min-w-0 flex-1 bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-1.5 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50">
    <div class="flex items-center gap-0.5 shrink-0 self-end sm:self-auto">
        <button type="button" data-repeater-move-up title="{{ __('admin.btn_move_up') }}" aria-label="{{ __('admin.btn_move_up') }}"
                class="p-1.5 text-gray-500 hover:text-gray-300 hover:bg-white/5 rounded transition-colors disabled:opacity-25 disabled:pointer-events-none">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
        </button>
        <button type="button" data-repeater-move-down title="{{ __('admin.btn_move_down') }}" aria-label="{{ __('admin.btn_move_down') }}"
                class="p-1.5 text-gray-500 hover:text-gray-300 hover:bg-white/5 rounded transition-colors disabled:opacity-25 disabled:pointer-events-none">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
        </button>
        <button type="button" data-repeater-remove title="{{ __('admin.btn_remove_social_link') }}" aria-label="{{ __('admin.btn_remove_social_link') }}"
                class="p-1.5 text-red-500/70 hover:text-red-400 hover:bg-red-500/10 rounded transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
        </button>
    </div>
</div>
