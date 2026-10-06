{{-- Media selector component for embedding in forms --}}
{{-- Usage: @include('admin.media._selector', ['name' => 'featured_image', 'value' => $mediaId]) --}}

@php
    $name = $name ?? 'media_id';
    $value = $value ?? null;
    $label = $label ?? __('admin.choose_file');
    $type = $type ?? null;
    $placeholderKey = $placeholder ?? (str_contains($name, '[logos]') ? 'square' : 'horizontal');
    $placeholderMedia = in_array($type, ['document', 'file'], true) ? null : \App\Models\Media::where('placeholder_key', $placeholderKey)->first();
@endphp

<div class="media-selector" data-name="{{ $name }}" @if($type) data-type="{{ $type }}" @endif>
    <label class="block text-sm font-medium text-gray-300 mb-1">{{ $label }}</label>
    <input type="hidden" name="{{ $name }}" value="{{ $value }}" class="media-selector-input">

    <template data-media-placeholder>
        @if(!in_array($type, ['document', 'file'], true))
            <div class="rounded-md overflow-hidden bg-white/5" style="aspect-ratio: {{ ['horizontal' => '16/9', 'square' => '1/1', 'vertical' => '9/16'][$placeholderKey] ?? '16/9' }}">
                @if($placeholderMedia)
                    <img src="{{ $placeholderMedia->url() }}" alt="{{ __('admin.media_empty_placeholder') }}" class="w-full h-full object-cover opacity-60">
                @else
                    <svg viewBox="0 0 160 90" role="img" aria-label="{{ __('admin.media_empty_placeholder') }}" class="w-full h-full text-gray-600"><path fill="currentColor" d="M20 70 55 35 80 60 110 25 145 70Z"/><circle cx="35" cy="25" r="8" fill="currentColor"/></svg>
                @endif
            </div>
        @endif
    </template>
    <div class="media-selector-preview mb-2">
        {{-- Preview populated by JS when media is selected --}}
    </div>

    <div class="flex gap-2">
        <button type="button" class="media-selector-btn px-3 py-2 bg-gray-800 border border-gray-700 text-gray-300 text-sm rounded-md hover:bg-gray-700 transition-colors" data-label="{{ __('admin.choose_file') }}">
            {{ __('admin.choose_file') }}
        </button>
        <button type="button" class="media-selector-clear px-3 py-2 bg-gray-800 border border-gray-700 text-gray-500 text-sm rounded-md hover:bg-gray-700 transition-colors {{ $value ? '' : 'hidden' }}">
            {{ __('admin.clear') }}
        </button>
    </div>
</div>
