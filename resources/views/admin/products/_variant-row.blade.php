{{--
    One variant row of the product editor (generic [data-repeater] of the base: add, remove, move up/down).
    Names re-index as items[variants][<index>][...]; `id` keeps a stored variant, an empty one creates it.
    `$row` is either the old input after a failed save or ProductController's form shape of a stored variant.
--}}
@php
    $row = $row ?? [];
    $field = fn (string $key) => "items[variants][{$index}][{$key}]";
    $input = 'w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50';
@endphp
<div class="rounded-lg border border-white/[0.08] bg-[#141414]" data-repeater-item data-variant-row>
    <div class="flex items-center gap-2 px-3 py-2">
        <button type="button" data-repeater-toggle aria-expanded="false" class="flex flex-1 min-w-0 items-center gap-2 text-left">
            <svg data-repeater-icon class="w-3.5 h-3.5 text-gray-500 shrink-0 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            <span data-repeater-item-label class="truncate text-sm text-white">{{ $row['name'] ?? '' }}</span>
            @if(filled($row['sku'] ?? null))<span class="text-xs text-gray-600 shrink-0">{{ $row['sku'] }}</span>@endif
        </button>
        <div class="flex items-center gap-0.5 shrink-0">
            <button type="button" data-repeater-move-up title="{{ __('admin.btn_move_up') }}" aria-label="{{ __('admin.btn_move_up') }}"
                    class="p-1.5 text-gray-500 hover:text-gray-300 hover:bg-white/5 rounded transition-colors disabled:opacity-25 disabled:pointer-events-none">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
            </button>
            <button type="button" data-repeater-move-down title="{{ __('admin.btn_move_down') }}" aria-label="{{ __('admin.btn_move_down') }}"
                    class="p-1.5 text-gray-500 hover:text-gray-300 hover:bg-white/5 rounded transition-colors disabled:opacity-25 disabled:pointer-events-none">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <button type="button" data-repeater-remove title="{{ __('shop.remove_variant') }}" aria-label="{{ __('shop.remove_variant') }}"
                    class="p-1.5 text-red-500/70 hover:text-red-400 hover:bg-red-500/10 rounded transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            </button>
        </div>
    </div>
    <div data-repeater-body class="hidden border-t border-white/[0.06] px-3 py-3 space-y-3">
        <input type="hidden" name="{{ $field('id') }}" value="{{ $row['id'] ?? '' }}">
        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label for="variant-name-{{ $index }}" class="block text-xs font-medium text-gray-500 mb-1">{{ __('shop.field_variant_name') }}</label>
                <input type="text" id="variant-name-{{ $index }}" name="{{ $field('name') }}" value="{{ $row['name'] ?? '' }}" maxlength="255" data-repeater-label-input class="{{ $input }}">
            </div>
            <div>
                <label for="variant-sku-{{ $index }}" class="block text-xs font-medium text-gray-500 mb-1">{{ __('shop.field_sku') }}</label>
                <input type="text" id="variant-sku-{{ $index }}" name="{{ $field('sku') }}" value="{{ $row['sku'] ?? '' }}" maxlength="100" class="{{ $input }}">
            </div>
            <div>
                <label for="variant-price-{{ $index }}" class="block text-xs font-medium text-gray-500 mb-1">{{ __('shop.field_price') }} <span class="text-gray-600">· {{ __('shop.currency_label') }}</span></label>
                <input type="text" inputmode="numeric" id="variant-price-{{ $index }}" name="{{ $field('price_amount') }}" value="{{ $row['price_amount'] ?? '' }}" class="{{ $input }}">
            </div>
            <div>
                <label for="variant-compare-{{ $index }}" class="block text-xs font-medium text-gray-500 mb-1">{{ __('shop.field_compare_at_price') }}</label>
                <input type="text" inputmode="numeric" id="variant-compare-{{ $index }}" name="{{ $field('compare_at_price_amount') }}" value="{{ $row['compare_at_price_amount'] ?? '' }}" class="{{ $input }}">
            </div>
            <div>
                <label for="variant-stock-{{ $index }}" class="block text-xs font-medium text-gray-500 mb-1">{{ __('shop.field_stock') }}</label>
                <input type="number" step="1" id="variant-stock-{{ $index }}" name="{{ $field('stock') }}" value="{{ $row['stock'] ?? '' }}" class="{{ $input }}">
            </div>
            <div>
                <label for="variant-weight-{{ $index }}" class="block text-xs font-medium text-gray-500 mb-1">{{ __('shop.field_weight_grams') }}</label>
                <input type="number" min="0" step="1" id="variant-weight-{{ $index }}" name="{{ $field('weight_grams') }}" value="{{ $row['weight_grams'] ?? '' }}" class="{{ $input }}">
            </div>
        </div>
        <div>
            <label for="variant-attributes-{{ $index }}" class="block text-xs font-medium text-gray-500 mb-1">{{ __('shop.field_variant_attributes') }}</label>
            <textarea id="variant-attributes-{{ $index }}" name="{{ $field('attributes') }}" rows="2" class="{{ $input }}"
                      placeholder="{{ __('shop.field_variant_attribute_key') }}: {{ __('shop.field_variant_attribute_value') }}">{{ $row['attributes'] ?? '' }}</textarea>
        </div>
    </div>
</div>
