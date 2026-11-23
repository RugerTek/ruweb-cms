@extends('admin.layouts.app')

@section('title', $menu->name)

@php
// Guarded: the compiled view can run more than once per process (queue workers, tests).
if (! function_exists('flattenMenuTree')) {
function flattenMenuTree($items, $parentId = null) {
    $result = [];
    foreach ($items as $idx => $item) {
        $result[] = [
            'id'          => $item->id,
            'parent_id'   => $parentId,
            'label'       => $item->label,
            'type'        => $item->type,
            'linkable_id' => $item->linkable_id,
            'url'         => $item->url,
            'target'      => $item->target,
            'translation_status' => $item->translation_status,
            'order'       => $idx,
        ];
        if ($item->children->isNotEmpty()) {
            foreach (flattenMenuTree($item->children, $item->id) as $child) {
                $result[] = $child;
            }
        }
    }
    return $result;
}
}
@endphp

@section('content')
{{-- Header --}}
<div class="mb-8 flex items-start justify-between gap-4">
    <div>
        <div class="flex items-center gap-2 text-sm text-gray-500 mb-2">
            <a href="{{ route('admin.menus.index') }}" class="hover:text-gray-300 transition-colors">{{ __('admin.menus') }}</a>
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-gray-400">{{ $menu->name }}</span>
        </div>
        <h1 class="text-lg font-semibold text-white">{{ $menu->name }}</h1>
        <div class="mt-3 flex items-center gap-1">
            @foreach($locales as $locale)
                @php
                    $translation = ($menuTranslations ?? collect())->get($locale->code);
                    $badgeColor = $translation
                        ? ($translation->id === $menu->id ? 'bg-sky-500/10 text-sky-400 ring-1 ring-sky-500/20' : 'bg-white/[0.04] text-gray-400 hover:text-gray-200')
                        : 'bg-yellow-500/10 text-yellow-400 hover:bg-yellow-500/20';
                @endphp
                @if($translation)
                    <a href="{{ route('admin.menus.edit', $translation) }}"
                       class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium transition-colors {{ $badgeColor }}">
                        {{ strtoupper($locale->code) }}
                    </a>
                @elseif(auth()->user()->isAdmin())
                    <form method="POST" action="{{ route('admin.menus.translate', [$menu, $locale->code]) }}" class="inline-flex">
                        @csrf
                        <button type="submit"
                                class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium transition-colors {{ $badgeColor }}">
                            +{{ strtoupper($locale->code) }}
                        </button>
                    </form>
                @endif
            @endforeach
        </div>
    </div>
    @if(auth()->user()->isAdmin())
    <form method="POST" action="{{ route('admin.menus.destroy', $menu) }}"
          onsubmit="return confirm('{{ __('admin.menu_confirm_delete') }}')">
        @csrf
        @method('DELETE')
        <button type="submit" class="px-3 py-1.5 bg-red-600/10 hover:bg-red-600/20 text-red-400 text-sm font-medium rounded-md transition-colors">
            {{ __('admin.menu_btn_delete') }}
        </button>
    </form>
    @endif
</div>

{{-- ── Section 1: Menu Settings ────────────────────────────────── --}}
<div class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl mb-6">
    <div class="px-5 py-4 border-b border-white/[0.06]">
        <h2 class="text-sm font-semibold text-white">{{ __('admin.menu_settings') }}</h2>
        <p class="text-xs text-gray-500 mt-0.5">{{ __('admin.menu_settings_hint') }}</p>
    </div>

    <form id="settings-form" method="POST" action="{{ route('admin.menus.update', $menu) }}" class="p-5">
        @csrf
        @method('PUT')

        <div class="max-w-lg space-y-4">
            @include('admin.menus._form', ['menu' => $menu])
        </div>

        <div class="mt-5 pt-4 border-t border-white/[0.06]">
            <button id="settings-save-btn" type="submit" disabled
                    class="px-4 py-2 bg-sky-600 hover:bg-sky-700 disabled:opacity-40 disabled:cursor-not-allowed text-white text-sm font-medium rounded-md transition-colors">
                {{ __('admin.btn_save_changes') }}
            </button>
        </div>
    </form>
