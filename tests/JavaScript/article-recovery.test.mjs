import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { SourceTextModule, createContext } from 'node:vm';
import test from 'node:test';

const source = await readFile(new URL('../../assets/controllers/editor/recovery_controller.js', import.meta.url), 'utf8');

async function fixture() {
    const values = new Map();
    const storage = {
        get length() { return values.size; },
        key: (i) => [...values.keys()][i] ?? null,
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
        removeItem: (key) => values.delete(key),
    };
    const layout = {
        state: { active_source: 'md', content_NMD: 'stale', content_delta: { ops: [] } },
        safeNmdToDelta: (markdown) => ({ ops: [{ insert: markdown }] }),
        updateMarkdownEditor() {},
        updateQuillEditor() {},
        syncTitleToMarkdown() {},
        updatePreview() {},
    };
    const fields = [
        { name: 'editor[title]', value: 'My article', type: 'text' },
        { name: 'editor[content]', value: 'stale', type: 'textarea' },
        { name: 'editor[isDraft]', value: '1', checked: false, type: 'checkbox' },
        { name: 'editor[_token]', value: 'secret', type: 'hidden' },
        { name: 'editor[loginMethod]', value: 'secret', type: 'hidden' },
    ];
    fields.namedItem = (name) => fields.find((field) => field.name === name);
    const markdown = { value: 'stale', _codemirror: { state: { doc: { toString: () => 'latest markdown' } } } };
    const form = { elements: fields, querySelector: () => markdown };
    const element = { querySelector: () => null };
    const labels = {
        browser_failed: 'browser failed', server_failed: 'server failed', saved_browser: 'browser saved',
        saved_both: 'both saved', saved_local_only: 'local only', confirm_restore: 'restore?',
    };
    const requests = [];
    let fetchImpl = async () => ({ ok: true });
    const context = createContext({
        console, structuredClone, setTimeout, clearTimeout, localStorage: storage,
        crypto: { randomUUID: () => 'new-id' },
        window: { confirm: () => true },
        fetch: (...args) => { requests.push(args); return fetchImpl(...args); },
    });
    const module = new SourceTextModule(source, { context });
    await module.link(async () => {
        const dependency = new SourceTextModule('export class Controller {}', { context });
        await dependency.link(() => {});
        return dependency;
    });
    await module.evaluate();
    const controller = new module.namespace.default();
    Object.assign(controller, {
        element, form, ownerValue: 'alice', urlValue: '/recovery', tokenValue: 'csrf',
        labelsValue: labels, statusTarget: { textContent: '' }, id: 'first-id',
        remote: new Map(), pending: new Map(), sending: false, restoring: false,
        application: { getControllerForElementAndIdentifier: () => layout },
        renderList() {},
    });
    return { controller, layout, fields, storage, values, requests, setFetch: (fn) => { fetchImpl = fn; } };
}

test('captures current Markdown and form values without identity or CSRF fields', async () => {
    const { controller } = await fixture();
    const snapshot = controller.capture();
    assert.equal(snapshot.editorState.content_NMD, 'latest markdown');
    assert.equal(snapshot.fields['editor[title]'].value, 'My article');
    assert.equal(snapshot.fields['editor[isDraft]'].checked, false);
    assert.equal(snapshot.fields['editor[_token]'], undefined);
    assert.equal(snapshot.fields['editor[loginMethod]'], undefined);
});

test('keeps browser copies scoped by account', async () => {
    const { controller, values } = await fixture();
    const snapshot = controller.capture();
    values.set('article-recovery:v1:alice:one', JSON.stringify(snapshot));
    values.set('article-recovery:v1:bob:two', JSON.stringify(snapshot));
    assert.deepEqual([...controller.browserCopies().keys()], ['one']);
    controller.ownerValue = 'bob';
    assert.deepEqual([...controller.browserCopies().keys()], ['two']);
});

test('retains the browser copy and reports failed server writes', async () => {
    const { controller, values, setFetch } = await fixture();
    setFetch(async () => ({ ok: false, status: 503 }));
    controller.saveSnapshot();
    clearTimeout(controller.serverTimer);
    await controller.flushServer();
    assert.ok(values.has('article-recovery:v1:alice:first-id'));
    assert.equal(controller.statusTarget.textContent, 'server failed');
});

