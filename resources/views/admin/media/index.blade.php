@extends('admin.layouts.app')

@section('title', __('admin.media'))

@section('content')
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6 gap-4">
    <h1 class="text-lg font-semibold text-white">{{ __('admin.media') }}</h1>

    <button id="upload-btn" class="inline-flex items-center gap-2 px-3 py-1.5 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        {{ __('admin.upload') }}
    </button>
</div>

<!-- Filters -->
<div class="flex flex-col sm:flex-row gap-3 mb-6">
    <form id="filter-form" method="GET" class="flex min-w-0 flex-1 flex-col sm:flex-row gap-2">
        <select id="filter-type" name="type" class="bg-[#1a1a1a] border border-white/10 text-gray-400 text-sm rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50">
            <option value="">{{ __('admin.all_types') }}</option>
            <option value="image" {{ request('type') === 'image' ? 'selected' : '' }}>{{ __('admin.images') }}</option>
            <option value="application" {{ request('type') === 'application' ? 'selected' : '' }}>{{ __('admin.documents') }}</option>
        </select>
        <select id="filter-month" name="month" class="bg-[#1a1a1a] border border-white/10 text-gray-400 text-sm rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50">
            <option value="">{{ __('admin.all_dates') }}</option>
            @foreach ($availableMonths as $month)
                <option value="{{ $month['value'] }}" {{ request('month') === $month['value'] ? 'selected' : '' }}>{{ $month['label'] }}</option>
            @endforeach
        </select>
        <input
            id="filter-search"
            type="text"
            name="search"
            value="{{ request('search') }}"
            placeholder="{{ __('admin.search_media_ph') }}"
            class="min-w-0 flex-1 bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50"
        >
    </form>
</div>

<!-- Media Grid -->
@if ($media->isEmpty())
    <div class="text-center py-20 text-gray-600">
        @if ($hasActiveFilters)
            <svg class="w-12 h-12 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z"/>
            </svg>
            <p class="text-sm font-medium text-gray-500">{{ __('admin.no_media_results') }}</p>
            <p class="text-sm text-gray-600 mt-1">{{ __('admin.no_media_results_hint') }}</p>
            <a href="{{ route('admin.media.index') }}" class="mt-4 inline-flex items-center gap-2 px-3 py-1.5 bg-[#1a1a1a] border border-white/10 hover:border-white/20 text-gray-300 text-sm font-medium rounded-md transition-colors">
                {{ __('admin.clear_filters') }}
            </a>
        @else
            <svg class="w-12 h-12 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
            <p class="text-sm font-medium text-gray-500">{{ __('admin.no_media_yet') }}</p>
            <p class="text-sm text-gray-600 mt-1">{{ __('admin.no_media_hint') }}</p>
            <button id="upload-btn-empty" class="mt-4 inline-flex items-center gap-2 px-3 py-1.5 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
                {{ __('admin.upload_file') }}
            </button>
        @endif
    </div>
@else
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
        @foreach ($media as $item)
            <div class="media-item relative bg-[#141414] ring-1 ring-white/[0.06] rounded-lg overflow-hidden cursor-pointer hover:ring-white/20 transition-all"
                 data-id="{{ $item->id }}"
                 data-url="{{ $item->url() }}"
                 data-title="{{ $item->title }}"
                 data-alt="{{ $item->alt }}"
                 data-metadata='@json($item->metadata)'
                 data-size="{{ $item->formattedSize() }}"
                 data-size-bytes="{{ $item->size }}"
                 data-mime="{{ $item->mime_type }}"
                 data-extension="{{ strtolower($item->extension) }}"
                 data-filename="{{ $item->original_filename }}"
                 data-is-image="{{ $item->isImage() ? '1' : '0' }}"
                 data-is-optimized="{{ $item->isOptimizedRaster() ? '1' : '0' }}"
                 data-optimized-at="{{ $item->optimized_at?->toIso8601String() }}"
                 data-original-size="{{ $item->original_size }}"
                 data-original-extension="{{ $item->original_extension }}"
                 data-bytes-saved="{{ $item->bytes_saved }}"
                 data-optimization-ratio="{{ $item->optimization_ratio }}"
                 data-has-variants="{{ $item->hasResponsiveVariants() ? '1' : '0' }}"
                 data-variants='@json($item->variants ?? [])'>
                <div class="aspect-square flex items-center justify-center bg-[#1a1a1a]">
                    @if ($item->isImage())
                        <img src="{{ $item->url() }}" alt="{{ $item->localizedAlt() }}" class="w-full h-full object-contain">
                    @else
                        <svg class="w-10 h-10 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                        </svg>
                    @endif
                </div>
                <div class="px-2 py-1.5">
                    <p class="text-xs text-gray-500 truncate">{{ $item->original_filename }}</p>
                    <p class="text-xs text-gray-700">{{ $item->formattedSize() }}</p>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-6">
        {{ $media->withQueryString()->links() }}
    </div>
