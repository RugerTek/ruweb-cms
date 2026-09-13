{{--
    Button destination selector (docs/live-editor-ui-ux.md, "Button Destination
    Selector"). A select of internal pages with `Custom` last, a manual input
    revealed only for Custom, and an always-visible "open in a new tab" checkbox.

    Params:
      $pageName, $pageValue     — input name / saved translation_group_id (or null)
      $urlName, $urlValue       — input name / saved custom URI
      $newTabName, $newTabValue — input name / saved boolean
      $pages                    — [translation_group_id => title] (LinkDestination::pageOptions())
      $label (optional)         — control label, defaults to "Destino del botón"
--}}
@php
    $pages = $pages ?? [];
    $pageValue = old(str_replace(['[', ']'], ['.', ''], $pageName), $pageValue ?? null);
    $urlValue = old(str_replace(['[', ']'], ['.', ''], $urlName), $urlValue ?? null);
    $isCustom = ! filled($pageValue) || ! array_key_exists($pageValue, $pages);
    $selected = $isCustom ? \App\Support\LinkDestination::CUSTOM : $pageValue;
    $inputClass = 'w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50';
    $uid = 'dest-' . md5($pageName);
@endphp
<div data-link-destination>
    <label for="{{ $uid }}-page" class="block text-sm font-medium text-gray-300 mb-1">{{ $label ?? __('admin.link_destination') }}</label>
    <select id="{{ $uid }}-page" name="{{ $pageName }}" data-link-destination-page
            class="w-full bg-[#1a1a1a] border border-white/10 text-gray-300 text-sm rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50">
        @foreach ($pages as $groupId => $title)
            <option value="{{ $groupId }}" @selected($selected === $groupId)>{{ $title }}</option>
        @endforeach
        <option value="{{ \App\Support\LinkDestination::CUSTOM }}" @selected($isCustom)>{{ __('admin.link_destination_custom') }}</option>
    </select>

    <div data-link-destination-custom class="mt-2 {{ $isCustom ? '' : 'hidden' }}">
        <label for="{{ $uid }}-url" class="sr-only">{{ __('admin.link_destination_custom_url') }}</label>
        <input type="text" id="{{ $uid }}-url" name="{{ $urlName }}" value="{{ $urlValue }}"
               placeholder="{{ __('admin.link_destination_custom_ph') }}" class="{{ $inputClass }}">
        <p class="text-xs text-gray-600 mt-1">{{ __('admin.link_destination_custom_help') }}</p>
    </div>

    <label class="mt-3 flex items-center gap-2 cursor-pointer">
        <input type="hidden" name="{{ $newTabName }}" value="0">
        <input type="checkbox" name="{{ $newTabName }}" value="1" @checked($newTabValue ?? false) class="rounded border-white/[0.12] bg-[#0a0a0a]">
        <span class="text-sm text-gray-300">{{ __('admin.link_open_new_tab') }}</span>
    </label>
</div>
