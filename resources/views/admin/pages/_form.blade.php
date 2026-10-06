@php
    $page = $page ?? null;
    $isEdit = $page !== null;
    $contentJson = old('content_json', $page?->content_json ?? []);
    $currentTemplate = old('template_key', $page?->template_key ?? 'default');
    $featuredImageId = old('featured_image', $page?->featuredImage()?->id);

    $sectionRegistry = [];
    foreach ($templates as $templateKey => $templateConfig) {
        foreach ($templateConfig['sections'] as $sectionKey) {
            if (!isset($sectionRegistry[$sectionKey])) {
                $sectionRegistry[$sectionKey] = [
                    'key' => $sectionKey,
                    'templates' => [],
                    'partialView' => null,
                    'partialSourceTemplate' => null,
                ];
            }

            if (!in_array($templateKey, $sectionRegistry[$sectionKey]['templates'], true)) {
                $sectionRegistry[$sectionKey]['templates'][] = $templateKey;
            }

            if ($sectionRegistry[$sectionKey]['partialView'] === null) {
                $candidatePath = resource_path("views/admin/pages/sections/{$templateKey}_{$sectionKey}.blade.php");
                if (file_exists($candidatePath)) {
                    $sectionRegistry[$sectionKey]['partialView'] = "admin.pages.sections.{$templateKey}_{$sectionKey}";
                    $sectionRegistry[$sectionKey]['partialSourceTemplate'] = $templateKey;
                }
            }
        }
    }

    $sectionsForRender = array_values($sectionRegistry);
@endphp

<form id="editor-form"
      method="POST"
      action="{{ $isEdit ? route('admin.pages.update', $page) : route('admin.pages.store') }}"
      class="flex flex-col h-full">
    @csrf
    @if(isset($editorLease))
        <input type="hidden" name="_editor_lock" value="{{ $editorLease['token'] }}">
    @endif
    @if($isEdit) @method('PUT') @endif
    <input type="hidden" name="locale" value="{{ old('locale', $page?->locale ?? \App\Models\Locale::baseCode()) }}">
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
                   value="{{ old('title', $page?->title) }}"
                   class="w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50"
                   placeholder="{{ __('admin.field_title_placeholder') }}" required>
            @error('title')
                <p class="text-red-400 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="slug" class="block text-xs font-medium text-gray-500 mb-1">{{ __('admin.field_slug') }}</label>
            <div class="flex items-center gap-1">
                <span class="text-gray-600 text-sm">/</span>
                <input type="text" name="slug" id="slug"
                       value="{{ old('slug', $page?->slug) }}"
                       class="flex-1 bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50"
                       placeholder="{{ __('admin.field_slug_placeholder') }}" required>
            </div>
            @error('slug')
                <p class="text-red-400 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="template_key" class="block text-xs font-medium text-gray-500 mb-1">{{ __('admin.field_template') }}</label>
            <select name="template_key" id="template_key"
                    class="w-full bg-[#1a1a1a] border border-white/10 text-gray-400 text-sm rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50">
                @foreach ($templates as $key => $tmpl)
                    <option value="{{ $key }}" {{ $currentTemplate === $key ? 'selected' : '' }}>{{ $tmpl['name'] }}</option>
                @endforeach
            </select>
            @error('template_key')
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

    </div>

    {{-- TAB: Contenido --}}
    <div data-panel="content" class="form-panel hidden space-y-4">
        <div id="section-fields" class="space-y-4">
            @foreach ($sectionsForRender as $sectionConfig)
                @php
                    $section = $sectionConfig['key'];
                    $sectionTemplates = implode(',', $sectionConfig['templates']);
                    $sectionDomId = preg_replace('/[^a-z0-9\-]+/i', '-', $section);
                    $sectionBodyId = 'section-body-' . trim($sectionDomId ?: $section, '-');
                    $isVisible = (bool) ($contentJson['sections'][$section]['is_visible'] ?? 1);
                @endphp

                <div class="template-section rounded-xl border border-white/[0.08] bg-[#101013] overflow-hidden"
                     data-templates="{{ $sectionTemplates }}">
                    <div class="section-accordion-header flex items-center justify-between px-4 py-3 cursor-pointer select-none hover:bg-white/[0.02] transition-colors"
                         data-accordion-toggle
                         aria-expanded="false"
                         aria-controls="{{ $sectionBodyId }}">
                        <div class="flex items-center gap-2 min-w-0">
                            <svg data-accordion-icon class="section-chevron w-4 h-4 text-gray-600 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                            <h3 class="text-xs font-medium text-gray-500 uppercase tracking-wide truncate">
                                {{ str_replace('_', ' ', $section) }}
                            </h3>
                        </div>

                        <label class="relative inline-flex items-center gap-2 cursor-pointer shrink-0" data-visibility-toggle>
                            <input type="hidden" name="content_json[sections][{{ $section }}][is_visible]" value="0">
                            <input type="checkbox"
                                   name="content_json[sections][{{ $section }}][is_visible]"
                                   value="1"
                                   class="sr-only peer"
                                   {{ $isVisible ? 'checked' : '' }}>
                            <div class="w-9 h-5 bg-gray-700 peer-checked:bg-sky-500 rounded-full transition-colors relative
                                        after:content-[''] after:absolute after:top-0.5 after:left-0.5
                                        after:bg-white after:rounded-full after:h-4 after:w-4
                                        after:transition-transform peer-checked:after:translate-x-4"></div>
                            <span class="text-xs text-gray-500">{{ __('admin.field_section_visible') }}</span>
                        </label>
                    </div>

                    <div id="{{ $sectionBodyId }}" data-accordion-body class="hidden border-t border-white/[0.08] p-4 space-y-4">
                        @if ($sectionConfig['partialView'])
                            @include($sectionConfig['partialView'], [
                                'page' => $page,
                                'sectionKey' => $section,
                                'contentJson' => $contentJson,
                                'templateKey' => $sectionConfig['partialSourceTemplate'],
                            ])
                        @else
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">{{ __('admin.field_section_heading') }}</label>
                                <input type="text" name="content_json[sections][{{ $section }}][heading]"
                                       value="{{ $contentJson['sections'][$section]['heading'] ?? '' }}"
                                       class="w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50"
                                       placeholder="{{ __('admin.field_section_heading_ph') }}">
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">{{ __('admin.field_section_body') }}</label>
                                <textarea name="content_json[sections][{{ $section }}][body]" rows="4"
                                          class="w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50"
                                          placeholder="{{ __('admin.field_section_body_ph') }}">{{ $contentJson['sections'][$section]['body'] ?? '' }}</textarea>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- TAB: Estado --}}
    <div data-panel="status" class="form-panel hidden space-y-5">

        {{-- Publish --}}
        <div class="space-y-3">
            <div>
                <label for="status" class="block text-xs font-medium text-gray-500 mb-1">{{ __('admin.field_status') }}</label>
                <select name="status" id="status"
                        class="w-full bg-[#1a1a1a] border border-white/10 text-gray-400 text-sm rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50">
                    @foreach (config('cms.statuses') as $s)
                        <option value="{{ $s }}" {{ old('status', $page?->status ?? 'draft') === $s ? 'selected' : '' }}>
                            {{ ucfirst($s) }}
                        </option>
                    @endforeach
                </select>
            </div>

            @if($isEdit && $page->published_at)
                <p class="text-xs text-gray-600">{{ __('admin.published_at', ['date' => $page->published_at->diffForHumans()]) }}</p>
            @endif
        </div>

        {{-- SEO --}}
        <div class="space-y-3">
            <h3 class="text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('admin.seo') }}</h3>

            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">{{ __('admin.field_seo_title') }}</label>
                <input type="text" name="content_json[meta][title]"
                       value="{{ $contentJson['meta']['title'] ?? '' }}"
                       class="w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50"
                       placeholder="{{ __('admin.field_seo_title_ph') }}">
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">{{ __('admin.field_seo_description') }}</label>
                <textarea name="content_json[meta][description]" rows="2"
                          class="w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50"
                          placeholder="{{ __('admin.field_seo_description_ph') }}">{{ $contentJson['meta']['description'] ?? '' }}</textarea>
            </div>
        </div>

        {{-- Delete --}}
        @if($isEdit)
            <div class="pt-3 border-t border-white/[0.06]">
                <button type="button" id="delete-page-btn"
                        class="w-full px-3 py-2 bg-red-500/10 hover:bg-red-500/20 text-red-400 text-sm font-medium rounded-md transition-colors">
                    {{ __('admin.btn_delete_page') }}
                </button>
            </div>
        @endif

    </div>

