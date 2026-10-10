@php
    $itemUid = 'testimonial-'.\Illuminate\Support\Str::uuid();
    $itemLabel = filled($item['author'] ?? null)
        ? $item['author']
        : __('admin.testimonial_item', ['position' => is_numeric($index) ? $index + 1 : 1]);
@endphp

<div class="testimonial-repeater-item rounded-lg border border-white/[0.08] bg-[#141414] overflow-hidden" data-repeater-item>
    <div class="flex items-center gap-1 px-3 py-2">
        <button type="button"
                data-repeater-toggle
                aria-expanded="false" aria-controls="{{ $itemUid }}-body"
                class="flex items-center gap-2 min-w-0 flex-1 text-left">
            <svg data-repeater-icon class="w-4 h-4 shrink-0 text-gray-600 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
            <span data-repeater-item-label class="text-sm text-gray-300 truncate">{{ $itemLabel }}</span>
        </button>

        <div class="flex items-center gap-0.5 shrink-0">
            <button type="button" data-repeater-move-up
                    title="{{ __('admin.btn_move_up') }}"
                    aria-label="{{ __('admin.btn_move_up') }}"
                    class="p-1.5 text-gray-500 hover:text-gray-300 hover:bg-white/5 rounded transition-colors disabled:opacity-25 disabled:pointer-events-none">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/>
                </svg>
            </button>
            <button type="button" data-repeater-move-down
                    title="{{ __('admin.btn_move_down') }}"
                    aria-label="{{ __('admin.btn_move_down') }}"
                    class="p-1.5 text-gray-500 hover:text-gray-300 hover:bg-white/5 rounded transition-colors disabled:opacity-25 disabled:pointer-events-none">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>
            <button type="button" data-repeater-remove
                    title="{{ __('admin.testimonial_remove') }}"
                    aria-label="{{ __('admin.testimonial_remove') }}"
                    class="p-1.5 text-red-500/70 hover:text-red-400 hover:bg-red-500/10 rounded transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
            </button>
        </div>
    </div>

    <div id="{{ $itemUid }}-body" data-repeater-body class="hidden border-t border-white/[0.08] p-3 space-y-3">
        @foreach(['author', 'role', 'quote'] as $field)
            <div data-field-wrapper>
                <label for="{{ $itemUid }}-{{ $field }}" class="block text-xs font-medium text-gray-500 mb-1">{{ __('admin.testimonial_'.$field) }}</label>
                @if($field === 'quote')
                    <textarea id="{{ $itemUid }}-{{ $field }}" name="content_json[sections][{{ $sectionKey }}][items][{{ $index }}][{{ $field }}]" rows="4" class="w-full bg-[#1a1a1a] border border-white/10 text-white rounded-md px-3 py-2">{{ $item[$field] ?? '' }}</textarea>
                @else
                    <input id="{{ $itemUid }}-{{ $field }}" type="text" @if($field === 'author') data-repeater-label-input @endif name="content_json[sections][{{ $sectionKey }}][items][{{ $index }}][{{ $field }}]" value="{{ $item[$field] ?? '' }}" class="w-full bg-[#1a1a1a] border border-white/10 text-white rounded-md px-3 py-2">
                @endif
            </div>
        @endforeach
        @include('admin.media._selector', [
            'name' => "content_json[sections][{$sectionKey}][items][{$index}][portrait_id]",
            'value' => $item['portrait_id'] ?? null,
            'label' => __('admin.testimonial_portrait'),
            'type' => 'image', 'placeholder' => 'square',
        ])
    </div>
</div>
