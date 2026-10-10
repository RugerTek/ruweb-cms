@php
    $post = $post ?? null;
    $isEdit = $post !== null;
    $featuredImageId = old('featured_image', $post?->featuredImage()?->id);
    $meta = old('meta_json', $post?->meta_json ?? []);
    $selectedCategories = old('categories', $post?->categories()->pluck('taxonomies.id')->toArray() ?? []);
@endphp

<form id="editor-form"
      method="POST"
      action="{{ $isEdit ? route('admin.posts.update', $post) : route('admin.posts.store') }}"
      class="flex flex-col h-full">
    @csrf
    @if(isset($editorLease))
        <input type="hidden" name="_editor_lock" value="{{ $editorLease['token'] }}">
    @endif
    @if($isEdit) @method('PUT') @endif
    <input type="hidden" name="return" value="{{ $editorReturn ?? request()->query('return') }}">
    <input type="hidden" name="locale" value="{{ old('locale', $post?->locale ?? \App\Models\Locale::baseCode()) }}">
    @include('admin.partials.stale-fields', ['staleFieldNames' => $staleFieldNames ?? []])

    {{-- Tab bar --}}
    <div class="flex-none flex border-b border-white/[0.06] -mx-5 px-5 mb-5">
        <button type="button" data-tab="general"
                class="form-tab active mr-1 px-3 py-2 text-xs font-medium rounded-t transition-colors">
            {{ __('admin.tab_general') }}
        </button>
        <button type="button" data-tab="content"
                class="form-tab mr-1 px-3 py-2 text-xs font-medium rounded-t transition-colors">
            {{ __('admin.tab_content') }}
        </button>
        <button type="button" data-tab="status"
                class="form-tab px-3 py-2 text-xs font-medium rounded-t transition-colors">
            {{ __('admin.tab_status') }}
        </button>
    </div>

    {{-- TAB: General --}}
    <div data-panel="general" class="form-panel space-y-5">

        <div>
            <label for="title" class="block text-xs font-medium text-gray-500 mb-1">{{ __('admin.field_title') }}</label>
            <input type="text" name="title" id="title"
                   value="{{ old('title', $post?->title) }}"
                   class="w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50"
                   placeholder="{{ __('admin.field_title_placeholder') }}" required>
            @error('title')
                <p class="text-red-400 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="slug" class="block text-xs font-medium text-gray-500 mb-1">{{ __('admin.field_slug') }}</label>
            <div class="flex items-center gap-1">
                <span class="text-gray-600 text-sm">/blog/</span>
                <input type="text" name="slug" id="slug"
                       value="{{ old('slug', $post?->slug) }}"
                       class="flex-1 bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50"
                       placeholder="{{ __('admin.field_slug_placeholder') }}" required>
            </div>
            @error('slug')
                <p class="text-red-400 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="excerpt" class="block text-xs font-medium text-gray-500 mb-1">{{ __('admin.field_excerpt') }}</label>
            <textarea name="excerpt" id="excerpt" rows="3"
                      class="w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50"
                      placeholder="{{ __('admin.field_excerpt_placeholder') }}">{{ old('excerpt', $post?->excerpt) }}</textarea>
            @error('excerpt')
                <p class="text-red-400 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div>
            @include('admin.media._selector', [
                'name'  => 'featured_image',
                'value' => $featuredImageId,
                'label' => __('admin.field_featured_image'),
            ])
        </div>

        <div>
            <label class="block text-xs font-medium text-gray-500 mb-2">{{ __('admin.field_categories') }}</label>
            <div id="category-list" class="space-y-1.5 max-h-40 overflow-y-auto pr-1 mb-2">
                @forelse($categories as $category)
                    <label class="flex items-center gap-2.5 cursor-pointer group">
                        <input type="checkbox" name="categories[]" value="{{ $category->id }}"
                               {{ in_array($category->id, $selectedCategories) ? 'checked' : '' }}
                               class="w-4 h-4 rounded bg-[#1a1a1a] border border-white/20 text-sky-500 focus:ring-sky-500/50 focus:ring-offset-0">
                        <span class="text-sm text-gray-400 group-hover:text-gray-200 transition-colors">{{ $category->name }}</span>
                    </label>
                @empty
                    <p id="category-empty-hint" class="text-xs text-gray-600">{{ __('admin.categories_empty_hint') }}</p>
                @endforelse
            </div>
            {{-- Inline create --}}
            <div class="flex items-center gap-1.5">
                <input type="text" id="new-category-name"
                       placeholder="{{ __('admin.category_inline_placeholder') }}"
                       class="flex-1 bg-[#1a1a1a] border border-white/10 text-white text-xs rounded px-2 py-1.5 placeholder-gray-600 focus:outline-none focus:ring-1 focus:ring-sky-500/50 focus:border-sky-500/50">
                <button type="button" id="new-category-btn"
                        class="px-2 py-1.5 bg-white/5 hover:bg-white/10 text-gray-400 hover:text-gray-200 text-xs rounded transition-colors whitespace-nowrap">
                    + {{ __('admin.category_inline_add') }}
                </button>
            </div>
            <p id="new-category-error" class="text-red-400 text-xs mt-1 hidden"></p>
            @error('categories')
                <p class="text-red-400 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

    </div>

    {{-- TAB: Content --}}
    <div data-panel="content" class="form-panel hidden space-y-5">

        <div>
            <label for="content" class="block text-xs font-medium text-gray-500 mb-1">{{ __('admin.field_content') }}</label>
            <textarea name="content" id="content" rows="20"
                      data-rich-text="post-body"
                      class="w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50"
                      placeholder="{{ __('admin.field_content_placeholder') }}">{{ old('content', $post?->content) }}</textarea>
            @error('content')
                <p class="text-red-400 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

    </div>

    {{-- TAB: Status --}}
    <div data-panel="status" class="form-panel hidden space-y-5">

        {{-- Publish --}}
        <div class="space-y-3">
            <div>
                <label for="status" class="block text-xs font-medium text-gray-500 mb-1">{{ __('admin.field_status') }}</label>
                <select name="status" id="status"
                        class="w-full bg-[#1a1a1a] border border-white/10 text-gray-400 text-sm rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50">
                    @foreach (config('cms.statuses') as $s)
                        <option value="{{ $s }}" {{ old('status', $post?->status ?? 'draft') === $s ? 'selected' : '' }}>
                            {{ ucfirst($s) }}
                        </option>
                    @endforeach
                </select>
            </div>

            @if($isEdit && $post->published_at)
                <p class="text-xs text-gray-600">{{ __('admin.published_at', ['date' => $post->published_at->diffForHumans()]) }}</p>
            @endif
        </div>

        {{-- SEO --}}
        <div class="space-y-3">
            <h3 class="text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('admin.seo') }}</h3>

            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">{{ __('admin.field_seo_title') }}</label>
                <input type="text" name="meta_json[title]"
                       value="{{ $meta['title'] ?? '' }}"
                       class="w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50"
                       placeholder="{{ __('admin.field_seo_title_ph') }}">
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">{{ __('admin.field_seo_description') }}</label>
                <textarea name="meta_json[description]" rows="2"
                          class="w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50"
                          placeholder="{{ __('admin.field_seo_description_ph') }}">{{ $meta['description'] ?? '' }}</textarea>
            </div>
        </div>

        {{-- Delete --}}
        @if($isEdit && auth()->user()->can('delete', $post))
            <div class="pt-3 border-t border-white/[0.06]">
                <button type="button" id="delete-post-btn"
                        class="w-full px-3 py-2 bg-red-500/10 hover:bg-red-500/20 text-red-400 text-sm font-medium rounded-md transition-colors">
                    {{ __('admin.btn_delete_post') }}
                </button>
            </div>
        @endif

    </div>

    @if(auth()->user()->isAdmin())
    <div class="p-4 space-y-2"><label class="block">{{ __('editorial.publish_at') }}<input type="datetime-local" name="published_at" value="{{ old('published_at', $post?->published_at?->format('Y-m-d\TH:i')) }}" class="block w-full"></label>
    <label class="block">{{ __('editorial.expires_at') }}<input type="datetime-local" name="expires_at" value="{{ old('expires_at', $post?->expires_at?->format('Y-m-d\TH:i')) }}" class="block w-full"></label></div>
    @endif
