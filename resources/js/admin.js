import '../css/admin.css';
import { initEditorEngine } from './editor-engine.js';

const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
if (token) {
    window.csrfToken = token;
}
const adminI18n = window.adminI18n || {};
const t = (key, fallback = '') => adminI18n[key] ?? fallback;
const maxUploadKb = Number(adminI18n.maxUploadKb || 0);
window.initEditorEngine = initEditorEngine;

// TinyMCE is heavy, so its chunk is only fetched on screens that actually use it.
if (document.querySelector('[data-rich-text]')) {
    import('./rich-text.js').then((module) => module.initRichText());
}

const selectors = Array.from(document.querySelectorAll('.media-selector'));
const libraryModal = document.getElementById('media-library-modal');
const libraryClose = document.getElementById('media-library-close');
const libraryGrid = document.getElementById('media-library-grid');
const librarySearch = document.getElementById('media-library-search');
const libraryRefresh = document.getElementById('media-library-refresh');
const libraryStatus = document.getElementById('media-library-status');
const libraryPagination = document.getElementById('media-library-pagination');

let activeSelector = null;
let activeLibraryCallback = null;
let searchTimer = null;
const LIBRARY_PAGE_SIZE = 20;
const libraryItemsById = new Map();
const libraryState = {
    currentPage: 1,
    lastPage: 1,
    isLoading: false,
    requestId: 0,
    search: '',
    type: '',
};

// Upload modal
const uploadModal = document.getElementById('upload-modal');
const uploadClose = document.getElementById('upload-close');
const uploadForm = document.getElementById('upload-form');
const dropZone = document.getElementById('drop-zone');
const fileInput = document.getElementById('file-input');
const uploadProgress = document.getElementById('upload-progress');
const fileNameLabel = document.getElementById('file-name');
const uploadEmptyState = document.getElementById('upload-empty-state');
const uploadPreviewWrap = document.getElementById('upload-preview-wrap');
const uploadPreviewImage = document.getElementById('upload-preview-image');
const uploadRemoveFile = document.getElementById('upload-remove-file');

let uploadFromLibrary = false;
let selectedUploadFiles = [];
let uploadPreviewUrl = null;

function clearSelectedUploadFiles() {
    selectedUploadFiles = [];
    if (fileInput) {
        fileInput.value = '';
    }
    if (fileNameLabel) {
        fileNameLabel.textContent = '';
    }
    if (uploadPreviewUrl) {
        URL.revokeObjectURL(uploadPreviewUrl);
        uploadPreviewUrl = null;
    }
    if (uploadPreviewImage) {
        uploadPreviewImage.src = '';
        uploadPreviewImage.alt = '';
    }
    uploadPreviewWrap?.classList.add('hidden');
    uploadEmptyState?.classList.remove('hidden');
    uploadRemoveFile?.classList.add('hidden');
}

function setSelectedUploadFiles(files) {
    if (!files?.length) {
        clearSelectedUploadFiles();
        return;
    }

    selectedUploadFiles = Array.from(files);
    const oversizedFile = maxUploadKb > 0
        ? selectedUploadFiles.find((file) => file.size > maxUploadKb * 1024)
        : null;

    if (fileNameLabel) {
        fileNameLabel.textContent = selectedUploadFiles.length === 1
            ? selectedUploadFiles[0].name
            : `${selectedUploadFiles.length} files selected`;
    }

    if (oversizedFile) {
        const maxMb = (maxUploadKb / 1024).toFixed(1);
        alert(`${t('validationFileMax', t('uploadFailed'))} (max ${maxMb} MB): ${oversizedFile.name}`);
        clearSelectedUploadFiles();
        return;
    }

    if (selectedUploadFiles.length !== 1) {
        if (uploadPreviewUrl) {
            URL.revokeObjectURL(uploadPreviewUrl);
            uploadPreviewUrl = null;
        }
        uploadPreviewWrap?.classList.add('hidden');
        uploadRemoveFile?.classList.remove('hidden');
        uploadEmptyState?.classList.remove('hidden');
        return;
    }

    const file = selectedUploadFiles[0];
    const isImage = String(file.type || '').startsWith('image/');
    if (!isImage) {
        if (uploadPreviewUrl) {
            URL.revokeObjectURL(uploadPreviewUrl);
            uploadPreviewUrl = null;
        }
        uploadPreviewWrap?.classList.add('hidden');
        uploadRemoveFile?.classList.remove('hidden');
        uploadEmptyState?.classList.remove('hidden');
        return;
    }

    if (uploadPreviewUrl) {
        URL.revokeObjectURL(uploadPreviewUrl);
    }
    uploadPreviewUrl = URL.createObjectURL(file);

    if (uploadPreviewImage) {
        uploadPreviewImage.src = uploadPreviewUrl;
        uploadPreviewImage.alt = file.name;
    }
    uploadEmptyState?.classList.add('hidden');
    uploadPreviewWrap?.classList.remove('hidden');
    uploadRemoveFile?.classList.remove('hidden');
}

