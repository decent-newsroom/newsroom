import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { SourceTextModule, createContext } from 'node:vm';

const source = await readFile(new URL('../../assets/controllers/nostr/deferred_embed_controller.js', import.meta.url), 'utf8');

async function fixture(result, type = 'note') {
    const requests = [];
    const context = createContext({
        fetch: async (url, options) => {
            requests.push({ url, options });
            if (result instanceof Error) throw result;
            return result;
        },
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
        bechValue: `${type}1raw-reference`,
        typeValue: type,
        element: { outerHTML: '<div>unresolved placeholder link</div>' },
    });
    return { controller, requests };
}

for (const type of ['note', 'nevent']) {
    test(`${type} posts the current raw browser payload and replaces only its placeholder`, async () => {
        const html = '<div class="nostr-event-preview">local exact preview</div>';
        const { controller, requests } = await fixture({ ok: true, text: async () => html }, type);
        await controller.fetchEmbed();
        assert.equal(requests.length, 1);
        assert.equal(requests[0].url, '/api/preview/');
        assert.equal(requests[0].options.method, 'POST');
        assert.deepEqual(JSON.parse(requests[0].options.body), {
            identifier: `${type}1raw-reference`,
            type,
            decoded: { bech: `${type}1raw-reference`, type },
        });
        assert.equal(controller.element.outerHTML, html);
    });
}

test('invalid, failed and empty responses preserve the unresolved placeholder link', async () => {
    for (const result of [
        { ok: false },
        { ok: true, text: async () => '<div class="alert alert-warning">Invalid event identifier.</div>' },
        { ok: true, text: async () => '' },
        new Error('offline'),
    ]) {
        const { controller, requests } = await fixture(result);
        await controller.fetchEmbed();
        assert.equal(requests.length, 1, 'No retries or additional discovery.');
        assert.equal(controller.element.outerHTML, '<div>unresolved placeholder link</div>');
    }
});

test('local-missing and warning-only fragments retain the established successful response handling', async () => {
    for (const html of [
        '<div class="alert alert-info">Event not found locally.</div>',
        '<div class="nostr-event-preview"><p class="event-preview__sensitive">Content warning</p></div>',
    ]) {
        const { controller } = await fixture({ ok: true, text: async () => html });
        await controller.fetchEmbed();
        assert.equal(controller.element.outerHTML, html);
    }
});
