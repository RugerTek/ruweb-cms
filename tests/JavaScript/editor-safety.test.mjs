import { test } from 'node:test';
import assert from 'node:assert/strict';
import { runInNewContext } from 'node:vm';
import { prepareEditorPreview } from '../../resources/js/editor-preview.js';
import { initEditorEngine } from '../../resources/js/editor-engine.js';

test('preview guard blocks every link activation and script navigation while preserving refresh and widgets', () => {
    const html = prepareEditorPreview('<html><head><script>publicScripts()</script></head><body></body></html>');
    assert.ok(html.indexOf('MutationObserver') < html.indexOf('publicScripts'));
    const script = html.match(/<script>(.*?)<\/script>/s)[1];
    const attributes = new Map([['href', '/contact'], ['target', '_blank'], ['onclick', 'navigate()'], ['tabindex', '0']]);
    const link = {
        style: {},
        removeAttribute: name => attributes.delete(name),
        setAttribute: (name, value) => attributes.set(name, value),
        closest: () => link,
    };
    const previewWindow = new EventTarget();
    previewWindow.navigation = new EventTarget();
    let mutation;
    runInNewContext(script, {
        window: previewWindow,
        document: { querySelectorAll: () => [link] },
        MutationObserver: class {
            constructor(callback) { mutation = callback; }
            observe() {}
        },
    });
    assert.equal(attributes.has('href'), false);
    assert.equal(attributes.has('target'), false);
    assert.equal(attributes.get('aria-disabled'), 'true');
    attributes.set('href', '/dynamic');
    mutation();
    assert.equal(attributes.has('href'), false);
    for (const [type, key] of [['click'], ['auxclick'], ['keydown', 'Enter'], ['keydown', ' ']]) {
        const event = new Event(type, { cancelable: true });
        Object.defineProperty(event, 'target', { value: link });
        event.key = key;
        previewWindow.dispatchEvent(event);
        assert.equal(event.defaultPrevented, true, `${type} ${key || ''}`);
    }
    const widgetClick = new Event('click', { cancelable: true });
    Object.defineProperty(widgetClick, 'target', { value: { closest: () => null } });
    previewWindow.dispatchEvent(widgetClick);
    assert.equal(widgetClick.defaultPrevented, false);
    for (const [url, blocked] of [['about:srcdoc', false], ['/contact', true], ['about:srcdoc#faq', true], ['https://example.com', true]]) {
        const event = new Event('navigate', { cancelable: true });
        event.destination = { url };
        previewWindow.navigation.dispatchEvent(event);
        assert.equal(event.defaultPrevented, blocked, url);
    }
    const submit = new Event('submit', { cancelable: true });
    previewWindow.dispatchEvent(submit);
    assert.equal(submit.defaultPrevented, true);
    assert.equal(previewWindow.open('/admin/pages/1/edit'), null);
    assert.ok(prepareEditorPreview('<p>Fragment</p>').startsWith('<script>'));
});

function editor(t, leaseEnabled = true, frame = null, fetchResponse = async () => ({ ok: true, json: async () => ({ expiresAt: Date.now() + 120000 }) }), status = 'published') {
    t.mock.timers.enable({ apis: ['Date', 'setInterval', 'setTimeout'], now: 1000000 });
    const form = new EventTarget();
    form.action = '/save';
    form.values = new Map([['title', 'Original'], ['status', status], ['_editor_lock', 'token']]);
    form.querySelector = () => ({
        get value() { return form.values.get('status'); },
        set value(value) { form.values.set('status', value); },
    });
    const save = new EventTarget();
    const publish = new EventTarget();
    const notice = { hidden: true };
    const document = new EventTarget();
    document.getElementById = id => ({ 'editor-form': form, 'save-btn': save, 'publish-btn': publish, 'editor-lock-notice': notice, 'preview-frame': frame })[id];
    document.querySelector = () => null;
    const window = new EventTarget();
    window.location = { reload() {}, href: 'http://localhost/admin/pages/1/edit' };
    const lease = {
        token: 'token', heartbeatUrl: '/heartbeat', releaseUrl: '/release',
        heartbeatSeconds: 30, ttlSeconds: 120, lostMessage: 'Lock lost',
    };
    t.mock.method(globalThis, 'fetch', fetchResponse);
    const OldFormData = globalThis.FormData;
    const OldDocument = globalThis.document;
    const OldWindow = globalThis.window;
    globalThis.FormData = class {
        constructor(form) { this.data = new Map(form?.values || []); }
        delete(key) { this.data.delete(key); }
        entries() { return this.data.entries(); }
        get(key) { return this.data.get(key); }
        set(key, value) { this.data.set(key, value); }
    };
    globalThis.document = document;
    globalThis.window = window;
    globalThis.showToast = () => {};
    t.after(() => {
        globalThis.FormData = OldFormData;
        globalThis.document = OldDocument;
        globalThis.window = OldWindow;
        delete globalThis.showToast;
    });
    initEditorEngine({ lease: leaseEnabled ? lease : null, previewUrl: frame ? '/preview' : null });
    return { form, save, publish, notice, window, document, lease, change() {
        form.values.set('title', 'Pending changes');
        form.dispatchEvent(new Event('input'));
    } };
}

