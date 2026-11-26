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
        saved_both: 'both saved', saved_local_only: 'local only', confirm_restore: 'restore?', empty: 'empty',
    };
    const requests = [];
    let fetchImpl = async () => ({ ok: true });
    const document = {
        createElement(tagName) {
            return {
                tagName, children: [],
                append(...children) { this.children.push(...children); },
                addEventListener() {},
            };
        },
    };
    const context = createContext({
        console, structuredClone, setTimeout, clearTimeout, localStorage: storage,
        crypto: { randomUUID: () => 'new-id' },
        window: { confirm: () => true },
        document,
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
    return { controller, layout, fields, markdown, storage, values, requests, setFetch: (fn) => { fetchImpl = fn; } };
}

test('does not save or queue an empty or whitespace-only article', async () => {
    const { controller, fields, markdown, values, requests } = await fixture();
    fields.namedItem('editor[title]').value = ' \n ';
    markdown._codemirror.state.doc.toString = () => '\n  ';
    assert.equal(controller.saveSnapshot(), 'empty');
    controller.saveNow();
    assert.equal(controller.statusTarget.textContent, 'empty');
    assert.equal(values.size, 0);
    assert.equal(controller.pending.size, 0);
    await controller.flushServer();
    assert.equal(requests.length, 0);
});

test('saves an article with only a title or Markdown content', async () => {
    const { controller, fields, markdown, values } = await fixture();
    markdown._codemirror.state.doc.toString = () => '';
    assert.equal(controller.saveSnapshot(), true);
    clearTimeout(controller.serverTimer);
    fields.namedItem('editor[title]').value = '';
    markdown._codemirror.state.doc.toString = () => 'body';
    assert.equal(controller.saveSnapshot(), true);
    clearTimeout(controller.serverTimer);
    assert.equal(JSON.parse(values.get('article-recovery:v1:alice:first-id')).editorState.content_NMD, 'body');
});

test('checks current rich text rather than stale Markdown and preserves embedded content', async () => {
    const { controller, fields, layout, values } = await fixture();
    fields.namedItem('editor[title]').value = '';
    layout.state.active_source = 'quill';
    layout.state.content_NMD = 'old content';
    layout.state.content_delta = { ops: [{ insert: '\n' }] };
    assert.equal(controller.saveSnapshot(), 'empty');
    layout.state.content_delta = { ops: [{ insert: { image: 'https://example.test/image' } }, { insert: '\n' }] };
    assert.equal(controller.saveSnapshot(), true);
    clearTimeout(controller.serverTimer);
    assert.equal(values.size, 1);
});

test('clearing an article does not overwrite its saved or queued copy', async () => {
    const { controller, fields, markdown, values } = await fixture();
    controller.saveSnapshot();
    clearTimeout(controller.serverTimer);
    const saved = values.get('article-recovery:v1:alice:first-id');
    const pending = controller.pending.get('first-id');
    fields.namedItem('editor[title]').value = '';
    markdown._codemirror.state.doc.toString = () => '';
    assert.equal(controller.saveSnapshot(), 'empty');
    assert.equal(values.get('article-recovery:v1:alice:first-id'), saved);
    assert.equal(controller.pending.get('first-id'), pending);
});

test('restoring into an empty editor skips its backup but retains the restored copy', async () => {
    const { controller, fields, markdown, values } = await fixture();
    const snapshot = controller.capture();
    fields.namedItem('editor[title]').value = '';
    markdown._codemirror.state.doc.toString = () => '';
    controller.restore(snapshot);
    clearTimeout(controller.serverTimer);
    assert.equal(values.has('article-recovery:v1:alice:first-id'), false);
    assert.equal(values.has('article-recovery:v1:alice:new-id'), true);
});

test('restoring preserves metadata-only work even without a title or content', async () => {
    const { controller, fields, markdown, values } = await fixture();
    const snapshot = controller.capture();
    fields.namedItem('editor[title]').value = '';
    fields.push({ name: 'editor[summary]', value: 'Unsaved summary', type: 'textarea' });
    markdown._codemirror.state.doc.toString = () => '';
    assert.equal(controller.saveSnapshot(), 'empty');
    controller.restore(snapshot);
    clearTimeout(controller.serverTimer);
    assert.equal(JSON.parse(values.get('article-recovery:v1:alice:first-id')).fields['editor[summary]'].value, 'Unsaved summary');
});

test('preserves manually edited event JSON with an empty form', async () => {
    const { controller, fields, markdown, layout, values } = await fixture();
    fields.namedItem('editor[title]').value = '';
    markdown._codemirror.state.doc.toString = () => '';
    const json = { closest: () => ({}) };
    controller.element.querySelector = (selector) => selector === '#json-editor-textarea' ? json : null;
    controller.application.getControllerForElementAndIdentifier = (_element, identifier) =>
        identifier === 'editor--layout' ? layout : { jsonEdited: true, getCurrentJson: () => '{"content":"work"}' };
    assert.equal(controller.saveSnapshot(), true);
    clearTimeout(controller.serverTimer);
    assert.equal(JSON.parse(values.get('article-recovery:v1:alice:first-id')).json, '{"content":"work"}');
});

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

test('renders recovery entries with translated and styled actions', async () => {
    const { controller, values } = await fixture();
    controller.labelsValue.browser = 'browser';
    controller.labelsValue.restore = 'Restore';
    controller.labelsValue.delete = 'Delete';
    controller.listTarget = {
        children: [],
        replaceChildren() { this.children = []; },
        append(item) { this.children.push(item); },
    };
    delete controller.renderList;
    values.set('article-recovery:v1:alice:one', JSON.stringify(controller.capture()));
    controller.renderList();
    const item = controller.listTarget.children[0];
    assert.equal(item.className, 'editor-recovery__item');
    assert.match(item.children[0].textContent, /My article/);
    const actions = item.children[1].children;
    assert.deepEqual(actions.map((button) => button.textContent), ['Restore', 'Delete']);
    assert.ok(actions.every((button) => button.className.includes('btn-secondary')));
});
