import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { SourceTextModule, createContext } from 'node:vm';

const source = await readFile(new URL('../../assets/controllers/content/stream_controller.js', import.meta.url), 'utf8');

async function fixture({ profile = true, owner = false } = {}) {
    const connections = [];
    class Events {
        constructor(url) {
            this.url = new URL(url);
            connections.push(this);
        }
        close() { this.closed = true; }
    }
    const context = createContext({
        URL, EventSource: Events, window: { location: { origin: 'https://newsroom.test' } },
    });
    const module = new SourceTextModule(source, { context });
    await module.link(async () => {
        const stimulus = new SourceTextModule('export class Controller {}', { context });
        await stimulus.link(() => {});
        return stimulus;
    });
    await module.evaluate();
    const controller = new module.namespace.default();
    Object.assign(controller, {
        hasPubkeyValue: profile, pubkeyValue: 'author', hubUrlValue: '/.well-known/mercure',
        ownerValue: owner, urlValue: '/p/author/stream',
        frameTarget: { src: '', reload() { this.reloads = (this.reloads || 0) + 1; } },
        hasNoticeTarget: true, noticeTarget: { hidden: true }, errorTarget: { hidden: true },
    });
    controller.connect();
    return { controller, connections };
}

test('one connection subscribes to all public sources but never visitor drafts', async () => {
    const { controller, connections } = await fixture();
    assert.equal(connections.length, 1);
    assert.deepEqual(connections[0].url.searchParams.getAll('topic'), [
        '/author/author/overview', '/author/author/articles',
        '/author/author/media', '/author/author/highlights',
    ]);
    connections[0].onmessage();
    assert.equal(controller.noticeTarget.hidden, false);
    assert.equal(controller.frameTarget.src, '', 'updates must not replace the reader position automatically');
    controller.disconnect();
    assert.equal(connections[0].closed, true);
});

test('only an owner subscribes to draft changes and home has no profile subscription', async () => {
    const owner = await fixture({ owner: true });
    assert.ok(owner.connections[0].url.searchParams.getAll('topic').includes('/author/author/drafts'));
    const home = await fixture({ profile: false });
    assert.equal(home.connections.length, 0);
    home.controller.disconnect();
    owner.controller.disconnect();
});

test('explicit refresh resets the page and supports retrying the same URL', async () => {
    const { controller } = await fixture({ profile: false });
    controller.noticeTarget.hidden = false;
    controller.errorTarget.hidden = false;
    controller.refresh();
    assert.equal(controller.frameTarget.src, 'https://newsroom.test/p/author/stream?refresh=1');
    assert.equal(controller.noticeTarget.hidden, true);
    assert.equal(controller.errorTarget.hidden, true);
    controller.refresh();
    assert.equal(controller.frameTarget.reloads, 1);
});

test('only the stream frame failure displays its retry message', async () => {
    const { controller } = await fixture({ profile: false });
    let prevented = false;
    controller.failed({ target: {}, preventDefault: () => { prevented = true; } });
    assert.equal(prevented, false);
    assert.equal(controller.errorTarget.hidden, true);
    controller.failed({ target: controller.frameTarget, preventDefault: () => { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(controller.errorTarget.hidden, false);
});