function openUpload(fromLibrary = false) {
    uploadFromLibrary = fromLibrary;
    uploadModal?.classList.remove('hidden');
}

function closeUpload() {
    uploadModal?.classList.add('hidden');
    uploadForm?.reset();
    clearSelectedUploadFiles();
}

uploadClose?.addEventListener('click', closeUpload);
dropZone?.addEventListener('click', () => fileInput?.click());
dropZone?.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropZone.classList.add('border-sky-500/50');
});
dropZone?.addEventListener('dragleave', () => {
    dropZone.classList.remove('border-sky-500/50');
});
dropZone?.addEventListener('drop', (e) => {
    e.preventDefault();
    dropZone.classList.remove('border-sky-500/50');
    if (e.dataTransfer.files.length) {
        setSelectedUploadFiles(e.dataTransfer.files);
    }
});
fileInput?.addEventListener('change', () => {
    setSelectedUploadFiles(fileInput.files || []);
});
uploadRemoveFile?.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    clearSelectedUploadFiles();
});

uploadForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!selectedUploadFiles.length) {
        alert(t('chooseFile', t('uploadFailed')));
        return;
    }

    const formData = new FormData(uploadForm);
    formData.delete('files[]');
    selectedUploadFiles.forEach((file) => {
        formData.append('files[]', file);
    });

    uploadProgress?.classList.remove('hidden');
    try {
        const response = await fetch('/admin/media', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': window.csrfToken,
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: formData,
        });
        if (response.ok) {
            const payload = await response.json();
            const uploadedItems = Array.isArray(payload?.data)
                ? payload.data
                : (payload ? [payload] : []);

            if (uploadFromLibrary && (activeSelector || activeLibraryCallback)) {
                closeUpload();

                if (uploadedItems.length === 1 && activeLibraryCallback) {
                    const { onSelect } = activeLibraryCallback;
                    closeLibrary();
                    onSelect(uploadedItems[0]);
                } else if (uploadedItems.length === 1) {
                    renderSelectorPreview(activeSelector, uploadedItems[0]);
                    notifySelectorChange(activeSelector);
                    closeLibrary();
                } else {
                    void loadLibrary(1);
                }
            } else {
                window.location.reload();
            }
        } else {
            const contentType = response.headers.get('content-type') || '';
            const data = contentType.includes('application/json') ? await response.json() : null;
            const validationError = data?.errors ? Object.values(data.errors).flat()[0] : null;
            alert(validationError || data?.error || data?.message || t('uploadFailed'));
        }
    } catch {
        alert(t('uploadFailed'));
    } finally {
        uploadProgress?.classList.add('hidden');
    }
});

document.getElementById('upload-btn')?.addEventListener('click', () => openUpload(false));
document.getElementById('upload-btn-empty')?.addEventListener('click', () => openUpload(false));
document.getElementById('media-library-upload')?.addEventListener('click', () => openUpload(true));

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function selectorNodes(selector) {
    return {
        input: selector.querySelector('.media-selector-input'),
        preview: selector.querySelector('.media-selector-preview'),
        clear: selector.querySelector('.media-selector-clear'),
        button: selector.querySelector('.media-selector-btn'),
    };
}

function notifySelectorChange(selector) {
    const { input } = selectorNodes(selector);

    if (!input) {
        return;
    }

    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
}