test('serializes server writes and preserves pending copies for different editor sessions', async () => {
    const { controller, requests, setFetch } = await fixture();
    let release;
    setFetch(() => new Promise((resolve) => { release = resolve; }));
    controller.saveSnapshot();
    clearTimeout(controller.serverTimer);
    const first = controller.flushServer();
    controller.id = 'second-id';
    controller.saveSnapshot();
    clearTimeout(controller.serverTimer);
    assert.equal(requests.length, 1);
    release({ ok: true });
    await first;
    assert.equal(requests.length, 2);
    assert.match(requests[0][0], /first-id$/);
    assert.match(requests[1][0], /second-id$/);
    release({ ok: true });
});

test('does not replace current work if the browser backup fails', async () => {
    const { controller, layout, storage } = await fixture();
    const snapshot = controller.capture();
    storage.setItem = () => { throw new Error('quota'); };
    controller.restore(snapshot);
    clearTimeout(controller.serverTimer);
    assert.equal(layout.state.content_NMD, 'stale');
    assert.equal(controller.statusTarget.textContent, 'browser failed');
    assert.equal(controller.id, 'first-id');
});

test('restoring forks a copy and keeps the original browser copy', async () => {
    const { controller, layout, values } = await fixture();
    const snapshot = controller.capture();
    values.set('article-recovery:v1:alice:original', JSON.stringify(snapshot));
    controller.restore(snapshot);
    clearTimeout(controller.serverTimer);
    assert.ok(values.has('article-recovery:v1:alice:original'));
    assert.ok(values.has('article-recovery:v1:alice:first-id'));
    assert.ok(values.has('article-recovery:v1:alice:new-id'));
    assert.equal(layout.state.content_NMD, 'latest markdown');
});

test('imported snapshots cannot overwrite login identity or CSRF fields', async () => {
    const { controller, fields } = await fixture();
    const snapshot = controller.capture();
    snapshot.fields['editor[_token]'] = { value: 'attacker-token' };
    snapshot.fields['editor[loginMethod]'] = { value: 'attacker-login' };
    controller.restore(snapshot);
    clearTimeout(controller.serverTimer);
    assert.equal(fields.namedItem('editor[_token]').value, 'secret');
    assert.equal(fields.namedItem('editor[loginMethod]').value, 'secret');
});

test('anonymous recovery reports browser-only storage', async () => {
    const { controller } = await fixture();
    controller.urlValue = '';
    controller.saveSnapshot();
    assert.equal(controller.statusTarget.textContent, 'local only');
    assert.equal(controller.pending.size, 0);
});

test('restores edited event JSON and retains its override flag', async () => {
    const { controller, layout } = await fixture();
    const jsonController = {
        jsonEdited: false,
        cmView: { state: { doc: { length: 3 } }, dispatch: (change) => { jsonController.change = change; } },
        getCurrentJson: () => '',
    };
    const json = { value: '', closest: () => ({}) };
    controller.element.querySelector = (selector) => selector === '#json-editor-textarea' ? json : null;
    controller.application.getControllerForElementAndIdentifier = (_element, identifier) =>
        identifier === 'editor--layout' ? layout : jsonController;
    const snapshot = controller.capture();
    snapshot.json = '{"content":"custom"}';
    snapshot.jsonEdited = true;
    controller.restore(snapshot);
    clearTimeout(controller.serverTimer);
    assert.equal(json.value, snapshot.json);
    assert.equal(jsonController.change.changes.insert, snapshot.json);
    assert.equal(jsonController.jsonEdited, true);
});

test('recreates sparse advanced metadata collection entries', async () => {
    const { controller } = await fixture();
    const names = ['zapSplits', 'sources', 'mediaAttachments'];
    const calls = [];
    const metadata = {};
    for (const name of names) {
        metadata[`${name}ContainerTarget`] = { dataset: {}, replaceChildren() {} };
    }
    metadata.addZapSplit = () => calls.push(['zap', metadata.zapSplitsContainerTarget.dataset.index]);
    metadata.addSource = () => calls.push(['source', metadata.sourcesContainerTarget.dataset.index]);
    metadata.addMediaAttachment = () => calls.push(['media', metadata.mediaAttachmentsContainerTarget.dataset.index]);
    controller.element.querySelector = () => ({});
    controller.application.getControllerForElementAndIdentifier = () => metadata;
    controller.restoreCollections({
        'editor[advancedMetadata][zapSplits][2][recipient]': { value: 'recipient' },
        'editor[advancedMetadata][sources][4]': { value: 'https://example.test' },
        'editor[advancedMetadata][mediaAttachments][1][url]': { value: 'https://example.test/image' },
    });
    assert.deepEqual(calls, [['zap', '2'], ['source', '4'], ['media', '1']]);
    assert.equal(metadata.sourcesContainerTarget.dataset.index, '5');
});
