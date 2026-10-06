const t = key => window.adminI18n?.[key] || '';

export function initTitleEditors(root = document) {
    root.querySelectorAll('[data-title-editor]').forEach(wrapper => {
        if (wrapper.dataset.ready) return;
        wrapper.dataset.ready = '1';
        const field = wrapper.querySelector('[data-title-input]');
        const visual = wrapper.querySelector('[data-title-visual]');
        const action = wrapper.querySelector('[data-title-highlight]');
        const render = () => {
            visual.replaceChildren();
            const regex = /\[highlight\]([\s\S]*?)\[\/highlight\]/g;
            let offset = 0;
            for (const match of field.value.matchAll(regex)) {
                visual.append(document.createTextNode(field.value.slice(offset, match.index)));
                const mark = document.createElement('mark');
                mark.textContent = match[1];
                visual.append(mark);
                offset = match.index + match[0].length;
            }
            visual.append(document.createTextNode(field.value.slice(offset)));
        };
        const serialize = node => {
            if (node.nodeType === Node.TEXT_NODE) return node.textContent;
            const text = Array.from(node.childNodes).map(serialize).join('');
            return node.nodeName === 'MARK' ? `[highlight]${text}[/highlight]` : text;
        };
        const sync = () => {
            visual.querySelectorAll('mark mark').forEach(mark => mark.replaceWith(...mark.childNodes));
            field.value = serialize(visual).replace(/\n/g, ' ');
            field.dispatchEvent(new Event('input', { bubbles: true }));
        };
        render();
        field.addEventListener('editor:render-title', render);
        visual.addEventListener('input', sync);
        visual.addEventListener('beforeinput', event => {
            if (event.inputType?.startsWith('format')) event.preventDefault();
        });
        visual.addEventListener('keydown', event => { if (event.key === 'Enter') event.preventDefault(); });
        visual.addEventListener('paste', event => {
            event.preventDefault();
            const selection = window.getSelection();
            if (!selection.rangeCount) return;
            const range = selection.getRangeAt(0);
            range.deleteContents();
            const text = document.createTextNode(event.clipboardData.getData('text/plain').replace(/\s*\n\s*/g, ' '));
            range.insertNode(text);
            range.setStartAfter(text); range.collapse(true);
            selection.removeAllRanges(); selection.addRange(range);
            sync();
        });
        let selectedRange;
        document.addEventListener('selectionchange', () => {
            const selection = window.getSelection();
            if (!selection.rangeCount || !visual.contains(selection.anchorNode) || !visual.contains(selection.focusNode)) return;
            selectedRange = selection.getRangeAt(0).cloneRange();
            const mark = selection.anchorNode?.parentElement?.closest('mark');
            action.setAttribute('aria-pressed', String(!!mark));
        });
        action.addEventListener('mousedown', event => event.preventDefault());
        action.addEventListener('click', () => {
            if (!selectedRange || selectedRange.collapsed || !visual.contains(selectedRange.commonAncestorContainer)) {
                visual.focus(); return;
            }
            const parent = selectedRange.startContainer.parentElement?.closest('mark');
            const endParent = selectedRange.endContainer.parentElement?.closest('mark');
            if (parent && parent === endParent) {
                parent.replaceWith(...parent.childNodes);
            } else {
                const mark = document.createElement('mark');
                // A text-only fragment avoids carrying arbitrary pasted formatting.
                mark.textContent = selectedRange.toString();
                selectedRange.deleteContents(); selectedRange.insertNode(mark);
            }
            visual.focus();
            sync();
            selectedRange = null;
        });
    });
}