</div>

{{-- ── Section 2: Menu Structure ───────────────────────────────── --}}
<div class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl">
    <div class="px-5 py-4 border-b border-white/[0.06]">
        <h2 class="text-sm font-semibold text-white">{{ __('admin.menu_structure') }}</h2>
        <p class="text-xs text-gray-500 mt-0.5">{{ __('admin.menu_structure_hint') }}</p>
    </div>

    <div class="p-5">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            {{-- Left: Add items --}}
            <div class="space-y-3">
                <h3 class="text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.menu_add_items') }}</h3>

                {{-- Custom Link --}}
                <div class="bg-[#1a1a1a] ring-1 ring-white/[0.06] rounded-lg overflow-hidden">
                    <button type="button" onclick="togglePanel('panel-custom')"
                            class="w-full flex items-center justify-between px-3 py-2.5 text-sm font-medium text-gray-300 hover:text-white transition-colors">
                        <span>{{ __('admin.menu_item_type_custom_link') }}</span>
                        <svg id="chevron-panel-custom" class="w-3 h-3 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    <div id="panel-custom" class="border-t border-white/[0.06] px-3 py-3 space-y-2">
                        <input type="text" id="custom-url" placeholder="{{ __('admin.menu_custom_url_placeholder') }}"
                               class="w-full bg-[#111111] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50">
                        <input type="text" id="custom-label" placeholder="{{ __('admin.menu_custom_label_placeholder') }}"
                               class="w-full bg-[#111111] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50">
                        <button type="button" onclick="addCustomLink()"
                                class="px-3 py-1.5 bg-sky-600 hover:bg-sky-700 text-white text-xs font-medium rounded-md transition-colors">
                            {{ __('admin.menu_add_to_menu') }}
                        </button>
                    </div>
                </div>

                @if($pages->isNotEmpty())
                <div class="bg-[#1a1a1a] ring-1 ring-white/[0.06] rounded-lg overflow-hidden">
                    <button type="button" onclick="togglePanel('panel-pages')"
                            class="w-full flex items-center justify-between px-3 py-2.5 text-sm font-medium text-gray-300 hover:text-white transition-colors">
                        <span>{{ __('admin.menu_item_type_page') }}</span>
                        <svg id="chevron-panel-pages" class="w-3 h-3 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    <div id="panel-pages" class="hidden border-t border-white/[0.06] px-3 py-3">
                        <div class="space-y-1 max-h-48 overflow-y-auto mb-3">
                            @foreach($pages as $page)
                            <label class="flex items-center gap-2 text-sm text-gray-400 hover:text-gray-200 cursor-pointer py-0.5">
                                <input type="checkbox" value="{{ $page->id }}" data-label="{{ $page->title }}" class="page-check rounded border-white/20 bg-[#111111]">
                                {{ $page->title }}
                            </label>
                            @endforeach
                        </div>
                        <button type="button" onclick="addChecked('page', '.page-check')"
                                class="px-3 py-1.5 bg-sky-600 hover:bg-sky-700 text-white text-xs font-medium rounded-md transition-colors">
                            {{ __('admin.menu_add_to_menu') }}
                        </button>
                    </div>
                </div>
                @endif

                @if($posts->isNotEmpty())
                <div class="bg-[#1a1a1a] ring-1 ring-white/[0.06] rounded-lg overflow-hidden">
                    <button type="button" onclick="togglePanel('panel-posts')"
                            class="w-full flex items-center justify-between px-3 py-2.5 text-sm font-medium text-gray-300 hover:text-white transition-colors">
                        <span>{{ __('admin.menu_item_type_post') }}</span>
                        <svg id="chevron-panel-posts" class="w-3 h-3 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    <div id="panel-posts" class="hidden border-t border-white/[0.06] px-3 py-3">
                        <div class="space-y-1 max-h-48 overflow-y-auto mb-3">
                            @foreach($posts as $post)
                            <label class="flex items-center gap-2 text-sm text-gray-400 hover:text-gray-200 cursor-pointer py-0.5">
                                <input type="checkbox" value="{{ $post->id }}" data-label="{{ $post->title }}" class="post-check rounded border-white/20 bg-[#111111]">
                                {{ $post->title }}
                            </label>
                            @endforeach
                        </div>
                        <button type="button" onclick="addChecked('post', '.post-check')"
                                class="px-3 py-1.5 bg-sky-600 hover:bg-sky-700 text-white text-xs font-medium rounded-md transition-colors">
                            {{ __('admin.menu_add_to_menu') }}
                        </button>
                    </div>
                </div>
                @endif

                @if(($products ?? collect())->isNotEmpty())
                {{-- Only while the shop catalog is on: MenuController passes published products of the menu language. --}}
                <div class="bg-[#1a1a1a] ring-1 ring-white/[0.06] rounded-lg overflow-hidden">
                    <button type="button" onclick="togglePanel('panel-products')"
                            class="w-full flex items-center justify-between px-3 py-2.5 text-sm font-medium text-gray-300 hover:text-white transition-colors">
                        <span>{{ __('shop.product') }}</span>
                        <svg id="chevron-panel-products" class="w-3 h-3 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    <div id="panel-products" class="hidden border-t border-white/[0.06] px-3 py-3">
                        <div class="space-y-1 max-h-48 overflow-y-auto mb-3">
                            @foreach($products as $product)
                            <label class="flex items-center gap-2 text-sm text-gray-400 hover:text-gray-200 cursor-pointer py-0.5">
                                <input type="checkbox" value="{{ $product->id }}" data-label="{{ $product->title }}" class="product-check rounded border-white/20 bg-[#111111]">
                                {{ $product->title }}
                            </label>
                            @endforeach
                        </div>
                        <button type="button" onclick="addChecked('product', '.product-check')"
                                class="px-3 py-1.5 bg-sky-600 hover:bg-sky-700 text-white text-xs font-medium rounded-md transition-colors">
                            {{ __('admin.menu_add_to_menu') }}
                        </button>
                    </div>
                </div>
                @endif

                @if($taxonomies->isNotEmpty())
                <div class="bg-[#1a1a1a] ring-1 ring-white/[0.06] rounded-lg overflow-hidden">
                    <button type="button" onclick="togglePanel('panel-taxonomies')"
                            class="w-full flex items-center justify-between px-3 py-2.5 text-sm font-medium text-gray-300 hover:text-white transition-colors">
                        <span>{{ __('admin.menu_item_type_taxonomy') }}</span>
                        <svg id="chevron-panel-taxonomies" class="w-3 h-3 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    <div id="panel-taxonomies" class="hidden border-t border-white/[0.06] px-3 py-3">
                        <div class="space-y-1 max-h-48 overflow-y-auto mb-3">
                            @foreach($taxonomies as $taxonomy)
                            @php
                                $taxonomyTypeKey = 'admin.taxonomy_type_' . $taxonomy->type;
                                $taxonomyTypeLabel = __($taxonomyTypeKey) !== $taxonomyTypeKey ? __($taxonomyTypeKey) : ucfirst($taxonomy->type);
                            @endphp
                            <label class="flex items-center gap-2 text-sm text-gray-400 hover:text-gray-200 cursor-pointer py-0.5">
                                <input type="checkbox" value="{{ $taxonomy->id }}" data-label="{{ $taxonomy->name }}" class="taxonomy-check rounded border-white/20 bg-[#111111]">
                                {{ $taxonomy->name }} <span class="text-gray-600 text-xs">({{ $taxonomyTypeLabel }})</span>
                            </label>
                            @endforeach
                        </div>
                        <button type="button" onclick="addChecked('taxonomy', '.taxonomy-check')"
                                class="px-3 py-1.5 bg-sky-600 hover:bg-sky-700 text-white text-xs font-medium rounded-md transition-colors">
                            {{ __('admin.menu_add_to_menu') }}
                        </button>
                    </div>
                </div>
                @endif
            </div>

            {{-- Right: Menu item list --}}
            <div class="lg:col-span-2">
                <form id="menu-items-form" method="POST" action="{{ route('admin.menus.items.sync', $menu) }}">
                    @csrf
                    @method('PUT')

                    <div class="bg-[#1a1a1a] ring-1 ring-white/[0.06] rounded-lg min-h-48 p-4">
                        <div id="menu-sortable" class="space-y-1.5"></div>
                        <div id="menu-empty" class="py-10 text-center text-sm text-gray-600" style="display:none">
                            {{ __('admin.menu_no_items') }}
                        </div>
                    </div>

                    <div class="mt-4 pt-4 border-t border-white/[0.06]">
                        <button id="items-save-btn" type="submit" disabled
                                class="px-4 py-2 bg-sky-600 hover:bg-sky-700 disabled:opacity-40 disabled:cursor-not-allowed text-white text-sm font-medium rounded-md transition-colors">
                            {{ __('admin.menu_save_items') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<style>
    .sub-sortable {
        min-height: 0;
        transition: min-height .15s ease, padding .15s ease, margin .15s ease, background .15s ease;
    }
    .sub-sortable.sub-empty {
        min-height: 0;
        padding-top: 0 !important;
        padding-bottom: 0 !important;
        margin-top: 0 !important;
        margin-bottom: 0 !important;
    }
    .is-dragging .sub-sortable.sub-empty {
        min-height: 28px;
        margin-top: .25rem !important;
        margin-bottom: .5rem !important;
        border-radius: .375rem;
        background: rgba(16,185,129,.05);
        border: 1px dashed rgba(16,185,129,.25);
    }
    .sortable-ghost { opacity: .4; }
    .sortable-chosen { transform: scale(1.01); box-shadow: 0 0 0 1px rgba(16,185,129,.35); }
    .sortable-drag { cursor: grabbing !important; }
</style>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<script>
(function () {
    // ── Settings form dirty detection ─────────────────────────────
    const settingsForm = document.getElementById('settings-form');
    const settingsSaveBtn = document.getElementById('settings-save-btn');

    function settingsSnapshot() {
        const data = new FormData(settingsForm);
        data.delete('_token');
        data.delete('_method');
        const entries = [];
        for (const [k, v] of data.entries()) entries.push(k + '=' + v);
        return entries.join('&');
    }

    const initialSettingsSnapshot = settingsSnapshot();

    function updateSettingsDirty() {
        settingsSaveBtn.disabled = settingsSnapshot() === initialSettingsSnapshot;
    }

    settingsForm.addEventListener('input', updateSettingsDirty);
    settingsForm.addEventListener('change', updateSettingsDirty);

    // ── Items store ───────────────────────────────────────────────
    const store = {};
    const initial = @json(flattenMenuTree($menuItems));
    initial.forEach(i => { store[i.id] = Object.assign({}, i); });

    let nextId = Date.now();
    function newId() { return ++nextId; }

    const itemsSaveBtn = document.getElementById('items-save-btn');

    function markItemsDirty() {
        itemsSaveBtn.disabled = false;
    }

    // ── Render ────────────────────────────────────────────────────
    function render() {
        const container = document.getElementById('menu-sortable');
        container.innerHTML = '';
        roots().forEach(item => container.appendChild(makeEl(item)));
        document.getElementById('menu-empty').style.display = Object.keys(store).length ? 'none' : 'block';
        initSortable(container, null);
    }

    function roots() {
        return Object.values(store).filter(i => !i.parent_id).sort((a, b) => a.order - b.order);
    }

    function childrenOf(parentId) {
        return Object.values(store).filter(i => i.parent_id === parentId).sort((a, b) => a.order - b.order);
    }

    function makeEl(item) {
        const children = childrenOf(item.id);
        const div = document.createElement('div');
        div.className = 'menu-item bg-[#141414] border border-white/[0.06] rounded-lg';
        div.dataset.id = item.id;

        const typeLabels = {
            custom_link: '{{ __('admin.menu_item_type_custom_link') }}',
            page:        '{{ __('admin.menu_item_type_page') }}',
            post:        '{{ __('admin.menu_item_type_post') }}',
            taxonomy:    '{{ __('admin.menu_item_type_taxonomy') }}',
            product:     @json(__('shop.product')),
        };

        div.innerHTML = `
            <div class="flex items-center gap-2 px-3 py-2.5">
                <svg class="handle w-4 h-4 text-gray-600 shrink-0 cursor-grab" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16"/>
                </svg>
                <span class="flex-1 text-sm text-white truncate item-label-text">${esc(item.label)}</span>
                ${item.translation_status === 'needs_review' ? `<span class="text-xs text-yellow-400 px-1.5 py-0.5 bg-yellow-500/10 rounded shrink-0">{{ __('admin.needs_review') }}</span>` : ''}
                <span class="text-xs text-gray-600 px-1.5 py-0.5 bg-white/5 rounded shrink-0">${esc(typeLabels[item.type] || item.type)}</span>
                <button type="button" class="toggle-btn text-gray-600 hover:text-gray-300 transition-colors shrink-0">
                    <svg class="w-3.5 h-3.5 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
            </div>
            <div class="item-settings hidden border-t border-white/[0.06] px-3 py-3 space-y-2.5">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">{{ __('admin.menu_item_label') }}</label>
                    <input type="text" class="label-input w-full bg-[#111111] border border-white/10 text-white text-xs rounded px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-sky-500/50"
                           value="${esc(item.label)}">
                </div>
                ${item.type === 'custom_link' ? `
                <div>
                    <label class="block text-xs text-gray-500 mb-1">{{ __('admin.menu_item_url') }}</label>
                    <input type="text" class="url-input w-full bg-[#111111] border border-white/10 text-white text-xs rounded px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-sky-500/50"
                           value="${esc(item.url || '')}">
                </div>` : ''}
                <div>
                    <label class="block text-xs text-gray-500 mb-1">{{ __('admin.menu_item_target') }}</label>
                    <select class="target-select bg-[#111111] border border-white/10 text-white text-xs rounded px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-sky-500/50">
                        <option value="_self" ${item.target !== '_blank' ? 'selected' : ''}>{{ __('admin.menu_item_target_self') }}</option>
                        <option value="_blank" ${item.target === '_blank' ? 'selected' : ''}>{{ __('admin.menu_item_target_blank') }}</option>
                    </select>
                </div>
                <button type="button" class="remove-btn text-xs text-red-400 hover:text-red-300 transition-colors">
                    {{ __('admin.menu_item_remove') }}
                </button>
            </div>
            <div class="sub-sortable pl-5 ml-3 border-l border-white/[0.06] space-y-1.5 mt-1 mb-2 ${children.length ? '' : 'sub-empty'}"></div>
        `;

        div.querySelector('.toggle-btn').addEventListener('click', () => {
            const settings = div.querySelector('.item-settings');
            const svg = div.querySelector('.toggle-btn svg');
            settings.classList.toggle('hidden');
            svg.style.transform = settings.classList.contains('hidden') ? '' : 'rotate(180deg)';
        });

        div.querySelector('.remove-btn').addEventListener('click', () => removeItem(item.id));

        div.querySelector('.label-input').addEventListener('input', function () {
            store[item.id].label = this.value;
            div.querySelector('.item-label-text').textContent = this.value;
            markItemsDirty();
        });

        if (item.type === 'custom_link') {
            div.querySelector('.url-input')?.addEventListener('input', function () {
                store[item.id].url = this.value;
                markItemsDirty();
            });
        }

        div.querySelector('.target-select').addEventListener('change', function () {
            store[item.id].target = this.value;
            markItemsDirty();
        });

        const sub = div.querySelector('.sub-sortable');
        children.forEach(child => sub.appendChild(makeEl(child)));
        initSortable(sub, item.id);

        return div;
    }

    const menuStructurePanel = document.getElementById('menu-sortable').parentElement;

    function initSortable(container, parentId) {
        if (container._s) container._s.destroy();
        container._s = new Sortable(container, {
            group: 'menu-items',
            handle: '.handle',
            animation: 150,
            fallbackOnBody: true,
            swapThreshold: 0.65,
            emptyInsertThreshold: 12,
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            dragClass: 'sortable-drag',
            onStart: function () {
                menuStructurePanel?.classList.add('is-dragging');
            },
            onEnd: function () {
                menuStructurePanel?.classList.remove('is-dragging');
                syncOrderFromDom();
                markItemsDirty();
            },
        });
    }

    function syncOrderFromDom() {
        collectOrder(document.getElementById('menu-sortable'), null);
        document.querySelectorAll('.sub-sortable').forEach(sub => {
            sub.classList.toggle('sub-empty', sub.children.length === 0);
        });
    }

    function collectOrder(container, parentId) {
        Array.from(container.children).forEach((el, idx) => {
            const id = parseInt(el.dataset.id);
            if (!id || !store[id]) return;
            store[id].parent_id = parentId;
            store[id].order = idx;
            const sub = el.querySelector(':scope > .sub-sortable');
            if (sub) collectOrder(sub, id);
        });
    }

    function removeItem(id) {
        function removeDescendants(pid) {
            Object.values(store).filter(i => i.parent_id === pid).forEach(i => {
                removeDescendants(i.id);
                delete store[i.id];
            });
        }
        removeDescendants(id);
        delete store[id];
        document.querySelector(`.menu-item[data-id="${id}"]`)?.remove();
        document.getElementById('menu-empty').style.display = Object.keys(store).length ? 'none' : 'block';
        markItemsDirty();
    }

    // ── Add items ─────────────────────────────────────────────────
    window.addCustomLink = function () {
        const url   = document.getElementById('custom-url').value.trim();
        const label = document.getElementById('custom-label').value.trim() || url;
        if (!url) return;
        const id = newId();
        store[id] = { id, parent_id: null, label, type: 'custom_link', linkable_id: null, url, target: '_self', order: roots().length };
        document.getElementById('menu-sortable').appendChild(makeEl(store[id]));
        initSortable(document.getElementById('menu-sortable'), null);
        document.getElementById('custom-url').value = '';
        document.getElementById('custom-label').value = '';
        document.getElementById('menu-empty').style.display = 'none';
        markItemsDirty();
    };

    window.addChecked = function (type, selector) {
        let order = roots().length;
        document.querySelectorAll(selector + ':checked').forEach(cb => {
            const id = newId();
            store[id] = { id, parent_id: null, label: cb.dataset.label, type, linkable_id: parseInt(cb.value), url: null, target: '_self', order: order++ };
            document.getElementById('menu-sortable').appendChild(makeEl(store[id]));
            cb.checked = false;
        });
        initSortable(document.getElementById('menu-sortable'), null);
        document.getElementById('menu-empty').style.display = Object.keys(store).length ? 'none' : 'block';
        markItemsDirty();
    };

    window.togglePanel = function (id) {
        const panel   = document.getElementById(id);
        const chevron = document.getElementById('chevron-' + id);
        panel.classList.toggle('hidden');
        chevron.style.transform = panel.classList.contains('hidden') ? '' : 'rotate(180deg)';
    };

    // ── Form submit ───────────────────────────────────────────────
    document.getElementById('menu-items-form').addEventListener('submit', function (e) {
        e.preventDefault();
        syncOrderFromDom();

        this.querySelectorAll('input[name^="items["]').forEach(el => el.remove());

        const sorted = Object.values(store).sort((a, b) => (a.order ?? 0) - (b.order ?? 0));
        sorted.forEach((item, idx) => {
            const fields = {
                id:          item.id,
                parent_id:   item.parent_id ?? '',
                label:       item.label ?? '',
                type:        item.type ?? 'custom_link',
                linkable_id: item.linkable_id ?? '',
                url:         item.url ?? '',
                target:      item.target ?? '_self',
                order:       idx,
            };
            Object.entries(fields).forEach(([field, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = `items[${idx}][${field}]`;
                input.value = value;
                this.appendChild(input);
            });
        });

        this.submit();
    });

    function esc(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    render();
})();
</script>
@endpush
@endsection
