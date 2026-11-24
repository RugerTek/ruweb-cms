{{--
    Product editor (Full profile). Every write goes through ProductOperations (shared with MCP); this form only
    collects the input. Commerce fields (sku, prices, stock, variants) exist on the base-language row only.
    Variants use the base generic repeater (resources/js/admin-common.js): add, remove and reorder rows.
--}}
@php
    $product = $product ?? null;
    $isEdit = $product !== null;
    $isBase = ! $isEdit || $product->isBaseLocale();
    $amount = fn ($money) => $money === null ? '' : number_format($money->amount, 0, ',', '.');
    $input = 'w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50';
    $checkbox = 'w-4 h-4 rounded bg-[#1a1a1a] border border-white/20 text-sky-500 focus:ring-sky-500/50 focus:ring-offset-0';

    $meta = old('meta_json', $product?->meta_json ?? []);
    $selectedBrands = array_map('intval', (array) old('brands', $product ? $product->brands()->pluck('taxonomies.id')->all() : []));
    $categories ??= collect();
    $selectedCategories = array_map('intval', (array) old('categories', $product ? $product->categories()->pluck('taxonomies.id')->all() : []));
    $gallery = old('items.gallery', $product ? $product->mediaByCollection('gallery')->get()->map(fn ($media) => ['media_id' => $media->id])->all() : []);
    $variants = old('items.variants', $product ? $product->variants->map(fn ($variant) => [
        'id' => $variant->id,
        'name' => $variant->name,
        'sku' => $variant->sku,
        'price_amount' => $amount($variant->price_amount),
        'compare_at_price_amount' => $amount($variant->compare_at_price_amount),
        'stock' => $variant->stock,
        'weight_grams' => $variant->weight_grams,
        'attributes' => collect($variant->attributes_json ?? [])->map(fn ($value, $key) => $key.': '.$value)->implode("\n"),
    ])->all() : []);
    $variants = array_values(array_filter((array) $variants, 'is_array'));
    $hasVariants = (bool) old('has_variants', $product?->has_variants ?? false);
    $status = old('status', $product?->status ?? 'draft');
    $dimensions = old('dimensions_json', $product?->dimensions_json ?? []);
@endphp

