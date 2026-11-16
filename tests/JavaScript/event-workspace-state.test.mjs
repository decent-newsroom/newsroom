import assert from 'node:assert/strict';
import { SourceTextModule, createContext } from 'node:vm';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(
  new URL('../../assets/controllers/ui/event_workspace_state_controller.js', import.meta.url),
  'utf8',
);
const account = 'a'.repeat(64);
const otherAccount = 'b'.repeat(64);
const prefix = 'event-workspace-state:v1:';

async function fixture(initial = {}) {
  const values = new Map(Object.entries(initial));
  const storage = {
    get length() { return values.size; },
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, value),
    removeItem: (key) => values.delete(key),
    key: (index) => [...values.keys()][index] ?? null,
  };
  const scrolls = [];
  let clearedSnapshots = 0;
  const listeners = new Map();
  const animationFrames = new Map();
  let nextFrame = 0;
  const window = {
    sessionStorage: storage,
    scrollY: 0,
    requestAnimationFrame: (callback) => {
      const id = ++nextFrame;
      animationFrames.set(id, callback);
      return id;
    },
    cancelAnimationFrame: (id) => animationFrames.delete(id),
    scrollTo: (_x, y) => scrolls.push(y),
    addEventListener: (name, callback) => listeners.set(name, callback),
    removeEventListener: (name) => listeners.delete(name),
  };
  const context = createContext({ window });
  const module = new SourceTextModule(source, { context });
  await module.link(async (specifier) => {
    if (specifier === '@hotwired/turbo') {
      const dependency = new SourceTextModule(
        'export const cache = { clear: () => clearSnapshots() };',
        { context },
      );
      context.clearSnapshots = () => { clearedSnapshots += 1; };
      await dependency.link(() => {});
      return dependency;
    }
    const dependency = new SourceTextModule(
      'export class Controller { constructor(element) { this.element = element; } }',
      { context },
    );
    await dependency.link(() => {});
    return dependency;
  });
  await module.evaluate();

  const details = ['one', 'two', 'three', 'four', 'five'].map((id) => ({
    dataset: { eventWorkspaceDisclosure: id },
    open: false,
    matches: (selector) => selector === 'details[data-event-workspace-disclosure]',
  }));
  const elementListeners = new Map();
  const element = {
    querySelectorAll: () => details,
    addEventListener: (name, callback, capture) => elementListeners.set(name, { callback, capture }),
    removeEventListener: (name) => elementListeners.delete(name),
  };
  const controller = new module.namespace.default(element);
  controller.userValue = account;
  controller.contextValue = 'reading-nook';
  const runAnimationFrames = () => {
    const callbacks = [...animationFrames.values()];
    animationFrames.clear();
    callbacks.forEach((callback) => callback());
  };
  return { controller, details, storage, values, scrolls, listeners, elementListeners, module, runAnimationFrames,
    snapshotClearCount: () => clearedSnapshots };
}

test('restores at most four disclosure IDs and the local scroll position', async () => {
  const stateKey = `${prefix}${account}:reading-nook`;
  const result = await fixture({
    [stateKey]: JSON.stringify({ open: ['one', 'two', 'three', 'four', 'five'], scrollY: 321 }),
  });

  result.controller.connect();
  result.runAnimationFrames();

  assert.deepEqual(result.details.map(({ open }) => open), [true, true, true, true, false]);
  assert.deepEqual(result.scrolls, [321]);
  assert.equal(result.listeners.has('pagehide'), true);
});

test('does not restore scroll after the workspace disconnects', async () => {
  const result = await fixture({
    [`${prefix}${account}:reading-nook`]: JSON.stringify({ open: [], scrollY: 321 }),
  });
  result.controller.connect();
  result.controller.disconnect();
  result.runAnimationFrames();

  assert.deepEqual(result.scrolls, []);
});

test('saves only open disclosure identifiers and scroll position within its account context', async () => {
  const result = await fixture();
  result.controller.connect();
  result.details[0].open = true;
  result.details[2].open = true;
  result.controller.onToggle({ target: result.details[0] });
  result.controller.onToggle({ target: result.details[2] });

  assert.deepEqual(
    JSON.parse(result.values.get(`${prefix}${account}:reading-nook`)),
    { open: ['one', 'three'], scrollY: 0 },
  );
});

test('removes other-account workspace state when a workspace is opened', async () => {
  const oldKey = `${prefix}${otherAccount}:my-content`;
  const currentKey = `${prefix}${account}:reading-nook`;
  const result = await fixture({
    [oldKey]: JSON.stringify({ open: ['private-draft'], scrollY: 4 }),
    [currentKey]: JSON.stringify({ open: [], scrollY: 0 }),
  });

  result.controller.connect();

  assert.equal(result.values.has(oldKey), false);
  assert.equal(result.values.has(currentKey), true);
});

