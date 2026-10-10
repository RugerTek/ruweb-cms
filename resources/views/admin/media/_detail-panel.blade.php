<div data-base-locale="{{ $mediaBaseLocale }}" id="detail-panel" class="hidden fixed inset-y-0 right-0 z-50 w-full max-w-xs bg-[#111111] border-l border-white/[0.06] shadow-2xl overflow-y-auto">
    <div class="flex items-center justify-between px-5 py-4 border-b border-white/[0.06]">
        <h2 class="text-sm font-semibold text-white">{{ __('admin.details') }}</h2>
        <button id="detail-close" aria-label="{{ __('admin.close') }}" class="text-gray-600 hover:text-gray-400 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>

    <div class="p-5">
        <div id="detail-preview" class="mb-4 flex items-center justify-center bg-[#1a1a1a] rounded-lg overflow-hidden min-h-32"></div>

        <div class="mb-4">
            <p id="detail-filename" class="text-sm font-medium text-gray-300 break-all"></p>
            <p id="detail-size" class="text-xs text-gray-600 mt-0.5"></p>
        </div>

        <div class="mb-4">
            <label for="detail-url" class="block text-xs font-medium text-gray-500 mb-1">{{ __('admin.field_media_url') }}</label>
            <div class="flex items-center gap-2">
                <input type="text" id="detail-url" readonly
                    class="flex-1 min-w-0 px-3 py-2 bg-[#1a1a1a] border border-white/10 rounded-md text-gray-300 text-xs focus:outline-none select-all">
                <button type="button" id="detail-copy-url" title="{{ __('admin.copy') }}"
                    class="shrink-0 p-2 text-gray-500 hover:text-gray-300 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                    </svg>
                </button>
            </div>
        </div>

        <div class="mb-4">
            <label for="detail-locale" class="block text-xs font-medium text-gray-500 mb-1">{{ __('admin.media_metadata_language') }}</label>
            <select id="detail-locale" class="w-full px-3 py-2 bg-[#1a1a1a] border border-white/10 rounded-md text-white text-sm">
                <option value="{{ $mediaBaseLocale }}">{{ $mediaLocales->firstWhere('code', $mediaBaseLocale)?->name ?? strtoupper($mediaBaseLocale) }} — {{ __('admin.media_metadata_base') }}</option>
                @foreach ($mediaLocales->where('code', '!=', $mediaBaseLocale) as $locale)
                    <option value="{{ $locale->code }}">{{ $locale->name }} ({{ $locale->code }})</option>
                @endforeach
            </select>
            <p id="detail-metadata-help" class="text-xs text-gray-500 mt-2">{{ __('admin.media_metadata_help') }}</p>
        </div>

        <div class="mb-3">
            <label for="detail-alt" class="block text-xs font-medium text-gray-500 mb-1">{{ __('admin.field_alt_text') }}</label>
            <input type="text" id="detail-alt" maxlength="255" aria-describedby="detail-metadata-help"
                class="w-full px-3 py-2 bg-[#1a1a1a] border border-white/10 rounded-md text-white text-sm focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50">
        </div>

        <div class="mb-5">
            <label for="detail-title" class="block text-xs font-medium text-gray-500 mb-1">{{ __('admin.field_media_title') }}</label>
            <input type="text" id="detail-title" maxlength="255" aria-describedby="detail-metadata-help"
                class="w-full px-3 py-2 bg-[#1a1a1a] border border-white/10 rounded-md text-white text-sm focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50">
        </div>

        <div class="mb-5 border-t border-white/[0.06] pt-4">
            <button type="button"
                    id="detail-optimization-toggle"
                    class="w-full flex items-center justify-between gap-2 text-left">
                <h3 class="text-xs font-semibold text-white uppercase tracking-wide">{{ __('admin.media_optimization') }}</h3>
                <svg id="detail-optimization-chevron" class="w-4 h-4 text-gray-500 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <div id="detail-optimization-content" class="hidden mt-3">
                <div class="space-y-2 mb-3">
                    <div class="flex items-center justify-between gap-3 text-xs">
                        <span class="text-gray-500">{{ __('admin.optimization_status') }}</span>
                        <span id="detail-optimization-status" class="text-gray-300"></span>
                    </div>
                    <div class="flex items-center justify-between gap-3 text-xs">
                        <span class="text-gray-500">{{ __('admin.optimization_eligible') }}</span>
                        <span id="detail-optimization-eligible" class="text-gray-300"></span>
                    </div>
                    <div class="flex items-center justify-between gap-3 text-xs">
                        <span class="text-gray-500">{{ __('admin.optimization_original_size') }}</span>
                        <span id="detail-original-size" class="text-gray-300"></span>
                    </div>
                    <div class="flex items-center justify-between gap-3 text-xs">
                        <span class="text-gray-500">{{ __('admin.optimization_original_format') }}</span>
                        <span id="detail-original-extension" class="text-gray-300"></span>
                    </div>
                    <div class="flex items-center justify-between gap-3 text-xs">
                        <span class="text-gray-500">{{ __('admin.optimization_bytes_saved') }}</span>
                        <span id="detail-bytes-saved" class="text-sky-400"></span>
                    </div>
                    <div class="flex items-center justify-between gap-3 text-xs">
                        <span class="text-gray-500">{{ __('admin.optimization_ratio') }}</span>
                        <span id="detail-optimization-ratio" class="text-sky-400"></span>
                    </div>
                </div>

                <div id="detail-optimization-reason-wrap" class="hidden rounded-md border border-amber-500/20 bg-amber-500/5 px-2.5 py-2 mb-3">
                    <p class="text-[11px] uppercase tracking-wide text-amber-400 mb-1">{{ __('admin.optimization_reason') }}</p>
                    <p id="detail-optimization-reason" class="text-xs text-amber-200"></p>
                </div>

                <div>
                    <p class="text-xs font-medium text-gray-500 mb-2">{{ __('admin.optimization_variants') }}</p>
                    <ul id="detail-variants-list" class="space-y-1"></ul>
                    <p id="detail-no-variants" class="text-xs text-gray-600">{{ __('admin.optimization_no_variants') }}</p>
                </div>
            </div>
        </div>

        <section class="mb-4" aria-labelledby="detail-usage-title">
            <h3 id="detail-usage-title" class="text-xs font-medium text-gray-400 mb-2">{{ __('media_safety.usage') }}</h3>
            <ul id="detail-usage" class="text-xs text-gray-400 space-y-2" aria-live="polite"></ul>
        </section>
        <p id="detail-metadata-status" role="status" aria-live="polite" class="text-xs text-gray-300 mb-3"></p>
        <p id="detail-metadata-error" role="alert" class="hidden text-xs text-red-400 mb-3"></p>
        <div class="flex gap-2">
            <button id="detail-save" class="disabled:opacity-50 disabled:cursor-not-allowed flex-1 py-2 px-4 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
                {{ __('admin.btn_media_save') }}
            </button>
            <button id="detail-delete" class="py-2 px-3 bg-transparent hover:bg-red-500/10 text-red-500/70 hover:text-red-400 text-sm rounded-md border border-red-500/20 transition-colors">
                {{ __('admin.btn_delete') }}
            </button>
        </div>
    </div>
</div>