</form>

{{-- Delete form — outside editor-form to avoid nesting --}}
@if($isEdit && auth()->user()->can('delete', $post))
    <form method="POST" action="{{ route('admin.posts.destroy', $post) }}" id="delete-post-form" class="hidden">
        @csrf
        @method('DELETE')
        @if(isset($editorLease))<input type="hidden" name="_editor_lock" value="{{ $editorLease['token'] }}">@endif
    </form>
@endif

@push('scripts')
<script>
    // Tab switching
    const formTabs = document.querySelectorAll('.form-tab');
    const formPanels = document.querySelectorAll('.form-panel');

    formTabs.forEach(tab => {
        tab.addEventListener('click', () => {
            const target = tab.dataset.tab;
            formTabs.forEach(t => t.classList.toggle('active', t === tab));
            formPanels.forEach(p => p.classList.toggle('hidden', p.dataset.panel !== target));
            updateTabStyles();
        });
    });

    function updateTabStyles() {
        formTabs.forEach(t => {
            if (t.classList.contains('active')) {
                t.classList.add('text-sky-400', 'border-b-2', 'border-sky-500');
                t.classList.remove('text-gray-500', 'border-transparent');
            } else {
                t.classList.remove('text-sky-400', 'border-b-2', 'border-sky-500');
                t.classList.add('text-gray-500', 'border-transparent');
            }
        });
    }
    updateTabStyles();

    // Slug auto-generation from title
    const titleInput = document.getElementById('title');
    const slugInput = document.getElementById('slug');
    let slugManuallyEdited = {{ $isEdit ? 'true' : 'false' }};

    slugInput.addEventListener('input', () => { slugManuallyEdited = true; });

    titleInput.addEventListener('input', () => {
        if (slugManuallyEdited) return;
        slugInput.value = titleInput.value
            .toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9\s-]/g, '')
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-|-$/g, '');
    });

    // Delete button → hidden form
    const deletePostBtn = document.getElementById('delete-post-btn');
    if (deletePostBtn) {
        deletePostBtn.addEventListener('click', async () => {
            if (await window.confirmAction({ message: @json(__('admin.confirm_delete_post')) })) {
                document.getElementById('delete-post-form').submit();
            }
        });
    }

    // Inline category create
    function toSlug(str) {
        return str.toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9\s-]/g, '')
            .trim()
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-');
    }

    const newCategoryBtn  = document.getElementById('new-category-btn');
    const newCategoryName = document.getElementById('new-category-name');
    const categoryError   = document.getElementById('new-category-error');
    const categoryList    = document.getElementById('category-list');

    if (newCategoryBtn) {
        newCategoryBtn.addEventListener('click', async () => {
            const name = newCategoryName.value.trim();
            if (!name) return;

            categoryError.classList.add('hidden');
            newCategoryBtn.disabled = true;

            try {
                const res = await fetch('{{ route('admin.taxonomies.store', 'category') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ name: name, slug: toSlug(name), order: 0 }),
                });

                if (!res.ok) {
                    const data = await res.json().catch(() => ({}));
                    const msg = data?.errors?.name?.[0] || data?.errors?.slug?.[0] || '{{ __('admin.category_inline_error') }}';
                    categoryError.textContent = msg;
                    categoryError.classList.remove('hidden');
                    return;
                }

                const { id, name: createdName } = await res.json();

                // Remove empty hint if present
                document.getElementById('category-empty-hint')?.remove();

                // Append new checkbox
                const label = document.createElement('label');
                label.className = 'flex items-center gap-2.5 cursor-pointer group';
                label.innerHTML = `
                    <input type="checkbox" name="categories[]" value="${id}" checked
                           class="w-4 h-4 rounded bg-[#1a1a1a] border border-white/20 text-sky-500 focus:ring-sky-500/50 focus:ring-offset-0">
                    <span class="text-sm text-gray-400 group-hover:text-gray-200 transition-colors">${createdName}</span>
                `;
                categoryList.appendChild(label);
                newCategoryName.value = '';

            } finally {
                newCategoryBtn.disabled = false;
            }
        });

        newCategoryName.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); newCategoryBtn.click(); }
        });
    }
</script>
@endpush