@endif

@include('admin.media._detail-panel')

@push('scripts')
<script>
    // Reactive filters
    const filterType = document.getElementById('filter-type');
    const filterMonth = document.getElementById('filter-month');
    const filterSearch = document.getElementById('filter-search');

    const submitFilter = (keepFocus = false) => {
        const params = new URLSearchParams();
        if (filterType.value) params.set('type', filterType.value);
        if (filterMonth.value) params.set('month', filterMonth.value);
        if (filterSearch.value) params.set('search', filterSearch.value);
        if (keepFocus) params.set('focus', 'search');
        window.location.href = '?' + params.toString();
    };

    filterType.addEventListener('change', () => submitFilter(false));
    filterMonth.addEventListener('change', () => submitFilter(false));

    let debounceTimer;
    filterSearch.addEventListener('input', () => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => submitFilter(true), 400);
    });

    if (new URLSearchParams(window.location.search).get('focus') === 'search') {
        filterSearch.focus();
        filterSearch.setSelectionRange(filterSearch.value.length, filterSearch.value.length);
    }

    // Detail panel
    const detailPanel = document.getElementById('detail-panel');
    const detailClose = document.getElementById('detail-close');
    const optimizationToggle = document.getElementById('detail-optimization-toggle');
    const optimizationContent = document.getElementById('detail-optimization-content');
    const optimizationChevron = document.getElementById('detail-optimization-chevron');
    let activeItem = null;
    const formatBytes = (bytes) => {
        const value = Number(bytes || 0);
        if (!value) return '0 B';
        if (value >= 1048576) return `${(value / 1048576).toFixed(2)} MB`;
        if (value >= 1024) return `${(value / 1024).toFixed(2)} KB`;
        return `${value} B`;
    };

    const optimizationStatus = (item) => {
        if (item.dataset.isOptimized === '1') return '{{ __('admin.optimization_status_optimized') }}';
        if (['jpg', 'jpeg', 'png'].includes(item.dataset.extension || '')) return '{{ __('admin.optimization_status_pending') }}';
        return '{{ __('admin.optimization_status_not_applicable') }}';
    };

    const optimizationEligibility = (item) => {
        const extension = (item.dataset.extension || '').toLowerCase();
        const isImage = item.dataset.isImage === '1';
        const isOptimized = item.dataset.isOptimized === '1';

        if (isOptimized) {
            return { eligible: true, reason: '' };
        }

        if (!isImage) {
            return { eligible: false, reason: '{{ __('admin.optimization_reason_not_image') }}' };
        }
        if (['jpg', 'jpeg', 'png'].includes(extension)) {
            return { eligible: true, reason: '' };
        }
        if (extension === 'webp') {
            return { eligible: false, reason: '{{ __('admin.optimization_reason_already_webp') }}' };
        }
        if (['svg', 'gif'].includes(extension)) {
            return { eligible: false, reason: '{{ __('admin.optimization_reason_format_not_supported') }}' };
        }

        return { eligible: false, reason: '{{ __('admin.optimization_reason_format_not_supported') }}' };
    };

    const copyIconDefault = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>';
    const copyIconDone = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>';
    let copyResetTimer;

    const resetCopyIcon = () => {
        clearTimeout(copyResetTimer);
        const svg = document.getElementById('detail-copy-url')?.querySelector('svg');
        if (svg) svg.innerHTML = copyIconDefault;
    };

    document.querySelectorAll('.media-item').forEach(item => {
        item.addEventListener('click', async () => {
            if (!await window.mediaMetadata.open(item)) return;
            if (activeItem) activeItem.classList.remove('ring-2', 'ring-sky-500/60');
            activeItem = item;
            item.classList.add('ring-2', 'ring-sky-500/60');

            document.getElementById('detail-preview').innerHTML = item.dataset.mime.startsWith('image/')
                ? ''
                : `<div class="text-gray-700 text-center py-8"><svg class="w-14 h-14 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg></div>`;

            if (item.dataset.mime.startsWith('image/')) {
                const image = document.createElement('img');
                image.src = item.dataset.url;
                image.alt = item.dataset.alt || '';
                image.className = 'max-w-full max-h-56 rounded object-contain';
                document.getElementById('detail-preview').replaceChildren(image);
            }
            document.getElementById('detail-filename').textContent = item.dataset.filename;
            document.getElementById('detail-size').textContent = item.dataset.size;
            document.getElementById('detail-url').value = item.dataset.url;
            resetCopyIcon();
            detailPanel.dataset.mediaId = item.dataset.id;

            const eligibility = optimizationEligibility(item);
            document.getElementById('detail-optimization-status').textContent = optimizationStatus(item);
            document.getElementById('detail-optimization-eligible').textContent = eligibility.eligible
                ? '{{ __('admin.optimization_eligible_yes') }}'
                : '{{ __('admin.optimization_eligible_no') }}';
            document.getElementById('detail-original-size').textContent = item.dataset.originalSize
                ? formatBytes(item.dataset.originalSize)
                : '{{ __('admin.not_available') }}';
            document.getElementById('detail-original-extension').textContent = item.dataset.originalExtension
                ? item.dataset.originalExtension.toUpperCase()
                : '{{ __('admin.not_available') }}';
            document.getElementById('detail-bytes-saved').textContent = item.dataset.bytesSaved
                ? formatBytes(item.dataset.bytesSaved)
                : '{{ __('admin.not_available') }}';
            document.getElementById('detail-optimization-ratio').textContent = item.dataset.optimizationRatio
                ? `${item.dataset.optimizationRatio}%`
                : '{{ __('admin.not_available') }}';

            const reasonWrap = document.getElementById('detail-optimization-reason-wrap');
            const reasonValue = document.getElementById('detail-optimization-reason');
            if (eligibility.eligible) {
                reasonWrap.classList.add('hidden');
                reasonValue.textContent = '';
            } else {
                reasonWrap.classList.remove('hidden');
                reasonValue.textContent = eligibility.reason;
            }

            const variants = JSON.parse(item.dataset.variants || '[]');
            const variantsList = document.getElementById('detail-variants-list');
            const noVariants = document.getElementById('detail-no-variants');
            variantsList.innerHTML = '';
            if (Array.isArray(variants) && variants.length > 0) {
                noVariants.classList.add('hidden');
                variants.forEach((variant) => {
                    const width = variant.width ? `${variant.width}w` : '?w';
                    const size = variant.size ? formatBytes(variant.size) : '{{ __('admin.not_available') }}';
                    const li = document.createElement('li');
                    li.className = 'text-xs text-gray-400 flex items-center justify-between gap-2';
                    li.innerHTML = `<span>${width}</span><span>${size}</span>`;
                    variantsList.appendChild(li);
                });
            } else {
                noVariants.classList.remove('hidden');
            }

            optimizationContent?.classList.add('hidden');
            optimizationChevron?.classList.remove('rotate-180');

            detailPanel.classList.remove('hidden');
            const usage = document.getElementById('detail-usage');
            usage.textContent = @json(__('media_safety.loading'));
            try {
                const response = await fetch(`/admin/media/${item.dataset.id}`, { headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error();
                const payload = await response.json();
                if (activeItem !== item) return;
                usage.replaceChildren();
                if (!payload.usage?.length) usage.textContent = @json(__('media_safety.unused'));
                for (const reference of payload.usage || []) {
                    const row = document.createElement('li');
                    row.textContent = `${reference.label} — ${reference.field}`;
                    usage.appendChild(row);
                }
            } catch (_) {
                if (activeItem === item) usage.textContent = @json(__('media_safety.error'));
            }
        });
    });

    document.getElementById('detail-copy-url')?.addEventListener('click', (event) => {
        const input = document.getElementById('detail-url');
        const btn = event.currentTarget;
        navigator.clipboard.writeText(input.value).then(() => {
            btn.querySelector('svg').innerHTML = copyIconDone;
            clearTimeout(copyResetTimer);
            copyResetTimer = setTimeout(resetCopyIcon, 1500);
        }).catch(() => {
            input.focus();
            input.select();
        });
    });

    optimizationToggle?.addEventListener('click', () => {
        const isHidden = optimizationContent?.classList.contains('hidden');
        optimizationContent?.classList.toggle('hidden', !isHidden);
        optimizationChevron?.classList.toggle('rotate-180', isHidden);
    });

    detailClose?.addEventListener('click', async () => {
        if (!await window.mediaMetadata.canLeave()) return;
        window.mediaMetadata.reset();
        if (activeItem) activeItem.classList.remove('ring-2', 'ring-sky-500/60');
        activeItem = null;
        detailPanel.classList.add('hidden');
    });

    document.getElementById('detail-delete')?.addEventListener('click', async () => {
        if (!confirm('{{ __('admin.confirm_delete_media') }}')) return;
        const id = detailPanel.dataset.mediaId;
        const error = document.getElementById('detail-metadata-error');
        try {
            const response = await fetch(`/admin/media/${id}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': window.csrfToken, Accept: 'application/json' },
            });
            if (response.ok) { window.location.reload(); return; }
            const payload = await response.json();
            error.textContent = Object.values(payload.errors || {}).flat()[0] || @json(__('media_safety.error'));
        } catch (_) {
            error.textContent = @json(__('media_safety.error'));
        }
        error.classList.remove('hidden');
    });
</script>
@endpush
@endsection
