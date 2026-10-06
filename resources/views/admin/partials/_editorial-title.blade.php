@php $uid = 'title-'.md5($name); @endphp
<div data-title-editor data-field-wrapper>
    <div class="flex items-center justify-between gap-2 mb-1">
        <label id="{{ $uid }}-label" for="{{ $uid }}" class="text-xs font-medium text-gray-500">{{ $label ?? __('admin.field_section_heading') }}</label>
        <button type="button" data-title-highlight aria-pressed="false" class="text-xs text-sky-400 rounded px-2 py-1 focus-visible:ring-2 focus-visible:ring-sky-400">{{ __('admin.title_highlight') }}</button>
    </div>
    <div id="{{ $uid }}" contenteditable="true" role="textbox" aria-multiline="false" aria-labelledby="{{ $uid }}-label" data-title-visual
         class="min-h-10 w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-500/50"></div>
    <input type="hidden" name="{{ $name }}" value="{{ $value ?? '' }}" data-title-input>
    <p class="text-xs text-gray-600 mt-1">{{ __('admin.title_highlight_help') }}</p>
</div>
