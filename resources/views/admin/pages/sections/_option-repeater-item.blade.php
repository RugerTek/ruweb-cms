{{--
    One administrable option of a select/radio field (docs/forms.md).
    Single-field items render as a flat row: the input inline, with move and
    delete controls on the right. No accordion (docs/live-editor-ui-ux.md,
    "Single-Field Items"). Used inside a generic [data-repeater]; `$field` is
    the repeater key, e.g. subject_options.
--}}
<div class="flex items-center gap-1 rounded-lg border border-white/[0.08] bg-[#141414] pl-3 pr-2 py-1.5" data-repeater-item>
    <label class="sr-only" for="option-{{ $field }}-{{ $index }}">{{ __('admin.field_option_label') }}</label>
    <input type="text" data-repeater-label-input
           id="option-{{ $field }}-{{ $index }}"
           name="content_json[sections][{{ $sectionKey }}][{{ $field }}][{{ $index }}][label]"
           value="{{ $option['label'] ?? '' }}"
           class="min-w-0 flex-1 bg-transparent border-0 text-white text-sm px-0 py-1 placeholder-gray-600 focus:outline-none focus:ring-0"
           placeholder="{{ __('admin.field_option_label_ph') }}">
    <div class="flex items-center gap-0.5 shrink-0">
        <button type="button" data-repeater-move-up title="{{ __('admin.btn_move_up') }}" aria-label="{{ __('admin.btn_move_up') }}"
                class="p-1.5 text-gray-500 hover:text-gray-300 hover:bg-white/5 rounded transition-colors disabled:opacity-25 disabled:pointer-events-none">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
        </button>
        <button type="button" data-repeater-move-down title="{{ __('admin.btn_move_down') }}" aria-label="{{ __('admin.btn_move_down') }}"
                class="p-1.5 text-gray-500 hover:text-gray-300 hover:bg-white/5 rounded transition-colors disabled:opacity-25 disabled:pointer-events-none">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
        </button>
        <button type="button" data-repeater-remove title="{{ __('admin.btn_remove_option') }}" aria-label="{{ __('admin.btn_remove_option') }}"
                class="p-1.5 text-red-500/70 hover:text-red-400 hover:bg-red-500/10 rounded transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
        </button>
    </div>
</div>
