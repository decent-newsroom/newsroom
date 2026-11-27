import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { SourceTextModule, createContext } from 'node:vm';

const source = await readFile(new URL('../../assets/controllers/ui/workspace_fragment_controller.js', import.meta.url), 'utf8');

async function fixture() {
    const context = createContext({ AbortController });
    const module = new SourceTextModule(source, { context });
    await module.link(async () => {
        const stimulus = new SourceTextModule('export class Controller {}', { context });
        await stimulus.link(() => {});
        return stimulus;
    });
    await module.evaluate();

    return () => {
        const element = { isConnected: true, open: true, tagName: 'DETAILS' };
        const frame = new EventTarget();
        frame.parentElement = element;
        const controller = new module.namespace.default();
        controller.element = element;
        controller.connect();
        const abort = new AbortController();
        const options = { signal: abort.signal };
        let resumes = 0;
        let prevented = false;
        const event = {
            target: frame,
            detail: { fetchOptions: options, resume: () => { ++resumes; } },
            preventDefault: () => { prevented = true; },
        };
        return { element, frame, controller, abort, options, event, get resumes() { return resumes; }, get prevented() { return prevented; } };
    };
}

test('workspace frame requests share four slots and continue after responses or errors', async () => {
    const make = await fixture();
    const rows = Array.from({ length: 6 }, make);
    rows.forEach(row => row.controller.beforeRequest(row.event));
    assert.deepEqual(rows.map(row => row.resumes), [1, 1, 1, 1, 0, 0]);
    assert.ok(rows.every(row => row.prevented));

    rows[0].frame.dispatchEvent(new Event('turbo:before-fetch-response'));
    assert.equal(rows[4].resumes, 1);
    rows[1].frame.dispatchEvent(new Event('turbo:fetch-request-error'));
    assert.equal(rows[5].resumes, 1);
    rows.slice(2).forEach(row => row.abort.abort());
});

test('a queued closed disclosure waits until reopened', async () => {
    const make = await fixture();
    const rows = Array.from({ length: 6 }, make);
    rows.forEach(row => row.controller.beforeRequest(row.event));
    rows[4].element.open = false;
    rows[0].frame.dispatchEvent(new Event('turbo:before-fetch-response'));
    assert.equal(rows[4].resumes, 0);
    assert.equal(rows[5].resumes, 1);
    rows[4].element.open = true;
    rows[4].controller.toggle();
    assert.equal(rows[4].resumes, 0);
    rows[1].abort.abort();
    assert.equal(rows[4].resumes, 1);
    rows.slice(2).forEach(row => row.abort.abort());
});

test('chapter frames outside disclosures load automatically and share the four request limit', async () => {
    const make = await fixture();
    const chapters = Array.from({ length: 5 }, make);
    for (const chapter of chapters) {
        chapter.element.tagName = 'DIV';
        delete chapter.element.open;
        chapter.controller.beforeRequest(chapter.event);
    }
    assert.deepEqual(chapters.map(chapter => chapter.resumes), [1, 1, 1, 1, 0]);
    chapters[0].frame.dispatchEvent(new Event('turbo:before-fetch-response'));
    assert.equal(chapters[4].resumes, 1);
    chapters.slice(1).forEach(chapter => chapter.abort.abort());
});

test('aborted and detached queued frames settle without starting a request', async () => {
    const make = await fixture();
    const rows = Array.from({ length: 7 }, make);
    rows.forEach(row => row.controller.beforeRequest(row.event));
    rows[4].abort.abort();
    assert.equal(rows[4].resumes, 1);
    assert.equal(rows[4].options.signal.aborted, true);

    rows[5].element.isConnected = false;
    rows[5].controller.disconnect();
    assert.equal(rows[5].resumes, 1);
    assert.equal(rows[5].options.signal.aborted, true);

    rows[0].abort.abort();
    assert.equal(rows[6].resumes, 1);
    rows.slice(1, 4).forEach(row => row.abort.abort());
    rows[6].abort.abort();
});

test('nested frame requests are controlled by their own disclosure only', async () => {
    const make = await fixture();
    const parent = make();
    const child = make();
    child.controller.beforeRequest(child.event);
    parent.controller.beforeRequest(child.event);
    assert.equal(child.resumes, 1);
    child.abort.abort();
});

test('disconnect aborts an active frame before releasing its slot', async () => {
    const make = await fixture();
    const rows = Array.from({ length: 5 }, make);
    rows.forEach(row => row.controller.beforeRequest(row.event));
    rows[0].element.isConnected = false;
    rows[0].controller.disconnect();
    assert.equal(rows[0].options.signal.aborted, true);
    assert.equal(rows[4].resumes, 1);
    rows.slice(1).forEach(row => row.abort.abort());
});

test('a response retains its slot until its streamed body finishes', async () => {
    const make = await fixture();
    const rows = Array.from({ length: 5 }, make);
    rows.forEach(row => row.controller.beforeRequest(row.event));
    let stream;
    const body = new ReadableStream({ start(controller) { stream = controller; } });
    const response = new Event('turbo:before-fetch-response');
    Object.defineProperty(response, 'detail', { value: { fetchResponse: { response: new Response(body) } } });
    rows[0].frame.dispatchEvent(response);
    assert.equal(rows[4].resumes, 0);
    stream.enqueue(new Uint8Array([1]));
    await new Promise(resolve => setTimeout(resolve, 10));
    assert.equal(rows[4].resumes, 0);
    stream.close();
    const deadline = Date.now() + 1000;
    while (rows[4].resumes === 0 && Date.now() < deadline) {
        await new Promise(resolve => setTimeout(resolve, 10));
    }
    assert.equal(rows[4].resumes, 1);
    rows.slice(1).forEach(row => row.abort.abort());
});