<form id="product-form" method="POST" action="{{ $isEdit ? route('admin.products.update', $product) : route('admin.products.store') }}" class="space-y-6">
    @csrf
    @if($isEdit)
        @method('PUT')
        <input type="hidden" name="expected_revision" value="{{ $revision ?? '' }}">
    @endif
    <input type="hidden" name="return" value="{{ $editorReturn ?? '' }}">

    @if($errors->any())
        <div role="alert" class="rounded-md border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">
            <ul class="list-disc pl-5 space-y-0.5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    {{-- General --}}
    <section class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5 space-y-5">
        <h2 class="text-xs font-semibold text-white uppercase tracking-wide">{{ __('shop.section_general') }}</h2>
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="title" class="block text-xs font-medium text-gray-500 mb-1">{{ __('shop.field_title') }}</label>
                <input type="text" name="title" id="title" value="{{ old('title', $product?->title) }}" required maxlength="255" class="{{ $input }}">
                @error('title')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="slug" class="block text-xs font-medium text-gray-500 mb-1">{{ __('shop.field_slug') }}</label>
                <div class="flex items-center gap-1">
                    <span class="text-gray-600 text-sm">/{{ app(\App\Support\ShopModule::class)->path() }}/</span>
                    <input type="text" name="slug" id="slug" value="{{ old('slug', $product?->slug) }}" required maxlength="255" placeholder="{{ __('shop.field_slug_placeholder') }}" class="{{ $input }}">
                </div>
                @error('slug')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
            </div>
        </div>
        <div>
            <label for="excerpt" class="block text-xs font-medium text-gray-500 mb-1">{{ __('shop.field_excerpt') }}</label>
            <textarea name="excerpt" id="excerpt" rows="3" maxlength="500" class="{{ $input }}">{{ old('excerpt', $product?->excerpt) }}</textarea>
        </div>
        <div>
            <label for="description" class="block text-xs font-medium text-gray-500 mb-1">{{ __('shop.field_description') }}</label>
            <textarea name="description" id="description" rows="12" data-rich-text="post-body" class="{{ $input }}">{{ old('description', $product?->description) }}</textarea>
        </div>
    </section>

    @if($isBase)
        {{-- Prices and inventory --}}
        <section class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5 space-y-5">
            <h2 class="text-xs font-semibold text-white uppercase tracking-wide">{{ __('shop.section_pricing') }} · {{ __('shop.section_inventory') }}</h2>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="sku" class="block text-xs font-medium text-gray-500 mb-1">{{ __('shop.field_sku') }}</label>
                    <input type="text" name="sku" id="sku" value="{{ old('sku', $product?->sku) }}" maxlength="100" class="{{ $input }}">
                    @error('sku')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
                <div class="flex flex-col justify-end gap-2">
                    <input type="hidden" name="has_variants" value="0">
                    <label class="flex items-center gap-2.5 cursor-pointer">
                        <input type="checkbox" name="has_variants" value="1" id="has_variants" data-has-variants @checked($hasVariants) class="{{ $checkbox }}">
                        <span class="text-sm text-gray-300">{{ __('shop.field_has_variants') }}</span>
                    </label>
                    <p class="text-xs text-gray-600">{{ __('shop.has_variants_help') }}</p>
                </div>
            </div>
            <div class="grid gap-5 sm:grid-cols-2 {{ $hasVariants ? 'hidden' : '' }}" data-simple-only>
                <div>
                    <label for="price_amount" class="block text-xs font-medium text-gray-500 mb-1">{{ __('shop.field_price') }} <span class="text-gray-600">· {{ __('shop.currency_label') }}</span></label>
                    <input type="text" inputmode="numeric" name="price_amount" id="price_amount" value="{{ old('price_amount', $amount($product?->price_amount)) }}" class="{{ $input }}">
                    <p class="text-xs text-gray-600 mt-1">{{ __('shop.price_help') }}</p>
                    @error('price_amount')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="compare_at_price_amount" class="block text-xs font-medium text-gray-500 mb-1">{{ __('shop.field_compare_at_price') }}</label>
                    <input type="text" inputmode="numeric" name="compare_at_price_amount" id="compare_at_price_amount" value="{{ old('compare_at_price_amount', $amount($product?->compare_at_price_amount)) }}" class="{{ $input }}">
                    <p class="text-xs text-gray-600 mt-1">{{ __('shop.compare_at_price_help') }}</p>
                    @error('compare_at_price_amount')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
            </div>
            <div class="grid gap-5 sm:grid-cols-3">
                <div class="flex flex-col gap-2">
                    <input type="hidden" name="track_stock" value="0">
                    <label class="flex items-center gap-2.5 cursor-pointer">
                        <input type="checkbox" name="track_stock" value="1" @checked(old('track_stock', $product?->track_stock)) class="{{ $checkbox }}">
                        <span class="text-sm text-gray-300">{{ __('shop.field_track_stock') }}</span>
                    </label>
                    <input type="hidden" name="allow_backorder" value="0">
                    <label class="flex items-center gap-2.5 cursor-pointer">
                        <input type="checkbox" name="allow_backorder" value="1" @checked(old('allow_backorder', $product?->allow_backorder)) class="{{ $checkbox }}">
                        <span class="text-sm text-gray-300">{{ __('shop.field_allow_backorder') }}</span>
                    </label>
                </div>
                <div class="{{ $hasVariants ? 'hidden' : '' }}" data-simple-only>
                    <label for="stock" class="block text-xs font-medium text-gray-500 mb-1">{{ __('shop.field_stock') }}</label>
                    <input type="number" step="1" name="stock" id="stock" value="{{ old('stock', $product?->stock) }}" class="{{ $input }}">
                    @error('stock')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="weight_grams" class="block text-xs font-medium text-gray-500 mb-1">{{ __('shop.field_weight_grams') }}</label>
                    <input type="number" min="0" step="1" name="weight_grams" id="weight_grams" value="{{ old('weight_grams', $product?->weight_grams) }}" class="{{ $input }}">
                </div>
            </div>
            <fieldset>
                <legend class="block text-xs font-medium text-gray-500 mb-1">{{ __('shop.field_dimensions') }}</legend>
                <div class="grid gap-3 grid-cols-3">
                    @foreach(['length', 'width', 'height'] as $dimension)
                        <div>
                            <label for="dimensions-{{ $dimension }}" class="sr-only">{{ __('shop.field_dimensions_'.$dimension) }}</label>
                            <input type="number" min="0" step="0.1" name="dimensions_json[{{ $dimension }}]" id="dimensions-{{ $dimension }}" value="{{ $dimensions[$dimension] ?? '' }}" placeholder="{{ __('shop.field_dimensions_'.$dimension) }}" class="{{ $input }}">
                        </div>
                    @endforeach
                </div>
            </fieldset>
        </section>

        {{-- Variants --}}
        <section class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5 space-y-4 {{ $hasVariants ? '' : 'hidden' }}" data-variants-section>
            <h2 class="text-xs font-semibold text-white uppercase tracking-wide">{{ __('shop.section_variants') }}</h2>
            @error('variants')<p class="text-red-400 text-xs">{{ $message }}</p>@enderror
            <div data-repeater data-repeater-key="variants" data-item-label="{{ __('shop.variant') }} :position"
                 data-confirm-remove="{{ __('shop.remove_variant') }}: :item" data-confirm-label="{{ __('shop.remove_variant') }}">
                <div data-repeater-list class="space-y-2">
                    @foreach($variants as $index => $row)
                        @include('admin.products._variant-row', ['index' => $index, 'row' => $row])
                    @endforeach
                </div>
                <p data-repeater-empty class="text-xs text-gray-600 py-3 {{ count($variants) ? 'hidden' : '' }}">{{ __('shop.no_variants_yet') }}</p>
                <button type="button" data-repeater-add class="mt-3 inline-flex items-center gap-2 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-md transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    {{ __('shop.add_variant') }}
                </button>
                <template data-repeater-template>@include('admin.products._variant-row', ['index' => '__INDEX__', 'row' => []])</template>
            </div>
        </section>
    @else
        <p class="rounded-md border border-white/[0.06] bg-white/[0.02] px-4 py-3 text-xs text-gray-500">{{ __('shop.commerce_fields_base_only') }}</p>
    @endif

    {{-- Images and classification --}}
    <section class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5 space-y-5">
        <h2 class="text-xs font-semibold text-white uppercase tracking-wide">{{ __('shop.section_media') }}</h2>
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                @include('admin.media._selector', ['name' => 'featured_image', 'value' => old('featured_image', $product?->featuredImage()?->id), 'label' => __('admin.field_featured_image'), 'type' => 'image', 'placeholder' => 'square'])
            </div>
            <div>
                <p class="block text-xs font-medium text-gray-500 mb-2">{{ __('shop.brands') }}</p>
                <div class="space-y-1.5 max-h-40 overflow-y-auto pr-1">
                    @forelse($brands as $brand)
                        <label class="flex items-center gap-2.5 cursor-pointer group">
                            <input type="checkbox" name="brands[]" value="{{ $brand->id }}" @checked(in_array($brand->id, $selectedBrands, true)) class="{{ $checkbox }}">
                            <span class="text-sm text-gray-400 group-hover:text-gray-200 transition-colors">{{ $brand->name }}</span>
                        </label>
                    @empty
                        <a href="{{ route('admin.taxonomies.index', \App\Models\Product::BRAND_TAXONOMY) }}" class="text-xs text-gray-600 underline hover:text-gray-300">{{ __('shop.brands') }} →</a>
                    @endforelse
                </div>
            </div>
            <div>
                <p class="block text-xs font-medium text-gray-500 mb-2">{{ __('shop.categories') }}</p>
                <div class="space-y-1.5 max-h-40 overflow-y-auto pr-1">
                    @forelse($categories as $category)
                        <label class="flex items-center gap-2.5 cursor-pointer group">
                            <input type="checkbox" name="categories[]" value="{{ $category['id'] }}" @checked(in_array($category['id'], $selectedCategories, true)) class="{{ $checkbox }}">
                            <span class="text-sm text-gray-400 group-hover:text-gray-200 transition-colors">{{ $category['path'] }}</span>
                        </label>
                    @empty
                        @if(in_array(\App\Models\Product::CATEGORY_TAXONOMY, config('cms.taxonomy_types', []), true))
                            <a href="{{ route('admin.taxonomies.index', \App\Models\Product::CATEGORY_TAXONOMY) }}" class="text-xs text-gray-600 underline hover:text-gray-300">{{ __('shop.categories') }} →</a>
                        @endif
                    @endforelse
                </div>
                @if($errors->has('categories') || $errors->has('categories.*'))<p class="text-red-400 text-xs mt-1">{{ $errors->first('categories') ?: $errors->first('categories.*') }}</p>@endif
                <p class="text-xs text-gray-600 mt-2">{{ __('shop.categories_help') }}</p>
            </div>
        </div>
        <div data-repeater data-repeater-key="gallery" data-item-label="{{ __('shop.field_gallery') }} :position" data-confirm-remove="{{ __('admin.remove') }}: :item" data-confirm-label="{{ __('admin.remove') }}">
            <h3 class="text-xs font-medium text-gray-400 mb-2">{{ __('shop.field_gallery') }}</h3>
            <div data-repeater-list class="space-y-2">
                @foreach(array_values(array_filter((array) $gallery, 'is_array')) as $index => $item)
                    @include('admin.products._gallery-item', ['index' => $index, 'mediaId' => $item['media_id'] ?? null])
                @endforeach
            </div>
            <button type="button" data-repeater-add class="mt-3 inline-flex items-center gap-2 px-3 py-1.5 bg-white/5 hover:bg-white/10 text-gray-300 text-sm font-medium rounded-md transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                {{ __('shop.field_gallery') }}
            </button>
            <template data-repeater-template>@include('admin.products._gallery-item', ['index' => '__INDEX__', 'mediaId' => null])</template>
        </div>
    </section>

    {{-- Status and SEO --}}
    <section class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5 space-y-5">
        <h2 class="text-xs font-semibold text-white uppercase tracking-wide">{{ __('shop.tab_status') }} · {{ __('shop.section_seo') }}</h2>
        <div class="grid gap-5 sm:grid-cols-3">
            <div>
                <label for="status" class="block text-xs font-medium text-gray-500 mb-1">{{ __('shop.col_status') }}</label>
                <select name="status" id="status" class="{{ $input }}">
                    <option value="draft" @selected($status === 'draft')>{{ __('shop.badge_draft') }}</option>
                    {{-- Editors never publish: a change to a live product is staged for review by ProductOperations. --}}
                    @if(auth()->user()->isAdmin() || $status === 'published')
                        <option value="published" @selected($status === 'published')>{{ __('shop.badge_published') }}</option>
                    @endif
                </select>
            </div>
            @if(auth()->user()->isAdmin())
                <div>
                    <label for="published_at" class="block text-xs font-medium text-gray-500 mb-1">{{ __('shop.field_published_at') }}</label>
                    <input type="datetime-local" name="published_at" id="published_at" value="{{ old('published_at', $product?->published_at?->format('Y-m-d\TH:i')) }}" class="{{ $input }}">
                </div>
                <div>
                    <label for="expires_at" class="block text-xs font-medium text-gray-500 mb-1">{{ __('shop.field_expires_at') }}</label>
                    <input type="datetime-local" name="expires_at" id="expires_at" value="{{ old('expires_at', $product?->expires_at?->format('Y-m-d\TH:i')) }}" class="{{ $input }}">
                    @error('expires_at')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
            @endif
        </div>
        <div>
            <label for="meta-title" class="block text-xs font-medium text-gray-500 mb-1">{{ __('shop.meta_title') }}</label>
            <input type="text" name="meta_json[title]" id="meta-title" value="{{ $meta['title'] ?? '' }}" maxlength="255" class="{{ $input }}">
        </div>
        <div>
            <label for="meta-description" class="block text-xs font-medium text-gray-500 mb-1">{{ __('shop.meta_description') }}</label>
            <textarea name="meta_json[description]" id="meta-description" rows="2" maxlength="320" class="{{ $input }}">{{ $meta['description'] ?? '' }}</textarea>
        </div>
    </section>

    <div class="flex flex-wrap items-center gap-3">
        <button type="submit" class="px-4 py-2 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
            {{ $isEdit ? __('shop.btn_update') : __('shop.new_product') }}
        </button>
        @if($isEdit)
            <button type="submit" formaction="{{ route('admin.products.preview', $product) }}" formtarget="_blank" formnovalidate
                    class="px-4 py-2 bg-white/5 hover:bg-white/10 text-gray-200 text-sm font-medium rounded-md transition-colors">{{ __('admin.action_preview') }} ↗</button>
        @endif
        <a href="{{ $editorBackHref ?? route('admin.products.index') }}" class="px-4 py-2 bg-white/5 hover:bg-white/10 text-gray-300 text-sm font-medium rounded-md transition-colors">{{ __('shop.btn_cancel') }}</a>
        @if($isEdit && $product->isPublished())
            <a href="{{ $product->url() }}" target="_blank" rel="noopener" class="ml-auto text-sm text-sky-400 hover:text-sky-300">{{ __('shop.view_live_product') }} ↗</a>
        @endif
    </div>
</form>

@push('scripts')
<script>
    (() => {
        const title = document.getElementById('title');
        const slug = document.getElementById('slug');
        let slugEdited = {{ $isEdit ? 'true' : 'false' }};
        slug.addEventListener('input', () => { slugEdited = true; });
        title.addEventListener('input', () => {
            if (slugEdited) return;
            slug.value = title.value.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                .replace(/[^a-z0-9\s-]/g, '').trim().replace(/\s+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
        });

        // A product with variants keeps price and stock on each variant: swap the simple fields for the list.
        const toggle = document.querySelector('[data-has-variants]');
        toggle?.addEventListener('change', () => {
            document.querySelectorAll('[data-simple-only]').forEach((node) => node.classList.toggle('hidden', toggle.checked));
            document.querySelector('[data-variants-section]')?.classList.toggle('hidden', !toggle.checked);
        });
    })();
</script>
@endpush
