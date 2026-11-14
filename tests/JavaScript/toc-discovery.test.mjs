import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { SourceTextModule, createContext } from 'node:vm';

const source = await readFile(new URL('../../assets/controllers/content/toc_discovery_controller.js', import.meta.url), 'utf8');

async function fixture(responses) {
    const requests = [];
    const context = createContext({
        AbortController, console,
        document: { activeElement: {} },
        setTimeout(callback, delay) {
            if (delay === 3000) queueMicrotask(callback);
            return 1;
        },
        clearTimeout() {},
        fetch: async (url, options) => {
            requests.push({ url, options });
            const data = responses.shift() ?? { status: 'loading' };
            if (data instanceof Error) throw data;
            return { ok: true, redirected: false, json: async () => data };
        },
    });
    const module = new SourceTextModule(source, { context });
    await module.link(async () => {
        const stimulus = new SourceTextModule('export class Controller {}', { context });
        await stimulus.link(() => {});
        return stimulus;
    });
    await module.evaluate();
    const make = () => {
        const controller = new module.namespace.default();
        const focused = [];
        const sibling = { html: 'open sibling', expanded: true };
        Object.assign(controller, {
            urlValue: '/authorized-source/toc/2/discovery', tokenValue: 'csrf',
            loadingValue: 'Loading', failedValue: 'Failed', limitedValue: 'Limited', retryValue: 'Retry',
            availableValue: 'Available', unavailableValue: 'Pinned revision unavailable',
            buttonTarget: { disabled: false, textContent: 'Discover' }, hasButtonTarget: true,
            statusTarget: { textContent: '' }, hasStatusTarget: true,
            panelTarget: { innerHTML: 'missing occurrence', querySelector: () => ({ focus: options => focused.push(options) }) },
            element: { contains: () => true },
        });
        return { controller, sibling, focused };
    };
    return { make, requests, context };
}

const click = controller => controller.discover({ preventDefault() {} });

test('updates only the occurrence with one POST and read-only polling, preserving sibling and focus', async () => {
    const f = await fixture([{ status: 'loading' }, { status: 'available', html: '<details>child</details>' }]);
    const { controller, sibling, focused } = f.make();
    await click(controller);
    assert.equal(f.requests.length, 2);
    assert.deepEqual(f.requests.map(r => r.options.method), ['POST', 'GET']);
    assert.equal(f.requests[0].options.headers['X-CSRF-Token'], 'csrf');
    assert.equal(f.requests[0].options.body, undefined);
    assert.equal(controller.panelTarget.innerHTML, '<details>child</details>');
    assert.equal(controller.statusTarget.textContent, 'Available');
    assert.equal(sibling.html, 'open sibling');
    assert.equal(sibling.expanded, true);
    assert.equal(focused.length, 1);
    assert.equal(focused[0].preventScroll, true);
});

test('missing worker/SSE converges after exactly twenty polls and offers explicit retry', async () => {
    const f = await fixture([]);
    const { controller } = f.make();
    await click(controller);
    assert.equal(f.requests.length, 21);
    assert.equal(controller.statusTarget.textContent, 'Failed');
    assert.equal(controller.buttonTarget.textContent, 'Retry');
    assert.equal(controller.buttonTarget.disabled, false);
    assert.equal(controller.panelTarget.innerHTML, 'missing occurrence');
});

test('server limits and HTTP failures cannot reload a parent or target media', async () => {
    for (const result of [
        { status: 'limited', retryAfter: 600 }, new Error('403'),
        Object.assign(new Error('HTTP timed out'), { name: 'AbortError' }),
    ]) {
        const f = await fixture([result]);
        const { controller } = f.make();
        await click(controller);
        assert.equal(f.requests.length, 1);
        assert.equal(controller.panelTarget.innerHTML, 'missing occurrence');
        assert.equal(controller.buttonTarget.disabled, false);
        assert.equal(controller.buttonTarget.textContent, 'Retry');
    }
});

test('unavailable exact pins do not offer a futile retry', async () => {
    const f = await fixture([{ status: 'unavailable', retryAfter: 0 }]);
    const { controller } = f.make();
    await click(controller);
    assert.equal(controller.statusTarget.textContent, 'Pinned revision unavailable');
    assert.equal(controller.buttonTarget.textContent, 'Discover');
});

test('four concurrent discoveries cap browser work; disconnect aborts and releases slots', async () => {
    const f = await fixture([]);
    const pending = [];
    f.context.fetch = (url, options) => new Promise((resolve, reject) => {
        pending.push({ resolve, options });
        options.signal.addEventListener('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })));
    });
    const rows = Array.from({ length: 5 }, () => f.make().controller);
    const jobs = rows.slice(0, 4).map(click);
    await click(rows[4]);
    assert.equal(pending.length, 4);
    assert.equal(rows[4].statusTarget.textContent, 'Limited');
    rows.slice(0, 4).forEach(row => row.disconnect());
    await Promise.all(jobs);
    const last = click(rows[4]);
    assert.equal(pending.length, 5);
    rows[4].disconnect();
    await last;
});
