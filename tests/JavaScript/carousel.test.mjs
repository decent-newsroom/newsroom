import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { SourceTextModule, createContext } from 'node:vm';

const source = await readFile(new URL('../../assets/controllers/ui/carousel_controller.js', import.meta.url), 'utf8');

async function fixture() {
    let resize;
    let callback;
    const context = createContext({
        ResizeObserver: class {
            constructor(fn) { resize = this; this.fn = fn; }
            observe(element) { this.element = element; }
            disconnect() { this.disconnected = true; }
        },
        requestAnimationFrame: fn => { callback = fn; return 42; },
        cancelAnimationFrame: id => { context.cancelled = id; },
        getComputedStyle: () => ({ columnGap: '16px' }),
    });
    const module = new SourceTextModule(source, { context });
    await module.link(async () => {
        const stimulus = new SourceTextModule('export class Controller {}', { context });
        await stimulus.link(() => {});
        return stimulus;
    });
    await module.evaluate();
    const track = {
        scrollWidth: 800, clientWidth: 300, scrollLeft: 0,
        firstElementChild: { getBoundingClientRect: () => ({ width: 200 }) },
        addEventListener(type, fn) { this.listener = fn; },
        removeEventListener(type, fn) { this.removed = this.listener === fn; },
        scrollBy(options) { this.scrolled = options.left; },
    };
    const controller = new module.namespace.default();
    Object.assign(controller, { trackTarget: track, previousTarget: {}, nextTarget: {} });
    controller.connect();
    callback();
    return { controller, track, resize, context };
}

test('controls reflect overflow and scroll one card in either direction', async () => {
    const { controller, track, resize } = await fixture();
    assert.equal(controller.previousTarget.disabled, true);
    assert.equal(controller.nextTarget.disabled, false);
    assert.equal(controller.nextTarget.hidden, false);
    controller.next();
    assert.equal(track.scrolled, 216);
    track.scrollLeft = 500;
    track.listener();
    assert.equal(controller.previousTarget.disabled, false);
    assert.equal(controller.nextTarget.disabled, true);
    controller.previous();
    assert.equal(track.scrolled, -216);
    track.scrollWidth = 300;
    resize.fn();
    assert.equal(controller.previousTarget.hidden, true);
    assert.equal(controller.nextTarget.hidden, true);
});

test('disconnect removes the scroll listener and observer', async () => {
    const { controller, track, resize, context } = await fixture();
    controller.disconnect();
    assert.equal(track.removed, true);
    assert.equal(resize.disconnected, true);
    assert.equal(context.cancelled, 42);
});