function renderSelectorPreview(selector, media) {
    const { input, preview, clear, button } = selectorNodes(selector);
    const defaultLabel = button?.dataset.label || button?.textContent || t('chooseFile');

    if (!preview || !input || !clear || !button) {
        return;
    }

    if (!media) {
        preview.innerHTML = '';
        input.value = '';
        clear.classList.add('hidden');
        button.textContent = defaultLabel;
        return;
    }

    input.value = media.id;
    clear.classList.remove('hidden');
    button.textContent = defaultLabel;

    const isImage = String(media.mime_type || '').startsWith('image/');
    preview.innerHTML = `
        <div class="flex items-center gap-3 rounded-lg border border-white/10 bg-[#141414] p-3">
            <div class="w-16 h-16 rounded-md overflow-hidden bg-[#1a1a1a] flex items-center justify-center shrink-0">
                ${isImage
                    ? `<img src="${escapeHtml(media.url)}" alt="${escapeHtml(media.alt || media.title || media.original_filename)}" class="w-full h-full object-cover">`
                    : `<svg class="w-8 h-8 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>`}
            </div>
            <div class="min-w-0">
                <p class="text-sm text-white truncate">${escapeHtml(media.title || media.original_filename)}</p>
                <p class="text-xs text-gray-500 truncate">${escapeHtml(media.original_filename || '')}</p>
            </div>
        </div>
    `;
}

async function fetchMediaItem(id) {
    const response = await fetch(`/admin/media/${id}`, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        throw new Error(t('unableToLoadMediaItem'));
    }

    return response.json();
}

function openLibrary(target) {
    if (typeof target?.onSelect === 'function') {
        activeSelector = null;
        activeLibraryCallback = target;
    } else {
        activeSelector = target;
        activeLibraryCallback = null;
    }

    if (!libraryModal) {
        return;
    }

    libraryModal.classList.remove('hidden');
    void loadLibrary(1);
}

function closeLibrary() {
    activeSelector = null;
    activeLibraryCallback = null;

    if (!libraryModal) {
        return;
    }

    libraryModal.classList.add('hidden');
}

// Lets any feature reuse the shared media library, e.g. TinyMCE image insertion.
window.openMediaLibrary = (options) => openLibrary(options);

function renderLibraryItems(items) {
    if (!libraryGrid) {
        return;
    }

    libraryItemsById.clear();

    if (!items.length) {
        libraryGrid.innerHTML = `<div class="col-span-full text-sm text-gray-500 py-6 text-center">${escapeHtml(t('noMediaFound'))}</div>`;
        return;
    }

    libraryGrid.innerHTML = items.map((item) => {
        libraryItemsById.set(String(item.id), item);
        const isImage = String(item.mime_type || '').startsWith('image/');

        return `
            <button type="button" class="media-library-item text-left bg-[#111111] border border-white/10 rounded-lg overflow-hidden hover:border-sky-500/50 transition-colors" data-id="${item.id}">
                <div class="aspect-square bg-[#1a1a1a] flex items-center justify-center overflow-hidden">
                    ${isImage
                        ? `<img src="${escapeHtml(item.url)}" alt="${escapeHtml(item.alt || item.title || item.original_filename)}" class="w-full h-full object-contain">`
                        : `<svg class="w-10 h-10 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>`}
                </div>
                <div class="p-3">
                    <p class="text-sm text-white truncate">${escapeHtml(item.title || item.original_filename)}</p>
                    <p class="text-xs text-gray-500 truncate">${escapeHtml(item.original_filename || '')}</p>
                </div>
            </button>
        `;
    }).join('');
}

function renderLibraryPagination(currentPage, lastPage) {
    if (!libraryPagination) {
        return;
    }

    if (lastPage <= 1) {
        libraryPagination.innerHTML = '';
        libraryPagination.classList.add('hidden');
        return;
    }

    libraryPagination.classList.remove('hidden');

    const btnBase = 'px-3 py-1.5 text-xs rounded-md transition-colors border font-medium';
    const btnActive = `${btnBase} bg-sky-600 border-sky-600 text-white`;
    const btnInactive = `${btnBase} bg-transparent border-white/10 text-gray-400 hover:bg-gray-800`;
    const btnDisabled = `${btnBase} bg-transparent border-white/[0.05] text-gray-700 cursor-not-allowed`;

    const prevDisabled = currentPage <= 1;
    const nextDisabled = currentPage >= lastPage;

    const pageStart = Math.max(1, currentPage - 2);
    const pageEnd = Math.min(lastPage, currentPage + 2);
    const pageButtons = [];
    for (let p = pageStart; p <= pageEnd; p++) {
        pageButtons.push(
            `<button type="button" class="${p === currentPage ? btnActive : btnInactive}" data-page="${p}">${p}</button>`
        );
    }

    const pageLabel = escapeHtml(t('pageOf').replace(':current', String(currentPage)).replace(':total', String(lastPage)));

    libraryPagination.innerHTML = `
        <span class="text-xs text-gray-500">${pageLabel}</span>
        <div class="flex items-center gap-1">
            <button type="button" class="${prevDisabled ? btnDisabled : btnInactive}" data-page="${currentPage - 1}" ${prevDisabled ? 'disabled' : ''}>${escapeHtml(t('previous'))}</button>
            ${pageButtons.join('')}
            <button type="button" class="${nextDisabled ? btnDisabled : btnInactive}" data-page="${currentPage + 1}" ${nextDisabled ? 'disabled' : ''}>${escapeHtml(t('next'))}</button>
        </div>
    `;
}

function currentLibraryFilters() {
    return {
        search: librarySearch?.value?.trim() || '',
        type: activeSelector?.dataset?.type || activeLibraryCallback?.type || '',
    };
}

async function loadLibrary(page = 1) {
    if (!libraryGrid || !libraryStatus) {
        return;
    }

    if (libraryState.isLoading) {
        return;
    }

    if (page === 1) {
        const filters = currentLibraryFilters();
        libraryState.search = filters.search;
        libraryState.type = filters.type;
    }

    const requestId = ++libraryState.requestId;
    libraryState.isLoading = true;
    libraryGrid.innerHTML = '';
    libraryStatus.textContent = t('loadingMedia');
    libraryStatus.classList.remove('hidden');

    const params = new URLSearchParams();
    if (libraryState.search) {
        params.set('search', libraryState.search);
    }
    if (libraryState.type) {
        params.set('type', libraryState.type);
    }
    params.set('page', String(page));
    params.set('per_page', String(LIBRARY_PAGE_SIZE));

    try {
        const response = await fetch(`/admin/media?${params.toString()}`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            throw new Error(t('unableToLoadMediaLibrary'));
        }

        const payload = await response.json();
        if (requestId !== libraryState.requestId) {
            return;
        }

        libraryState.currentPage = Number(payload.current_page || page);
        libraryState.lastPage = Number(payload.last_page || 1);

        renderLibraryItems(payload.data || []);
        renderLibraryPagination(libraryState.currentPage, libraryState.lastPage);
        libraryStatus.classList.add('hidden');
    } catch (_) {
        if (requestId === libraryState.requestId) {
            libraryStatus.textContent = t('unableToLoadMediaLibrary');
            libraryStatus.classList.remove('hidden');
            renderLibraryPagination(0, 0);
        }
    } finally {
        if (requestId === libraryState.requestId) {
            libraryState.isLoading = false;
        }
    }
}

function initMediaSelector(selector) {
    if (!selector || selector.dataset.selectorReady === '1') {
        return;
    }
    selector.dataset.selectorReady = '1';

    const { input, clear, button } = selectorNodes(selector);

    button?.addEventListener('click', () => openLibrary(selector));

    clear?.addEventListener('click', () => {
        renderSelectorPreview(selector, null);
        notifySelectorChange(selector);
    });

    if (input?.value) {
        fetchMediaItem(input.value)
            .then((media) => renderSelectorPreview(selector, media))
            .catch(() => renderSelectorPreview(selector, null));
    }
}

selectors.forEach(initMediaSelector);

// Repeaters clone rows at runtime, so their selectors are wired on demand.
window.initMediaSelector = initMediaSelector;
window.initMediaSelectorsWithin = (root) => {
    root?.querySelectorAll?.('.media-selector').forEach(initMediaSelector);
};

libraryClose?.addEventListener('click', closeLibrary);

libraryModal?.addEventListener('click', (event) => {
    if (event.target === libraryModal) {
        closeLibrary();
    }
});

libraryRefresh?.addEventListener('click', () => loadLibrary(1));

librarySearch?.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadLibrary(1), 250);
});

libraryPagination?.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-page]');
    if (!btn || btn.disabled) {
        return;
    }
    const page = Number(btn.dataset.page);
    if (page > 0) {
        void loadLibrary(page);
    }
});

libraryGrid?.addEventListener('click', (event) => {
    const button = event.target.closest('.media-library-item');
    if (!button || (!activeSelector && !activeLibraryCallback)) {
        return;
    }

    const selected = libraryItemsById.get(String(button.dataset.id));
    if (!selected) {
        return;
    }

    if (activeLibraryCallback) {
        const { onSelect } = activeLibraryCallback;
        closeLibrary();
        onSelect(selected);
        return;
    }

    const selector = activeSelector;
    renderSelectorPreview(selector, selected);
    notifySelectorChange(selector);
    closeLibrary();
});

// Shared confirmation modal for destructive actions.
const confirmModal = document.getElementById('confirm-modal');

const CONFIRM_TONES = {
    danger: 'bg-red-600 hover:bg-red-700',
    primary: 'bg-sky-600 hover:bg-sky-700',
};

window.confirmAction = (options) => {
    const config = typeof options === 'string' ? { message: options } : (options || {});
    const titleNode = document.getElementById('confirm-modal-title');
    const messageNode = document.getElementById('confirm-modal-message');
    const acceptButton = document.getElementById('confirm-modal-accept');
    const cancelButton = document.getElementById('confirm-modal-cancel');

    if (!confirmModal || !messageNode || !acceptButton || !cancelButton || !titleNode) {
        return Promise.resolve(false);
    }

    titleNode.textContent = config.title || titleNode.dataset.default || '';
    messageNode.textContent = config.message || '';
    acceptButton.textContent = config.confirmLabel || acceptButton.dataset.default || '';
    cancelButton.textContent = config.cancelLabel || cancelButton.dataset.default || '';

    acceptButton.className = `px-3 py-2 text-white text-sm font-medium rounded-md transition-colors ${
        CONFIRM_TONES[config.tone] || CONFIRM_TONES.danger
    }`;

    confirmModal.classList.remove('hidden');

    const previouslyFocused = document.activeElement;
    acceptButton.focus();

    return new Promise((resolve) => {
        const close = (result) => {
            confirmModal.classList.add('hidden');
            acceptButton.removeEventListener('click', onAccept);
            cancelButton.removeEventListener('click', onCancel);
            confirmModal.removeEventListener('click', onBackdrop);
            document.removeEventListener('keydown', onKeydown);
            previouslyFocused?.focus?.();
            resolve(result);
        };

        const onAccept = () => close(true);
        const onCancel = () => close(false);
        const onBackdrop = (event) => {
            if (event.target === confirmModal) close(false);
        };
        const onKeydown = (event) => {
            if (event.key === 'Escape') close(false);
        };

        acceptButton.addEventListener('click', onAccept);
        cancelButton.addEventListener('click', onCancel);
        confirmModal.addEventListener('click', onBackdrop);
        document.addEventListener('keydown', onKeydown);
    });
};

// Administrable logo repeater: one nested accordion per item, with ordering,
// dynamic labels and confirmed deletion.
document.querySelectorAll('[data-logo-repeater]').forEach((repeater) => {
    const list = repeater.querySelector('[data-logo-list]');
    const template = repeater.querySelector('[data-logo-template]');
    const addButton = repeater.querySelector('[data-logo-add]');
    const emptyMessage = repeater.querySelector('[data-logo-empty]');

    if (!list || !template || !addButton) {
        return;
    }

    const fallbackLabel = (position) =>
        t('logoItemFallbackLabel', 'Logo :position').replace(':position', position);

    const rows = () => Array.from(list.querySelectorAll('[data-logo-item]'));

    const setAccordion = (row, open) => {
        row.querySelector('[data-logo-accordion-body]')?.classList.toggle('hidden', !open);
        row.querySelector('[data-logo-accordion-icon]')?.classList.toggle('rotate-180', open);
        row.querySelector('[data-logo-accordion-toggle]')?.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    const syncRowLabel = (row, position) => {
        const label = row.querySelector('[data-logo-item-label]');
        const input = row.querySelector('[data-logo-label-input]');
        if (!label) {
            return;
        }
        label.textContent = input?.value.trim() || fallbackLabel(position);
    };

    // Rows must carry explicit, contiguous indexes: with `logos[][field]` PHP would
    // build one entry per input instead of one entry per row.
    const refreshRows = () => {
        const items = rows();

        items.forEach((row, index) => {
            row.querySelectorAll('[name]').forEach((field) => {
                field.name = field.name.replace(/\[logos\]\[[^\]]*\]/, `[logos][${index}]`);
            });

            const selector = row.querySelector('.media-selector');
            const nameField = row.querySelector('[data-logo-label-input]');
            if (selector && nameField) {
                selector.dataset.name = nameField.name.replace('[label]', '[image_id]');
            }

            const up = row.querySelector('[data-logo-move-up]');
            const down = row.querySelector('[data-logo-move-down]');
            if (up) up.disabled = index === 0;
            if (down) down.disabled = index === items.length - 1;

            syncRowLabel(row, index + 1);
        });

        emptyMessage?.classList.toggle('hidden', items.length > 0);
    };

    // Adding, removing or reordering changes the payload without firing an input
    // event, so the editor would not see the form as dirty and Save would stay off.
    const notifyStructureChange = () => {
        repeater.dispatchEvent(new Event('change', { bubbles: true }));
    };

    addButton.addEventListener('click', () => {
        const row = template.content.firstElementChild.cloneNode(true);
        list.appendChild(row);
        refreshRows();
        window.initMediaSelectorsWithin(row);
        setAccordion(row, true);
        notifyStructureChange();
        row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        row.querySelector('.media-selector-btn')?.focus();
    });

    list.addEventListener('click', async (event) => {
        const row = event.target.closest('[data-logo-item]');
        if (!row) {
            return;
        }

        if (event.target.closest('[data-logo-accordion-toggle]')) {
            const body = row.querySelector('[data-logo-accordion-body]');
            setAccordion(row, body?.classList.contains('hidden') ?? false);
            return;
        }

        if (event.target.closest('[data-logo-move-up]')) {
            const previous = row.previousElementSibling;
            if (previous) {
                list.insertBefore(row, previous);
                refreshRows();
                notifyStructureChange();
                row.querySelector('[data-logo-move-up]')?.focus();
            }
            return;
        }

        if (event.target.closest('[data-logo-move-down]')) {
            const next = row.nextElementSibling;
            if (next) {
                list.insertBefore(next, row);
                refreshRows();
                notifyStructureChange();
                row.querySelector('[data-logo-move-down]')?.focus();
            }
            return;
        }

        if (event.target.closest('[data-logo-remove]')) {
            const label = row.querySelector('[data-logo-item-label]')?.textContent?.trim() || '';
            const message = t('confirmRemoveLogo', 'This logo will be removed.').replace(':item', label);

            if (await window.confirmAction({ message, confirmLabel: t('remove', 'Remove') })) {
                row.remove();
                refreshRows();
                notifyStructureChange();
            }
        }
    });

    list.addEventListener('input', (event) => {
        const input = event.target.closest('[data-logo-label-input]');
        if (!input) {
            return;
        }

        const row = input.closest('[data-logo-item]');
        syncRowLabel(row, rows().indexOf(row) + 1);
    });

    refreshRows();
});

// Generic administrable repeater (docs/live-editor-ui-ux.md, docs/forms.md).
// Markup contract: [data-repeater data-repeater-key="<field>"] wrapping
// [data-repeater-list] > [data-repeater-item]*, a <template data-repeater-template>,
// [data-repeater-add] and optional [data-repeater-empty]. Items expose
// [data-repeater-move-up|move-down|remove] and [data-repeater-label-input]; multi-field
// items add the accordion hooks [data-repeater-toggle|body|icon] and [data-repeater-item-label],
// single-field items render as flat rows without them. Input names use [<key>][<index>].
document.querySelectorAll('[data-repeater]').forEach((repeater) => {
    const key = repeater.dataset.repeaterKey;
    const list = repeater.querySelector('[data-repeater-list]');
    const template = repeater.querySelector('[data-repeater-template]');
    const addButton = repeater.querySelector('[data-repeater-add]');
    const emptyMessage = repeater.querySelector('[data-repeater-empty]');

    if (!key || !list || !template || !addButton) {
        return;
    }

    const fallbackLabel = (position) => (repeater.dataset.itemLabel || 'Item :position').replace(':position', position);
    const rows = () => Array.from(list.querySelectorAll('[data-repeater-item]'));
    const indexPattern = new RegExp(`\\[${key}\\]\\[[^\\]]*\\]`);

    const setAccordion = (row, open) => {
        row.querySelector('[data-repeater-body]')?.classList.toggle('hidden', !open);
        row.querySelector('[data-repeater-icon]')?.classList.toggle('rotate-180', open);
        row.querySelector('[data-repeater-toggle]')?.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    const syncRowLabel = (row, position) => {
        const label = row.querySelector('[data-repeater-item-label]');
        const input = row.querySelector('[data-repeater-label-input]');
        if (label) {
            label.textContent = input?.value.trim() || fallbackLabel(position);
        }
    };

    const refreshRows = () => {
        const items = rows();

        items.forEach((row, index) => {
            row.querySelectorAll('[name]').forEach((field) => {
                field.name = field.name.replace(indexPattern, `[${key}][${index}]`);
            });
            row.querySelectorAll('.media-selector').forEach((selector) => {
                const hidden = selector.querySelector('input[type="hidden"][name]');
                if (hidden) selector.dataset.name = hidden.name;
            });

            const up = row.querySelector('[data-repeater-move-up]');
            const down = row.querySelector('[data-repeater-move-down]');
            if (up) up.disabled = index === 0;
            if (down) down.disabled = index === items.length - 1;

            syncRowLabel(row, index + 1);
        });

        emptyMessage?.classList.toggle('hidden', items.length > 0);
    };

    const notifyStructureChange = () => repeater.dispatchEvent(new Event('change', { bubbles: true }));

    addButton.addEventListener('click', () => {
        const row = template.content.firstElementChild.cloneNode(true);
        list.appendChild(row);
        refreshRows();
        window.initMediaSelectorsWithin?.(row);
        setAccordion(row, true);
        notifyStructureChange();
        row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        row.querySelector('[data-repeater-label-input], input, select, textarea')?.focus();
    });

    list.addEventListener('click', async (event) => {
        const row = event.target.closest('[data-repeater-item]');
        if (!row) return;

        if (event.target.closest('[data-repeater-toggle]')) {
            setAccordion(row, row.querySelector('[data-repeater-body]')?.classList.contains('hidden') ?? false);
            return;
        }

        if (event.target.closest('[data-repeater-move-up]')) {
            const previous = row.previousElementSibling;
            if (previous) {
                list.insertBefore(row, previous);
                refreshRows();
                notifyStructureChange();
                row.querySelector('[data-repeater-move-up]')?.focus();
            }
            return;
        }

        if (event.target.closest('[data-repeater-move-down]')) {
            const next = row.nextElementSibling;
            if (next) {
                list.insertBefore(next, row);
                refreshRows();
                notifyStructureChange();
                row.querySelector('[data-repeater-move-down]')?.focus();
            }
            return;
        }

        if (event.target.closest('[data-repeater-remove]')) {
            const label = row.querySelector('[data-repeater-item-label]')?.textContent?.trim()
                || row.querySelector('[data-repeater-label-input]')?.value.trim()
                || fallbackLabel(rows().indexOf(row) + 1);
            const message = (repeater.dataset.confirmRemove || 'This item will be removed.').replace(':item', label);

            if (await window.confirmAction({ message, confirmLabel: repeater.dataset.confirmLabel || t('remove', 'Remove') })) {
                row.remove();
                refreshRows();
                notifyStructureChange();
            }
        }
    });

    list.addEventListener('input', (event) => {
        const input = event.target.closest('[data-repeater-label-input]');
        if (!input) return;
        const row = input.closest('[data-repeater-item]');
        syncRowLabel(row, rows().indexOf(row) + 1);
    });

    refreshRows();
});

// Any form carrying data-confirm is gated by the shared modal instead of window.confirm.
document.querySelectorAll('form[data-confirm]').forEach((form) => {
    let confirmed = false;

    form.addEventListener('submit', async (event) => {
        if (confirmed) {
            return;
        }

        event.preventDefault();

        const accepted = await window.confirmAction({
            title: form.dataset.confirmTitle || undefined,
            message: form.dataset.confirm,
            confirmLabel: form.dataset.confirmLabel || undefined,
            tone: form.dataset.confirmTone || 'danger',
        });

        if (accepted) {
            confirmed = true;
            form.submit();
        }
    });
});
