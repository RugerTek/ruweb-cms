import { prepareEditorPreview } from './editor-preview.js';

export function initEditorEngine(config = {}) {
    const form = document.getElementById('editor-form');
    const iframe = document.getElementById('preview-frame');
    const headerSaveBtn = document.getElementById('save-btn');
    const updateBtn = document.getElementById('update-btn');
    const saveDraftBtn = document.getElementById('save-draft-btn');
    const publishBtn = document.getElementById('publish-btn');

    if (!form || !headerSaveBtn) {
        return;
    }

    const previewUrl = config.previewUrl || null;
    const savedMsg = config.savedMsg || 'Saved';
    const errorMsg = config.errorMsg || 'Save error';
    config.unsavedTitle = config.unsavedTitle || window.adminI18n?.unsavedChangesTitle || 'Unsaved changes';
    config.unsavedMessage = config.unsavedMessage || window.adminI18n?.unsavedChangesMessage || 'You have unsaved changes.';
    config.leaveLabel = config.leaveLabel || window.adminI18n?.btnLeaveWithoutSaving || 'Leave without saving';
    config.keepEditingLabel = config.keepEditingLabel || window.adminI18n?.btnKeepEditing || 'Keep editing';
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const lease = config.lease || window.editorLease;

    let previewTimer = null;
    let previewSequence = 0;
    let saving = false;
    let reloadAfterSave = false;
    let lockLost = false;
    let heartbeatPending = false;
    let lockDeadline = lease ? Date.now() + lease.ttlSeconds * 1000 : 0;
    let heartbeatTimer = null;
    let savedSnapshot = formSnapshot();
    let savedStatus = currentStatus();

    function formSnapshot(data = new FormData(form)) {
        data.delete('_token');
        data.delete('_method');
        data.delete('_editor_lock');

        const entries = [];
        for (const [key, value] of data.entries()) {
            entries.push([key, String(value)]);
        }

        return JSON.stringify(entries);
    }

    function isDirty() {
        return formSnapshot() !== savedSnapshot;
    }

    function updateDirtyState() {
        const dirty = isDirty() && !saving && !lockLost;
        if (updateBtn) updateBtn.disabled = !dirty;
        if (saveDraftBtn) saveDraftBtn.disabled = !dirty;
        headerSaveBtn.disabled = !dirty;
        if (publishBtn) publishBtn.disabled = saving || lockLost;
    }

    function loseLock() {
        lockLost = true;
        clearInterval(heartbeatTimer);
        const notice = document.getElementById('editor-lock-notice');
        if (notice) {
            notice.textContent = lease.lostMessage;
            notice.hidden = false;
        }
        updateDirtyState();
    }

    async function heartbeat() {
        if (!lease || lockLost || heartbeatPending) return;
        if (Date.now() >= lockDeadline) {
            loseLock();
            return;
        }
        heartbeatPending = true;
        try {
            const response = await fetch(lease.heartbeatUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ _editor_lock: lease.token }),
                signal: AbortSignal.timeout(10000),
            });
            if (response.ok) {
                await response.json();
                lockDeadline = Date.now() + lease.ttlSeconds * 1000;
            } else if ([401, 403, 419, 423].includes(response.status)) {
                loseLock();
            }
        } catch (_) {
            // A brief network interruption is safe while the server lease is alive.
        } finally {
            heartbeatPending = false;
            if (Date.now() >= lockDeadline) loseLock();
        }
    }

    function currentStatus() {
        return new FormData(form).get('status') || null;
    }

    function refreshPreview() {
        if (!previewUrl || !iframe) {
            return;
        }

        const sequence = ++previewSequence;
        const previewData = new FormData(form);
        previewData.delete('_method');

        let previousScrollY = 0;
        try {
            previousScrollY = iframe.contentWindow?.scrollY || 0;
        } catch (_) {}

        fetch(previewUrl, {
            method: 'POST',
            body: previewData,
            headers: csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {},
        })
            .then((res) => {
                if (!res.ok) {
                    return null;
                }

                return res.text();
            })
            .then((html) => {
                if (sequence !== previewSequence || typeof html !== 'string') {
                    return;
                }

                const restoreScroll = () => {
                    try {
                        iframe.contentWindow?.scrollTo(0, previousScrollY);
                    } catch (_) {}
                };

                iframe.addEventListener('load', restoreScroll, { once: true });
                iframe.srcdoc = prepareEditorPreview(html);
            })
            .catch(() => {});
    }

    async function save(statusOverride) {
        if (saving || lockLost) return;
        if (lease && Date.now() >= lockDeadline) {
            loseLock();
            return;
        }
        const beforeSave = new CustomEvent('editor:before-save', { cancelable: true });
        form.dispatchEvent(beforeSave);

        if (beforeSave.defaultPrevented) {
            updateDirtyState();
            return;
        }

        saving = true;
        updateDirtyState();

        try {
            const requestData = new FormData(form);
            if (statusOverride) {
                requestData.set('status', statusOverride);
            }
            const submittedSnapshot = formSnapshot(new FormData(form));

            const response = await fetch(form.action, {
                method: 'POST',
                body: requestData,
                headers: {
                    ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                    Accept: 'application/json',
                },
            });

            if (response.ok) {
                const nextStatus = requestData.get('status') || null;
                const statusInput = form.querySelector('[name="status"]');
                if (statusOverride && statusInput) statusInput.value = nextStatus;
                reloadAfterSave ||= Boolean(nextStatus && savedStatus && nextStatus !== savedStatus);
                // A publish action changes the submitted status, while edits made
                // during the request must remain dirty until their own save.
                const submittedEntries = JSON.parse(submittedSnapshot);
                savedSnapshot = statusOverride
                    ? JSON.stringify(submittedEntries.map(([key, value]) => [key, key === 'status' ? nextStatus : value]))
                    : submittedSnapshot;
                form.dispatchEvent(new CustomEvent('editor:saved'));
                showToast(savedMsg);

                if (reloadAfterSave && !isDirty()) {
                    window.removeEventListener('beforeunload', onBeforeUnload);
                    window.location.reload();
                    return;
                }

                savedStatus = nextStatus || savedStatus;
            } else {
                if (lease && [401, 403, 419, 423].includes(response.status)) loseLock();
                const message = await responseErrorMessage(response);
                showToast(message || errorMsg, 'error');
            }
        } catch (_) {
            showToast(errorMsg, 'error');
        } finally {
            saving = false;
            updateDirtyState();
        }
    }

    async function responseErrorMessage(response) {
        const contentType = response.headers.get('content-type') || '';

        if (!contentType.includes('application/json')) {
            return null;
        }

        try {
            const payload = await response.json();
            if (payload?.errors) window.editorFeedback?.showErrors(payload.errors);
            const firstError = payload?.errors
                ? Object.values(payload.errors).flat()[0]
                : null;

            return firstError || payload?.message || null;
        } catch (_) {
            return null;
        }
    }

    function onFormChange() {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(refreshPreview, 600);
        updateDirtyState();
    }

    function onBeforeUnload(event) {
        if (!isDirty()) return;
        event.preventDefault();
        event.returnValue = 'unsaved';
        return 'unsaved';
    }

    // Leaving through a link inside the editor is the common case, and the browser's
    // own beforeunload prompt is neither styled nor guaranteed to appear, so in-app
    // navigation is confirmed with the shared modal instead.
    async function onEditorLinkClick(event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const link = event.target.closest('a[href]');
        if (!link || link.target === '_blank' || link.hasAttribute('download') || !isDirty()) {
            return;
        }

        const destination = new URL(link.getAttribute('href'), window.location.href);
        if (destination.origin !== window.location.origin) {
            return;
        }
        if (destination.href === window.location.href) {
            return;
        }

        event.preventDefault();

        const confirmLeave = window.confirmAction
            ? await window.confirmAction({
                title: config.unsavedTitle,
                message: config.unsavedMessage,
                confirmLabel: config.leaveLabel,
                cancelLabel: config.keepEditingLabel,
                tone: 'danger',
            })
            : window.confirm(config.unsavedMessage);

        if (confirmLeave) {
            window.removeEventListener('beforeunload', onBeforeUnload);
            window.location.href = destination.href;
        }
    }

    form.addEventListener('editor:field-ready', event => {
        const { field, previousValue } = event.detail;
        savedSnapshot = JSON.stringify(JSON.parse(savedSnapshot).map(([key, value]) => [key, key === field.name && value === previousValue ? field.value : value]));
        clearTimeout(previewTimer); previewTimer = setTimeout(refreshPreview, 600);
        updateDirtyState();
    });
    form.addEventListener('input', onFormChange);
    form.addEventListener('change', onFormChange);
    form.addEventListener('submit', event => {
        event.preventDefault();
        save();
    });
    window.addEventListener('beforeunload', onBeforeUnload);
    document.addEventListener('click', onEditorLinkClick);

    if (updateBtn) updateBtn.addEventListener('click', () => save());
    if (saveDraftBtn) saveDraftBtn.addEventListener('click', () => save('draft'));
    if (publishBtn) publishBtn.addEventListener('click', () => save('published'));
    headerSaveBtn.addEventListener('click', () => save());

    if (lease) {
        heartbeatTimer = setInterval(heartbeat, lease.heartbeatSeconds * 1000);
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') heartbeat();
        });
        window.addEventListener('pagehide', () => {
            clearInterval(heartbeatTimer);
            const data = new FormData();
            data.set('_token', csrfToken);
            data.set('_editor_lock', lease.token);
            navigator.sendBeacon(lease.releaseUrl, data);
        });
        window.addEventListener('pageshow', event => {
            if (event.persisted) loseLock();
        });
    }

    updateDirtyState();
    refreshPreview();
}
