import { test } from 'node:test';
import assert from 'node:assert/strict';
import { initMediaMetadata } from '../../resources/js/media-metadata.js';

function setup(t) {
    const control = (extra = {}) => ({
        value: '', disabled: false, textContent: '', listeners: {},
        classList: { add() {}, remove() {} },
        addEventListener(type, listener) { this.listeners[type] = listener; },
        ...extra,
    });
    const nodes = Object.fromEntries(['panel', 'locale', 'alt', 'title', 'save', 'metadata-status', 'metadata-error'].map(id => [`detail-${id}`, control()]));
    nodes['detail-panel'].dataset = { baseLocale: 'es' };
    nodes['detail-locale'].options = [{ value: 'es' }, { value: 'en' }];
    const window = control({ adminI18n: { mediaMetadataSaved: 'Saved', mediaMetadataError: 'Failed' }, confirmAction: async () => false });
    t.mock.method(globalThis, 'fetch', async () => ({ ok: true, json: async () => ({ alt: 'Base', title: 'Base title', metadata: { es: { alt: 'Base', title: 'Base title' } } }) }));
    for (const [name, value] of Object.entries({ window, document: { getElementById: id => nodes[id], querySelector: () => null } })) {
        const previous = Object.getOwnPropertyDescriptor(globalThis, name);
        Object.defineProperty(globalThis, name, { configurable: true, value });
        t.after(() => previous ? Object.defineProperty(globalThis, name, previous) : delete globalThis[name]);
    }
    initMediaMetadata();
    const item = { dataset: { id: '5', metadata: JSON.stringify({ es: { alt: 'Base', title: 'Base title' } }) }, querySelector: () => null };
    const switchLocale = code => { nodes['detail-locale'].value = code; nodes['detail-locale'].listeners.change(); };
    const editAlt = text => { nodes['detail-alt'].value = text; nodes['detail-alt'].listeners.input(); };
    return { nodes, window, item, switchLocale, editAlt };
}

test('language switching preserves drafts and sends only changed languages together', async t => {
    const { nodes, window, item, switchLocale, editAlt } = setup(t);
    let submitted;
    t.mock.method(globalThis, 'fetch', async (_, request) => {
        submitted = JSON.parse(request.body);
        return { ok: true, json: async () => ({ alt: 'Base changed', title: 'Base title', metadata: submitted.metadata }) };
    });
    await window.mediaMetadata.open(item);
    editAlt('Base changed');
    switchLocale('en');
    assert.equal(nodes['detail-alt'].value, '');
    assert.equal(nodes['detail-alt'].placeholder, 'Base changed');
    editAlt('English');
    switchLocale('es');
    assert.equal(nodes['detail-alt'].value, 'Base changed');
    switchLocale('en');
    assert.equal(nodes['detail-alt'].value, 'English');
    await nodes['detail-save'].listeners.click();
    assert.deepEqual(submitted.metadata, { es: { alt: 'Base changed', title: 'Base title' }, en: { alt: 'English', title: '' } });
    assert.equal(nodes['detail-save'].disabled, true);
    assert.equal(nodes['detail-metadata-status'].textContent, 'Saved');
});

test('failed saves retain all language drafts and display validation errors', async t => {
    const { nodes, window, item, switchLocale, editAlt } = setup(t);
    t.mock.method(globalThis, 'fetch', async () => ({ ok: false, json: async () => ({ errors: { 'metadata.en.alt': ['Too long'] } }) }));
    await window.mediaMetadata.open(item);
    editAlt('Base changed'); switchLocale('en'); editAlt('English');
    await nodes['detail-save'].listeners.click();
    assert.equal(nodes['detail-metadata-error'].textContent, 'Too long');
    assert.equal(nodes['detail-alt'].value, 'English');
    assert.equal(nodes['detail-save'].disabled, false);
    switchLocale('es');
    assert.equal(nodes['detail-alt'].value, 'Base changed');
    assert.equal(nodes['detail-locale'].disabled, false);
});

test('cancelling unsaved confirmation keeps the current asset and navigation is guarded', async t => {
    const { nodes, window, item, editAlt } = setup(t);
    await window.mediaMetadata.open(item); editAlt('Unsaved');
    assert.equal(await window.mediaMetadata.open({ ...item, dataset: { ...item.dataset, id: '6' } }), false);
    assert.equal(nodes['detail-alt'].value, 'Unsaved');
    const event = { preventDefault() { this.prevented = true; } };
    window.listeners.beforeunload(event);
    assert.equal(event.prevented, true);
    window.mediaMetadata.reset();
    assert.equal(await window.mediaMetadata.canLeave(), true);
});