</form>

{{-- Delete form — outside editor-form to avoid nesting --}}
@if($isEdit)
    <form method="POST" action="{{ route('admin.pages.destroy', $page) }}" id="delete-form" class="hidden">
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

    // Template switcher for shared sections
    const templateSelect = document.getElementById('template_key');
    const sectionBlocks = document.querySelectorAll('.template-section');

    function refreshVisibleSections() {
        if (!templateSelect) {
            return;
        }

        sectionBlocks.forEach((block) => {
            const templates = (block.dataset.templates || '')
                .split(',')
                .map((value) => value.trim())
                .filter(Boolean);
            const shouldShow = templates.includes(templateSelect.value);
            block.style.display = shouldShow ? '' : 'none';
        });
    }

    templateSelect?.addEventListener('change', refreshVisibleSections);
    refreshVisibleSections();

    // Accordion behavior
    const accordionToggles = document.querySelectorAll('[data-accordion-toggle]');
    accordionToggles.forEach((toggle) => {
        toggle.addEventListener('click', () => {
            const section = toggle.closest('.template-section');
            const body = section?.querySelector('[data-accordion-body]');
            const icon = section?.querySelector('[data-accordion-icon]');

            if (!body || !icon) {
                return;
            }

            const shouldOpen = body.classList.contains('hidden');
            body.classList.toggle('hidden', !shouldOpen);
            icon.classList.toggle('rotate-180', shouldOpen);
            toggle.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
        });
    });

    // Keep visibility toggle independent from accordion click.
    const visibilityToggles = document.querySelectorAll('[data-visibility-toggle]');
    visibilityToggles.forEach((label) => {
        label.addEventListener('click', (event) => {
            event.stopPropagation();
        });
    });

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

    // Delete button -> hidden form
    const deleteBtn = document.getElementById('delete-page-btn');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', () => {
            if (confirm('{{ __('admin.confirm_delete_page') }}')) {
                document.getElementById('delete-form').submit();
            }
        });
    }
</script>
@endpush
