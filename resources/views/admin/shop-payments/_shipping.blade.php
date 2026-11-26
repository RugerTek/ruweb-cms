{{--
    Shipping on Admin > Payments and shipping (PLAN.md P2, E3.4): store pickup (fields of #shop-payments-form via the
    `form` attribute, so they save with the main form) and delivery zones with a fixed price, each zone with its own
    form (Admin\ShippingZoneController). Receives $zones, $pickupEnabled, $pickupAddress, $currency and $input.
--}}
@php
    $priceValue = fn ($zone): string => number_format($zone->price_amount?->amount ?? 0, 0, ',', '.');
@endphp
<section id="shipping" class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5" data-shipping>
    <h2 class="text-base font-semibold text-white">{{ __('checkout.shipping_title') }}</h2>

    {{-- Store pickup (free) --}}
    <div class="mt-4 rounded-lg ring-1 ring-white/[0.06] p-4 space-y-3">
        <label class="inline-flex items-center gap-2 text-sm text-gray-200">
            <input type="hidden" name="shop_pickup_enabled" value="0" form="shop-payments-form">
            <input type="checkbox" name="shop_pickup_enabled" value="1" form="shop-payments-form" @checked(old('shop_pickup_enabled', $pickupEnabled)) class="rounded border-white/20 bg-[#1a1a1a] text-sky-600">
            {{ __('checkout.pickup_enabled') }}
        </label>
        <label class="block">
            <span class="block text-xs font-medium text-gray-400 mb-1">{{ __('checkout.pickup_address') }}</span>
            <textarea name="shop_pickup_address" rows="2" maxlength="500" form="shop-payments-form" class="{{ $input }}">{{ old('shop_pickup_address', $pickupAddress) }}</textarea>
        </label>
        <button type="submit" form="shop-payments-form" class="px-3 py-2 bg-white/5 hover:bg-white/10 border border-white/10 text-white text-sm font-medium rounded-md transition-colors">
            {{ __('checkout.save') }}
        </button>
    </div>

    {{-- Delivery zones --}}
    <h3 class="text-sm font-semibold text-white mt-6">{{ __('checkout.zones') }}</h3>
    @if ($zones->isEmpty())
        <p class="text-sm text-gray-500 mt-2">{{ __('checkout.zones_empty') }}</p>
    @else
        <div class="mt-3 space-y-2">
            @foreach ($zones as $zone)
                <div class="flex flex-wrap items-end gap-2 rounded-lg ring-1 ring-white/[0.06] p-3" data-zone="{{ $zone->id }}">
                    <form method="POST" action="{{ route('admin.shipping-zones.update', ['zone' => $zone->id]) }}" class="flex flex-wrap items-end gap-2 flex-1">
                        @csrf
                        @method('PUT')
                        <label class="block flex-1 min-w-[10rem]">
                            <span class="block text-xs text-gray-500 mb-1">{{ __('checkout.zone_name') }}</span>
                            <input type="text" name="name" value="{{ $zone->name }}" required maxlength="120" class="{{ $input }}">
                        </label>
                        <label class="block w-36">
                            <span class="block text-xs text-gray-500 mb-1">{{ __('checkout.zone_price') }} ({{ $currency }})</span>
                            <input type="text" name="price" value="{{ $priceValue($zone) }}" required inputmode="numeric" class="{{ $input }}">
                        </label>
                        <label class="block w-20">
                            <span class="block text-xs text-gray-500 mb-1">{{ __('checkout.method_sort_order') }}</span>
                            <input type="number" name="sort_order" value="{{ $zone->sort_order }}" min="0" max="9999" class="{{ $input }}">
                        </label>
                        <label class="inline-flex items-center gap-2 text-sm text-gray-300 pb-2">
                            <input type="hidden" name="enabled" value="0">
                            <input type="checkbox" name="enabled" value="1" @checked($zone->enabled) class="rounded border-white/20 bg-[#1a1a1a] text-sky-600">
                            {{ __('checkout.zone_enabled') }}
                        </label>
                        <button type="submit" class="px-3 py-2 bg-white/5 hover:bg-white/10 border border-white/10 text-white text-sm font-medium rounded-md transition-colors">{{ __('checkout.save') }}</button>
                    </form>
                    <form method="POST" action="{{ route('admin.shipping-zones.destroy', ['zone' => $zone->id]) }}"
                          data-confirm="{{ __('checkout.zone_delete_confirm', ['name' => $zone->name]) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="px-3 py-2 text-sm text-red-400 hover:text-red-300" aria-label="{{ __('checkout.zone_delete_confirm', ['name' => $zone->name]) }}">✕</button>
                    </form>
                </div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('admin.shipping-zones.store') }}" class="mt-4 flex flex-wrap items-end gap-2 rounded-lg border border-dashed border-white/10 p-3" data-zone-new>
        @csrf
        <label class="block flex-1 min-w-[10rem]">
            <span class="block text-xs text-gray-500 mb-1">{{ __('checkout.zone_name') }}</span>
            <input type="text" name="name" required maxlength="120" placeholder="Asunción" class="{{ $input }}">
        </label>
        <label class="block w-36">
            <span class="block text-xs text-gray-500 mb-1">{{ __('checkout.zone_price') }} ({{ $currency }})</span>
            <input type="text" name="price" required inputmode="numeric" placeholder="25.000" class="{{ $input }}">
        </label>
        <input type="hidden" name="enabled" value="1">
        <button type="submit" class="px-3 py-2 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">{{ __('checkout.zone_add') }}</button>
    </form>
</section>
