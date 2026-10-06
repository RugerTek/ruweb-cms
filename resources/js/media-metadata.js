export function initMediaMetadata() {
    const panel = document.getElementById('detail-panel');
    if (!panel) return;
    const locale = document.getElementById('detail-locale');
    const alt = document.getElementById('detail-alt');
    const title = document.getElementById('detail-title');
    const save = document.getElementById('detail-save');
    const deleteButton = document.getElementById('detail-delete');
    const status = document.getElementById('detail-metadata-status');
    const error = document.getElementById('detail-metadata-error');
    const base = panel.dataset.baseLocale;
    const i18n = window.adminI18n;
    let item = null;
    let original = {};
    let drafts = {};
    let selected = base;
    let saving = false;
    const normalize = (fields = {}) => ({ alt: fields.alt || '', title: fields.title || '' });
    const changed = () => Object.keys(drafts).filter(code => JSON.stringify(drafts[code]) !== JSON.stringify(normalize(original[code])));
    const stash = () => {
        if (item) drafts[selected] = { alt: alt.value, title: title.value };
    };
    const sync = () => { save.disabled = saving || changed().length === 0; };
    const render = () => {
        const fields = drafts[selected] || normalize();
        alt.value = fields.alt;
        title.value = fields.title;
        alt.placeholder = selected === base ? '' : drafts[base]?.alt || '';
        title.placeholder = selected === base ? '' : drafts[base]?.title || '';
        sync();
    };
    const canLeave = async () => {
        if (saving) return false;
        stash();
        if (changed().length === 0) return true;
        return window.confirmAction({
            title: i18n.unsavedChangesTitle,
            message: i18n.unsavedChangesMessage,
            confirmLabel: i18n.btnLeaveWithoutSaving,
            cancelLabel: i18n.btnKeepEditing,
            tone: 'primary',
        });
    };
    window.mediaMetadata = {
        canLeave,
        reset() { item = null; drafts = {}; original = {}; },
        async open(next) {
            if (item === next) return true;
            if (!await canLeave()) return false;
            item = next;
            original = JSON.parse(next.dataset.metadata);
            drafts = Object.fromEntries(Array.from(locale.options, option => [option.value, normalize(original[option.value])]));
            selected = base;
            locale.value = base;
            status.textContent = '';
            error.classList.add('hidden');
            render();
            return true;
        },
    };
    locale.addEventListener('change', () => { stash(); selected = locale.value; render(); });
    [alt, title].forEach(input => input.addEventListener('input', () => {
        stash(); sync(); status.textContent = ''; error.classList.add('hidden');
    }));
    window.addEventListener('beforeunload', event => {
        stash();
        if (saving || changed().length) { event.preventDefault(); event.returnValue = ''; }
    });
    save.addEventListener('click', async () => {
        stash();
        const codes = changed();
        if (!item || saving || !codes.length) return;
        saving = true;
        [locale, alt, title, deleteButton].filter(Boolean).forEach(input => input.disabled = true);
        sync(); error.classList.add('hidden'); status.textContent = '';
        try {
            const response = await fetch(`/admin/media/${item.dataset.id}`, {
                method: 'PUT',
                headers: { 'X-CSRF-TOKEN': window.csrfToken, 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ metadata: Object.fromEntries(codes.map(code => [code, drafts[code]])) }),
            });
            const payload = await response.json();
            if (!response.ok) {
                error.textContent = Object.values(payload.errors || {}).flat()[0] || i18n.mediaMetadataError;
                error.classList.remove('hidden');
                return;
            }
            original = payload.metadata;
            item.dataset.metadata = JSON.stringify(original);
            item.dataset.alt = payload.alt || '';
            item.dataset.title = payload.title || '';
            drafts = Object.fromEntries(Array.from(locale.options, option => [option.value, normalize(original[option.value])]));
            const image = item.querySelector('img');
            if (image) image.alt = payload.localized_alt || '';
            const preview = document.querySelector('#detail-preview img');
            if (preview) preview.alt = payload.localized_alt || '';
            status.textContent = i18n.mediaMetadataSaved;
            render();
        } catch (_) {
            error.textContent = i18n.mediaMetadataError;
            error.classList.remove('hidden');
        } finally {
            saving = false;
            [locale, alt, title, deleteButton].filter(Boolean).forEach(input => input.disabled = false);
            sync();
        }
    });
}
