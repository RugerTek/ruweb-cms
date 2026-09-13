{{--
    One setting rendered by its `type` (docs/admin/settings.md). Shared by the
    Settings tabs and the Header / Footer screens so a setting looks and
    behaves the same wherever it is edited.

    Params: $setting (App\Models\Setting). Optional: $label, $help to override
    the `admin.settings_fields.<key>` translations.
--}}
@php
    $labelKey = 'admin.settings_fields.' . $setting->key . '.label';
    $helpKey = 'admin.settings_fields.' . $setting->key . '.help';
    $label = $label ?? (__($labelKey) !== $labelKey ? __($labelKey) : str_replace('_', ' ', $setting->key));
    $help = $help ?? (__($helpKey) !== $helpKey ? __($helpKey) : null);
    $inputClass = 'w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50';
    $fieldError = $errors->first('settings.' . $setting->key) ?: $errors->first('settings.' . $setting->key . '.*');
@endphp
<div class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-4">
    <label class="block text-sm font-medium text-gray-300 mb-1 capitalize">
        {{ $label }}
    </label>
    @if ($help)
        <p class="text-xs text-gray-500 mb-2">{{ $help }}</p>
    @endif

    @if ($setting->type === 'select')
        <select name="settings[{{ $setting->key }}]"
                class="w-full bg-[#1a1a1a] border border-white/10 text-gray-400 text-sm rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50">
            @foreach ($setting->options ?? [] as $optValue => $optLabel)
                @php
                    $optKey = 'admin.settings_options.' . $setting->key . '.' . $optValue;
                    $translatedOptLabel = __($optKey) !== $optKey ? __($optKey) : $optLabel;
                @endphp
                <option value="{{ $optValue }}" {{ $setting->value === $optValue ? 'selected' : '' }}>
                    {{ $translatedOptLabel }}
                </option>
            @endforeach
        </select>

    @elseif ($setting->type === 'text')
        <textarea name="settings[{{ $setting->key }}]" rows="3" class="{{ $inputClass }}">{{ old('settings.' . $setting->key, $setting->value) }}</textarea>

    @elseif ($setting->type === 'boolean')
        <label class="relative inline-flex items-center cursor-pointer">
            <input type="hidden" name="settings[{{ $setting->key }}]" value="0">
            <input type="checkbox" name="settings[{{ $setting->key }}]" value="1"
                   {{ $setting->value ? 'checked' : '' }}
                   class="sr-only peer">
            <div class="w-9 h-5 bg-gray-700 peer-focus:ring-2 peer-focus:ring-sky-500/50 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-sky-600"></div>
        </label>

    @elseif ($setting->type === 'integer')
        <input type="number" name="settings[{{ $setting->key }}]"
               value="{{ old('settings.' . $setting->key, $setting->value) }}"
               class="{{ $inputClass }}">

    @elseif ($setting->type === 'media')
        @include('admin.media._selector', [
            'name' => 'settings[' . $setting->key . ']',
            'value' => $setting->value,
            'label' => '',
        ])

    @elseif ($setting->type === 'password')
        @php $hasValue = ! empty($setting->value); @endphp
        <div class="relative">
            <input type="password" name="settings[{{ $setting->key }}]"
                   autocomplete="new-password"
                   placeholder="{{ $hasValue ? __('admin.password_stored_placeholder') : '' }}"
                   class="{{ $inputClass }} pr-20">
            <button type="button"
                    class="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-gray-400 hover:text-sky-400 px-2 py-1"
                    onclick="togglePasswordVisibility(this)">
                {{ __('admin.show') }}
            </button>
        </div>
        @if ($hasValue)
            <p class="mt-1.5 text-xs text-gray-500">{{ __('admin.password_stored_hint') }}</p>
        @endif

    @elseif ($setting->type === 'social_links')
        @include('admin.settings._social-links', ['setting' => $setting])

    @elseif ($setting->type === 'rich_text')
        {{-- Short running text with links; sanitised on save (docs/admin/rich-text.md). --}}
        <textarea name="settings[{{ $setting->key }}]" rows="3" data-rich-text="short-description" class="{{ $inputClass }}">{{ old('settings.' . $setting->key, $setting->value) }}</textarea>

    @elseif ($setting->type === 'map_point')
        @include('admin.settings._map-point', ['setting' => $setting])

    @else
        <input type="text" name="settings[{{ $setting->key }}]"
               value="{{ old('settings.' . $setting->key, $setting->value) }}"
               class="{{ $inputClass }}">
    @endif

    @if ($fieldError)
        <p class="mt-1.5 text-xs text-red-400">{{ $fieldError }}</p>
    @endif
</div>
