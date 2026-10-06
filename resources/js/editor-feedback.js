const t = key => window.adminI18n?.[key] || '';
const toName = path => { const [root, ...parts] = path.split('.'); return root + parts.map(p => `[${p}]`).join(''); };
const valueOf = field => field.type === 'checkbox' ? String(field.checked) : field.value;
export function revealEditorField(field) {
    const panel = field.closest('.form-panel');
    if (panel?.classList.contains('hidden')) document.querySelector(`.form-tab[data-tab="${panel.dataset.panel}"]`)?.click();
    const ancestors = [];
    for (let node = field.parentElement; node; node = node.parentElement) {
        if (node.matches('.template-section, [data-logo-item], [data-repeater-item]')) ancestors.unshift(node);
    }
    ancestors.forEach(node => {
        const body = node.querySelector('[data-accordion-body], [data-logo-accordion-body], [data-repeater-body]');
        const toggle = node.querySelector('[data-accordion-toggle], [data-logo-accordion-toggle], [data-repeater-toggle]');
        const icon = node.querySelector('[data-accordion-icon], [data-logo-accordion-icon], [data-repeater-icon]');
        body?.classList.remove('hidden'); toggle?.setAttribute('aria-expanded', 'true'); icon?.classList.add('rotate-180');
    });
    const visual = visualFor(field);
    visual.scrollIntoView({ block: 'center', behavior: 'smooth' });
    if (field.matches('[data-rich-text]')) field.dispatchEvent(new Event('editor:focus-field'));
    else visual.focus();
}
function visualFor(field) {
    if (field.dataset.visualId) return document.getElementById(field.dataset.visualId) || field;
    if (field.matches('[data-title-input]')) return field.closest('[data-title-editor]').querySelector('[data-title-visual]');
    if (field.matches('[data-rich-text]')) return field.parentElement.querySelector('.tox-tinymce') || field;
    return field;
}
export function initEditorFeedback(form) {
    if (!form) return null;
    const configNode = form.querySelector('[data-editor-stale-fields]');
    const names = configNode ? JSON.parse(configNode.textContent) : [];
    const states = new Map();
    const errors = new Map();
    const find = name => Array.from(form.querySelectorAll('[name]')).find(field => field.name === name && !field.disabled && !field.matches('[data-empty-repeater]'));
    const wrapperFor = field => field.closest('[data-field-wrapper], [data-title-editor], .media-selector') || field.parentElement;
    const appendHidden = (name, value) => {
        const field = document.createElement('input'); field.type = 'hidden'; field.name = name; field.value = value; form.append(field); return field;
    };
    const pending = (field, state) => field.isConnected && !field.disabled && !state.reviewed && valueOf(field) === state.initial;
    const badge = (target, type, count) => {
        if (!target) return;
        let node = Array.from(target.children).find(child => child.dataset.feedbackBadge === type);
        if (!count) { node?.remove(); return; }
        if (!node) { node = document.createElement('span'); node.dataset.feedbackBadge = type; node.className = `editor-badge editor-badge-${type}`; target.append(node); }
        node.textContent = count > 1 ? `! ${count}` : '!'; node.setAttribute('aria-label', `${type === 'warning' ? t('fieldOutdated') : t('validationErrors')}: ${count}`);
    };
    const update = () => {
        form.querySelectorAll('[data-feedback-origin]').forEach(node => node.remove());
        for (const [field, state] of states) {
            if (!field.isConnected && !state.reviewed) {
                state.reviewed = true; state.ack = appendHidden('acknowledged_fields[]', state.original);
            }
            if (field.isConnected) {
                const origin = appendHidden('editor_field_origins[]', JSON.stringify({ current: field.name, original: state.original })); origin.dataset.feedbackOrigin = '1';
            }
            const isPending = pending(field, state);
            visualFor(field)?.classList.toggle('editor-field-warning', isPending);
            state.hint.hidden = !isPending;
        }
        const containers = form.querySelectorAll('.template-section, [data-logo-item], [data-repeater-item], .form-panel');
        containers.forEach(container => {
            const target = container.matches('.form-panel') ? document.querySelector(`.form-tab[data-tab="${container.dataset.panel}"]`) : container.querySelector('[data-accordion-toggle], [data-logo-accordion-toggle], [data-repeater-toggle]');
            badge(target, 'warning', Array.from(states).filter(([field, state]) => container.contains(field) && pending(field, state)).length);
            badge(target, 'error', Array.from(errors).filter(([field]) => container.contains(field) && field.isConnected && !field.disabled).length);
        });
    };
    names.forEach(name => {
        const field = find(name); if (!field) return;
        const hint = document.createElement('div'); hint.dataset.staleHint = '1'; hint.className = 'editor-stale-hint';
        const text = document.createElement('span'); text.textContent = `⚠ ${t('fieldOutdated')} ${t('fieldOutdatedHint')}`;
        const action = document.createElement('button'); action.type = 'button'; action.textContent = t('markNoChanges'); action.className = 'rounded border border-amber-300/25 px-2 py-1 focus-visible:ring-2 focus-visible:ring-amber-300';
        const state = { initial: valueOf(field), original: name, reviewed: false, hint };
        states.set(field, state); hint.append(text, action); wrapperFor(field).append(hint);
        action.addEventListener('click', () => {
            state.reviewed = true; state.ack = appendHidden('acknowledged_fields[]', state.original); update();
            field.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });
    // Establish field identity at load; origin metadata is already in the clean snapshot.
    update();
    const clearError = field => {
        const error = errors.get(field); if (!error) return;
        error.node.remove(); errors.delete(field);
        const visual = visualFor(field); visual.classList.remove('editor-field-error'); visual.removeAttribute('aria-invalid');
        field.removeAttribute('aria-invalid');
        for (const node of new Set([field, visual])) {
            const ids = (node.getAttribute('aria-describedby') || '').split(' ').filter(id => id && id !== error.id);
            if (ids.length) node.setAttribute('aria-describedby', ids.join(' ')); else node.removeAttribute('aria-describedby');
        }
    };
    const refresh = event => {
        if (errors.has(event.target)) clearError(event.target);
        update();
        form.querySelector('#stale-fields-alert')?.classList.add('hidden');
    };
    form.addEventListener('input', refresh); form.addEventListener('change', refresh);
    form.addEventListener('editor:field-ready', event => {
        const state = states.get(event.detail.field);
        if (state && state.initial === event.detail.previousValue) state.initial = valueOf(event.detail.field);
        update();
    });
    const gate = event => {
        update(); const first = Array.from(states).find(([field, state]) => pending(field, state));
        if (!first) return;
        event.preventDefault(); form.querySelector('#stale-fields-alert')?.classList.remove('hidden'); revealEditorField(first[0]);
    };
    form.addEventListener('editor:before-save', gate); form.addEventListener('submit', gate);
    form.addEventListener('editor:saved', () => {
        for (const state of states.values()) { state.reviewed = true; state.hint.hidden = true; }
        Array.from(errors.keys()).forEach(clearError); update();
    });
    return {
        showErrors(payload) {
            Array.from(errors.keys()).forEach(clearError);
            let first;
            for (const [path, messages] of Object.entries(payload || {})) {
                const field = find(toName(path)); if (!field) continue;
                const node = document.createElement('p'); node.id = `editor-error-${crypto.randomUUID()}`; node.className = 'text-xs text-red-400 mt-1'; node.role = 'alert'; node.textContent = messages.join(' ');
                wrapperFor(field).append(node); errors.set(field, { node, id: node.id });
                const visual = visualFor(field); visual.classList.add('editor-field-error');
                for (const element of new Set([field, visual])) { element.setAttribute('aria-invalid', 'true'); element.setAttribute('aria-describedby', `${element.getAttribute('aria-describedby') || ''} ${node.id}`.trim()); }
                first ||= field;
            }
            update(); if (first) revealEditorField(first);
        },
    };
}
