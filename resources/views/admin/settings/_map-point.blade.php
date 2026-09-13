{{--
    Map picker for a `map_point` setting (docs/layout-header-footer.md). The
    client drops or drags a pin; latitude and longitude stay visible as small
    inputs so coordinates can also be pasted. Leaflet loads lazily from
    resources/js/map-picker.js when [data-map-picker] is on the page.
--}}
@php
    $point = old('settings.' . $setting->key);
    if (! is_array($point)) {
        $decoded = json_decode((string) $setting->value, true);
        $point = is_array($decoded) ? $decoded : [];
    }
    $lat = $point['lat'] ?? '';
    $lng = $point['lng'] ?? '';
    $maps = config('cms.maps');
    $inputClass = 'w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50';
@endphp
<div data-map-picker
     data-tiles-url="{{ $maps['tiles_url'] }}"
     data-attribution="{{ $maps['attribution'] }}"
     data-default-center="{{ implode(',', $maps['default_center']) }}"
     data-default-zoom="{{ $maps['default_zoom'] }}"
     data-pinned-zoom="{{ $maps['pinned_zoom'] }}">
    <div data-map-canvas class="h-72 w-full rounded-lg overflow-hidden ring-1 ring-white/[0.08] bg-[#1a1a1a]"></div>
    <p class="text-xs text-gray-600 mt-2">{{ __('admin.map_point_hint') }}</p>
    <div class="mt-3 grid grid-cols-1 sm:grid-cols-[1fr_1fr_auto] gap-2 items-end">
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1" for="map-lat-{{ $setting->key }}">{{ __('admin.map_point_lat') }}</label>
            <input type="text" inputmode="decimal" id="map-lat-{{ $setting->key }}" name="settings[{{ $setting->key }}][lat]" value="{{ $lat }}" data-map-lat class="{{ $inputClass }}" placeholder="-25.2961824">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1" for="map-lng-{{ $setting->key }}">{{ __('admin.map_point_lng') }}</label>
            <input type="text" inputmode="decimal" id="map-lng-{{ $setting->key }}" name="settings[{{ $setting->key }}][lng]" value="{{ $lng }}" data-map-lng class="{{ $inputClass }}" placeholder="-57.6359">
        </div>
        <button type="button" data-map-clear
                class="px-3 py-2 bg-white/5 hover:bg-white/10 border border-white/10 text-gray-300 text-sm font-medium rounded-md transition-colors">
            {{ __('admin.map_point_clear') }}
        </button>
    </div>
</div>