export function initLinkComboboxes(root = document) {
    root.querySelectorAll('[data-link-destination]').forEach(control => {
        if (control.dataset.ready) return;
        control.dataset.ready = '1';
        const select = control.querySelector('[data-link-destination-page]');
        const custom = control.querySelector('[data-link-destination-custom]');
        if (!select || !custom) return;
        const uid = select.id;
        const wrapper = document.createElement('div'); wrapper.className = 'relative';
        const input = document.createElement('input');
        input.id = `${uid}-search`; input.type = 'text'; input.className = 'link-combobox-input';
        input.setAttribute('role', 'combobox'); input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false'); input.setAttribute('aria-controls', `${uid}-list`);
        input.setAttribute('aria-haspopup', 'listbox'); input.autocomplete = 'off';
        input.placeholder = t('linkSearch');
        const toggle = document.createElement('button'); toggle.type = 'button'; toggle.className = 'link-combobox-toggle';
        toggle.textContent = '⌄'; toggle.setAttribute('aria-label', t('linkShowOptions')); toggle.setAttribute('aria-controls', `${uid}-list`);
        const list = document.createElement('div'); list.id = `${uid}-list`; list.role = 'listbox'; list.className = 'link-combobox-list'; list.hidden = true;
        list.setAttribute('aria-label', control.querySelector('label')?.textContent || t('linkSearch'));
        const status = document.createElement('p'); status.className = 'text-xs text-gray-400 mt-1'; status.role = 'status';
        select.before(wrapper); wrapper.append(input, toggle, list); wrapper.after(status);
        select.hidden = true; select.tabIndex = -1;
        control.querySelector(`label[for="${uid}"]`)?.setAttribute('for', input.id);
        select.dataset.visualId = input.id;
        const options = Array.from(select.options);
        let matches = [], active = -1;
        const norm = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
        const close = () => {
            list.hidden = true; input.setAttribute('aria-expanded', 'false'); toggle.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            input.value = select.selectedOptions[0]?.textContent || '';
            status.textContent = '';
        };
        const sync = () => {
            const isCustom = select.value === 'custom'; custom.classList.toggle('hidden', !isCustom);
            custom.querySelectorAll('input').forEach(field => field.disabled = select.disabled || !isCustom);
            close();
        };
        const activate = index => {
            active = index;
            Array.from(list.children).forEach((node, i) => node.classList.toggle('active', i === index));
            const node = list.children[index];
            if (node) { input.setAttribute('aria-activedescendant', node.id); node.scrollIntoView({ block: 'nearest' }); }
        };
        const choose = option => {
            select.value = option.value; sync();
            select.dispatchEvent(new Event('change', { bubbles: true }));
            if (select.value === 'custom') custom.querySelector('input')?.focus(); else input.focus();
        };
        const open = query => {
            const q = norm(query);
            matches = options.filter(option => option.value !== 'custom' && norm(option.textContent).includes(q));
            status.textContent = matches.length ? '' : t('linkNoResults');
            matches.push(...options.filter(option => option.value === 'custom'));
            list.replaceChildren();
            matches.forEach((option, index) => {
                const node = document.createElement('div'); node.id = `${uid}-option-${index}`; node.role = 'option';
                node.className = 'link-combobox-option'; node.textContent = option.textContent;
                node.setAttribute('aria-selected', String(option.selected));
                node.addEventListener('mousedown', event => event.preventDefault());
                node.addEventListener('click', () => choose(option)); list.append(node);
            });
            list.hidden = false; input.setAttribute('aria-expanded', 'true'); toggle.setAttribute('aria-expanded', 'true');
            activate(q ? 0 : Math.max(0, matches.findIndex(option => option.selected)));
        };
        input.addEventListener('input', event => { event.stopPropagation(); open(input.value); });
        input.addEventListener('click', () => { if (list.hidden) open(''); });
        input.addEventListener('keydown', event => {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault(); if (list.hidden) { open(''); return; }
                activate((active + (event.key === 'ArrowDown' ? 1 : -1) + matches.length) % matches.length);
            } else if (event.key === 'Enter' && !list.hidden) {
                event.preventDefault(); if (matches[active]) choose(matches[active]);
            } else if (event.key === 'Escape') { event.preventDefault(); close(); }
            else if (event.key === 'Tab') close();
        });
        input.addEventListener('blur', event => { if (event.relatedTarget !== toggle) close(); });
        toggle.addEventListener('click', () => { input.focus(); if (list.hidden) open(''); else close(); });
        select.addEventListener('change', sync);
        document.getElementById('template_key')?.addEventListener('change', sync);
        sync();
    });
}

export function initEditorResize() {
    const handle = document.getElementById('editor-divider');
    const workspace = document.getElementById('editor-workspace');
    const panel = document.getElementById('panel-edit');
    if (!handle || !workspace || !panel) return;
    let width = 384;
    const max = () => Math.max(384, Math.floor((workspace.clientWidth - handle.offsetWidth) / 2));
    const update = next => {
        width = Math.max(384, Math.min(next, max()));
        panel.style.setProperty('--editor-panel-width', `${width}px`);
        handle.setAttribute('aria-valuenow', String(Math.round(width)));
        handle.setAttribute('aria-valuemax', String(max()));
    };
    handle.addEventListener('pointerdown', event => {
        if (!window.matchMedia('(min-width: 1024px)').matches) return;
        event.preventDefault(); handle.setPointerCapture(event.pointerId);
        const start = event.clientX, initial = width;
        const move = e => update(initial + e.clientX - start);
        const end = () => { handle.removeEventListener('pointermove', move); };
        handle.addEventListener('pointermove', move);
        handle.addEventListener('pointerup', end, { once: true });
        handle.addEventListener('pointercancel', end, { once: true });
    });
    handle.addEventListener('keydown', event => {
        const next = { ArrowLeft: width - 16, ArrowRight: width + 16, Home: 384, End: max() }[event.key];
        if (next !== undefined) { event.preventDefault(); update(next); }
    });
    window.addEventListener('resize', () => update(width));
    update(width);
}