test('logout cleanup removes only event-workspace state entries', async () => {
  const result = await fixture({
    [`${prefix}${account}:reading-nook`]: JSON.stringify({ open: [], scrollY: 123 }),
    [`${prefix}${otherAccount}:my-content`]: '{}',
    'theme': 'dark',
  });

  result.controller.connect();
  result.module.namespace.clearEventWorkspaceState(result.storage);
  result.listeners.get('pagehide')();
  result.controller.disconnect();
  result.runAnimationFrames();

  assert.equal(result.values.has(`${prefix}${account}:reading-nook`), false);
  assert.equal(result.values.has(`${prefix}${otherAccount}:my-content`), false);
  assert.equal(result.values.get('theme'), 'dark');
  assert.deepEqual(result.scrolls, []);
});

test('a new workspace controller can save again after logout cleanup', async () => {
  const result = await fixture();
  result.controller.connect();
  result.module.namespace.clearEventWorkspaceState(result.storage);
  result.controller.disconnect();

  const next = new result.module.namespace.default(result.controller.element);
  next.userValue = otherAccount;
  next.contextValue = 'my-content';
  next.connect();
  result.details[0].open = true;
  next.onToggle({ target: result.details[0] });

  assert.deepEqual(JSON.parse(result.values.get(`${prefix}${otherAccount}:my-content`)), {
    open: ['one'],
    scrollY: 0,
  });
  next.disconnect();
});

test('switching accounts cannot recreate the old account state on disconnect', async () => {
  const result = await fixture();
  result.controller.connect();
  result.details[0].open = true;
  result.controller.onToggle({ target: result.details[0] });

  const next = new result.module.namespace.default(result.controller.element);
  next.userValue = otherAccount;
  next.contextValue = 'my-content';
  next.connect();
  assert.equal(result.snapshotClearCount(), 2, 'first workspace and account change clear Turbo snapshots');
  result.controller.disconnect();

  assert.equal(result.values.has(`${prefix}${account}:reading-nook`), false);
  next.disconnect();
});

test('logout controller clears snapshots and workspace state before clearing the signer', async () => {
  const result = await fixture();
  result.controller.connect();
  const logoutSource = await readFile(
    new URL('../../assets/controllers/nostr/logout_controller.js', import.meta.url), 'utf8',
  );
  const calls = [];
  const context = createContext({ console, calls });
  const module = new SourceTextModule(logoutSource, { context });
  await module.link(async (specifier) => {
    const code = specifier === '@hotwired/stimulus'
      ? 'export class Controller {}'
      : specifier.includes('signer_manager')
        ? 'export function clearRemoteSignerSession() { calls.push("signer"); }'
        : 'export function clearEventWorkspaceState() { clearState(); calls.push("workspace"); }';
    context.clearState = () => result.module.namespace.clearEventWorkspaceState(result.storage);
    const dependency = new SourceTextModule(code, { context });
    await dependency.link(() => {});
    return dependency;
  });
  await module.evaluate();
  new module.namespace.default().handleLogout({});
  assert.deepEqual(calls, ['workspace', 'signer']);
  assert.equal(result.snapshotClearCount(), 2);
});

test('account changes clear snapshots even when storage is unavailable', async () => {
  const result = await fixture();
  result.storage.getItem = () => { throw new Error('storage disabled'); };
  result.controller.connect();
  const next = new result.module.namespace.default(result.controller.element);
  next.userValue = otherAccount;
  next.contextValue = 'my-content';
  next.connect();
  assert.equal(result.snapshotClearCount(), 2);
});

test('malformed state does not prevent normal workspace navigation', async () => {
  const result = await fixture({ [`${prefix}${account}:reading-nook`]: '{invalid' });
  assert.doesNotThrow(() => result.controller.connect());
  assert.deepEqual(result.details.map(({ open }) => open), [false, false, false, false, false]);
  assert.doesNotThrow(() => result.controller.disconnect());
});

test('unavailable storage does not prevent normal workspace navigation', async () => {
  const result = await fixture();
  result.storage.getItem = () => { throw new Error('storage disabled'); };
  result.storage.setItem = () => { throw new Error('storage disabled'); };
  assert.doesNotThrow(() => result.controller.connect());
  result.details[0].open = true;
  assert.doesNotThrow(() => result.controller.onToggle({ target: result.details[0] }));
  assert.doesNotThrow(() => result.controller.disconnect());
});