const flush = () => new Promise(resolve => setImmediate(resolve));

test('lease loss disables all saves and keeps unsaved content and leave protection', async t => {
    const e = editor(t);
    e.change();
    assert.equal(e.save.disabled, false);
    globalThis.fetch = async () => ({ ok: false, status: 423 });
    t.mock.timers.tick(30000);
    await flush();
    assert.equal(e.notice.hidden, false);
    assert.equal(e.save.disabled, true);
    assert.equal(e.publish.disabled, true);
    assert.equal(e.form.values.get('title'), 'Pending changes');
    const leave = new Event('beforeunload', { cancelable: true });
    e.window.dispatchEvent(leave);
    assert.equal(leave.defaultPrevented, true);
    let saves = 0;
    globalThis.fetch = async () => { saves++; };
    e.save.dispatchEvent(new Event('click'));
    e.publish.dispatchEvent(new Event('click'));
    e.form.dispatchEvent(new Event('submit', { cancelable: true }));
    await flush();
    assert.equal(saves, 0);
});

test('a short connection failure is tolerated, but expiry disables saving', async t => {
    const e = editor(t);
    e.change();
    globalThis.fetch = async () => { throw new Error('Offline'); };
    t.mock.timers.tick(30000);
    await flush();
    assert.equal(e.notice.hidden, true);
    assert.equal(e.save.disabled, false);
    t.mock.timers.tick(90000);
    await flush();
    assert.equal(e.notice.hidden, false);
    assert.equal(e.save.disabled, true);
});

test('renewal keeps the editor available beyond the original expiry', async t => {
    const e = editor(t);
    e.change();
    for (let i = 0; i < 5; i++) {
        t.mock.timers.tick(30000);
        await flush();
    }
    assert.equal(e.notice.hidden, true);
    assert.equal(e.save.disabled, false);
});

test('pagehide releases the token and a cached editor cannot resume saving', t => {
    const e = editor(t);
    navigator.sendBeacon = () => true;
    t.after(() => { delete navigator.sendBeacon; });
    const beacon = t.mock.method(navigator, 'sendBeacon', () => true);
    e.window.dispatchEvent(new Event('pagehide'));
    assert.equal(beacon.mock.callCount(), 1);
    assert.equal(beacon.mock.calls[0].arguments[0], '/release');
    assert.equal(beacon.mock.calls[0].arguments[1].get('_editor_lock'), 'token');
    const show = new Event('pageshow');
    show.persisted = true;
    e.window.dispatchEvent(show);
    e.change();
    assert.equal(e.save.disabled, true);
    assert.equal(e.notice.hidden, false);
});

test('server rejection during save preserves pending changes and disables retry', async t => {
    const e = editor(t);
    e.change();
    globalThis.fetch = async () => ({ ok: false, status: 423, headers: new Headers({ 'content-type': 'application/json' }), json: async () => ({ message: 'Lost' }) });
    e.save.dispatchEvent(new Event('click'));
    await flush();
    assert.equal(e.notice.hidden, false);
    assert.equal(e.save.disabled, true);
    assert.equal(e.form.values.get('title'), 'Pending changes');
});

test('out-of-order preview responses cannot replace the latest preview', async t => {
    const frame = new EventTarget();
    frame.contentWindow = { scrollY: 20 };
    const requests = [];
    const e = editor(t, false, frame, () => new Promise(resolve => requests.push(resolve)));
    // The first request is created during initialization.
    e.change();
    t.mock.timers.tick(600);
    assert.equal(requests.length, 2);
    requests[1]({ ok: true, text: async () => '<head></head><p>Latest</p>' });
    await flush();
    requests[0]({ ok: true, text: async () => '<head></head><p>Stale</p>' });
    await flush();
    assert.match(frame.srcdoc, /Latest/);
    assert.doesNotMatch(frame.srcdoc, /Stale/);
});

test('publishing preserves newer edits and the next save keeps the published status', async t => {
    const draft = editor(t, false, null, undefined, 'draft');
    let finish;
    let submitted;
    globalThis.fetch = async (_url, options) => {
        submitted = options.body;
        return new Promise(resolve => { finish = resolve; });
    };
    draft.publish.dispatchEvent(new Event('click'));
    assert.equal(submitted.get('status'), 'published');
    draft.change();
    draft.save.dispatchEvent(new Event('click'));
    finish({ ok: true });
    await flush();
    assert.equal(draft.form.values.get('status'), 'published');
    assert.equal(draft.save.disabled, false);
    globalThis.fetch = async (_url, options) => {
        submitted = options.body;
        return { ok: true };
    };
    draft.save.dispatchEvent(new Event('click'));
    await flush();
    assert.equal(submitted.get('status'), 'published');
    assert.equal(submitted.get('title'), 'Pending changes');
    assert.equal(draft.save.disabled, true);
});
