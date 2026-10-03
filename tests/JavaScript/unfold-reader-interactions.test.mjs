import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { SourceTextModule, createContext } from 'node:vm';

const account = 'c'.repeat(64);
const eventId = 'b'.repeat(64);
const revision = 'a'.repeat(64);
const source = await readFile(new URL('../../assets/unfold-reader-interactions.js', import.meta.url), 'utf8');
const event = { id: eventId, pubkey: account, sig: 'f'.repeat(128), kind: 1111, created_at: 100, tags: [], content: 'Text' };
const prepared = { event: { pubkey: account, kind: 1111, created_at: 100, tags: [], content: 'Text' }, target_event_id: revision };
const delivery = (status = 'queued', retryable = true) => ({
  event_id: eventId, status, retryable, local_commit: true,
  published: status === 'published', relay_complete: status === 'published', relay_results: {}, error: null,
});

async function fixture(signer = { getPublicKey: async () => account, signEvent: async () => event }) {
  const values = new Map();
  const storage = {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, value),
    removeItem: (key) => values.delete(key),
  };
  let scheduled = 0;
  let navigated = 0;
  const context = createContext({
    document: { readyState: 'loading', addEventListener() {}, querySelectorAll: () => [], querySelector: () => null },
    window: {
      location: { origin: 'https://journal.example', assign() { navigated++; } }, sessionStorage: storage,
      setTimeout: () => ++scheduled, clearTimeout() {}, addEventListener() {},
    },
    fetch: async () => { throw new Error('Unexpected request'); },
    signer,
    URL,
    HTMLElement: class {},
  });
  const module = new SourceTextModule(source, { context });
  await module.link(async () => {
    const dependency = new SourceTextModule(
      'export const getSigner = async () => globalThis.signer; export const getRemoteSignerSession = () => null;',
      { context },
    );
    await dependency.link(() => {});
    return dependency;
  });
  await module.evaluate();
  const root = {
    dataset: { coordinate: `30023:${revision}:story` },
    querySelector: () => null, classList: { add() {} },
  };
  const reader = new module.namespace.UnfoldReaderInteractions(root);
  Object.assign(reader, {
    accountPubkey: account, csrfToken: 'csrf', identityResolved: true,
    labels: { unavailable: 'Unavailable', storage_unavailable: 'Storage unavailable', signer_mismatch: 'Wrong account', status_exhausted: 'Checks stopped' },
    renderThread() {}, renderComposerState() {}, updateActionState() {}, updateCountState() {},
    renderReplyPreview() {}, renderPlainText: (value) => value,
    fetchThread: async () => ({ comments: [], comments_count: 1 }),
  });
  return { reader, values, scheduled: () => scheduled, navigated: () => navigated };
}

test('prepare accepts an unsigned event without id or signature', async () => {
  const { reader } = await fixture();
  assert.equal(reader.validatePrepareResponse(prepared).event, prepared.event);
  assert.throws(() => reader.validatePrepareResponse({ ...prepared, event: { ...prepared.event, pubkey: revision } }));
});

test('malformed successful delivery is not treated as acceptance', async () => {
  const { reader } = await fixture();
  assert.throws(() => reader.validateDeliveryResponse({ status: 'queued' }));
  assert.throws(() => reader.validateDeliveryResponse({ ...delivery(), status: 'anything' }));
  assert.throws(() => reader.validateDeliveryResponse(null));
});

test('malformed account state is rejected rather than restoring another account draft', async () => {
  const { reader } = await fixture();
  assert.throws(() => reader.validateIdentityResponse({ pubkey: 'junk' }));
  assert.throws(() => reader.validateIdentityResponse({
    pubkey: account, login_url: '/login', csrf_token: 'token',
    liked: 'false', reposted: false, repost_available: true, likes: 0, reposts: 0,
  }));
});

test('delivery reads never inflate an already active like', async () => {
  const { reader } = await fixture();
  reader.liked = true;
  reader.likesCount = 7;
  const record = reader.buildRecord('like', '', event, prepared);
  reader.saveRecord(record);
  reader.applyDeliveryResult('like', record, delivery());
  reader.applyDeliveryResult('like', record, delivery('partial'));
  reader.applyDeliveryResult('like', record, delivery('published', false));
  assert.equal(reader.likesCount, 7);
});

test('a newly accepted like increments once across status updates', async () => {
  const { reader } = await fixture();
  const record = reader.buildRecord('like', '', event, prepared);
  reader.saveRecord(record);
  reader.applyDeliveryResult('like', record, delivery());
  reader.applyDeliveryResult('like', record, delivery('published', false));
  assert.equal(reader.likesCount, 1);
});

test('failed but retryable delivery actually calls retry', async () => {
  const { reader } = await fixture();
  const record = reader.buildRecord('like', '', event, prepared);
  reader.saveRecord(record);
  reader.fetchStatus = async () => delivery('failed');
  let calls = 0;
  reader.postJson = async (url, body) => {
    calls++;
    assert.equal(url, reader.retryUrl);
    assert.equal(body.event_id, eventId);
    return delivery();
  };
  await reader.retryRecord('like', eventId);
  assert.equal(calls, 1);
});

test('locally accepted event can retry from the server without a browser payload', async () => {
  const { reader } = await fixture();
  const record = reader.buildRecord('like', '', event, prepared);
  record.localCommit = true;
  delete record.event;
  reader.saveRecord(record);
  reader.fetchStatus = async () => delivery('partial');
  let calls = 0;
  reader.postJson = async (url) => {
    calls++;
    assert.equal(url, reader.retryUrl);
    return delivery();
  };
  await reader.retryRecord('like', eventId);
  assert.equal(calls, 1);
});

test('restored rejected status is reconciled with a locally accepted server event', async () => {
  const { reader } = await fixture();
  const record = reader.buildRecord('like', '', event, prepared);
  record.localCommit = true;
  record.status = 'failed';
  reader.removeSignedPayload(record);
  reader.saveRecord(record);
  reader.pendingRecords.clear();
  reader.restorePendingRecords();
  reader.fetchStatus = async () => delivery('published', false);
  await reader.refreshPendingStatuses(true);
  assert.equal(reader.getRecord(eventId).status, 'published');
});

test('missing original commit replays exactly the stored signed payload', async () => {
  const { reader } = await fixture();
  const parent = { id: 'd'.repeat(64), author: 'Parent', time: '' };
  const record = reader.buildRecord('reply', 'Text', event, prepared, parent);
  reader.saveRecord(record);
  reader.fetchStatus = async () => null;
  let calls = 0;
  reader.postJson = async (url, body) => {
    calls++;
    assert.equal(url, reader.publishUrl);
    assert.equal(body.event, event);
    assert.equal(body.parent_id, parent.id);
    return delivery();
  };
  await reader.retryRecord('reply', eventId);
  assert.equal(calls, 1);
});

test('comment and reply share a lock before asynchronous signing starts', async () => {
  const { reader } = await fixture();
  let release;
  let calls = 0;
  reader.acquireSigner = () => {
    calls++;
    return new Promise((resolve) => { release = resolve; });
  };
  const first = reader.publishInteraction('comment', 'Text');
  await reader.publishInteraction('reply', 'Other');
  assert.equal(calls, 1);
  assert.equal(reader.isActionLocked('reply'), true);
  release(null);
  await first;
  assert.equal(reader.isActionLocked('comment'), false);
});

test('storage failure aborts publish after preparing and signing', async () => {
  const { reader } = await fixture();
  reader.storageAvailable = false;
  let calls = 0;
  reader.postJson = async (url) => {
    calls++;
    assert.equal(url, reader.prepareUrl);
    return prepared;
  };
  await reader.publishInteraction('comment', 'Text');
  assert.equal(calls, 1);
});

test('signer account mismatch is rejected before signing', async () => {
  let signed = false;
  const { reader } = await fixture({
    getPublicKey: async () => revision,
    signEvent: async () => { signed = true; return event; },
  });
  assert.equal(await reader.acquireSigner(), null);
  assert.equal(signed, false);
});

test('locally accepted comment permits another identical comment while queued', async () => {
  const { reader } = await fixture();
  const record = reader.buildRecord('comment', 'Text', event, prepared);
  record.localCommit = true;
  record.status = 'queued';
  reader.saveRecord(record);
  reader.draftField = { value: 'Text' };
  assert.equal(reader.hasBlockingComposerRecord('comment'), false);
  assert.equal(reader.canPublishCurrentDraft('comment', record), true);
});

test('saving pending state does not reset the bounded polling budget', async () => {
  const { reader, scheduled } = await fixture();
  const record = reader.buildRecord('like', '', event, prepared);
  reader.statusRefreshAttempts = 6;
  reader.saveRecord(record);
  reader.threadNotice = {};
  reader.scheduleStatusRefresh();
  assert.equal(reader.statusRefreshAttempts, 6);
  assert.equal(scheduled(), 0);
  assert.equal(reader.threadNotice.textContent, 'Checks stopped');
  assert.equal(record.retryable, true);
});

test('exhausted polling retains server-side retry for a locally committed event', async () => {
  const { reader } = await fixture();
  const record = reader.buildRecord('like', '', event, prepared);
  record.localCommit = true;
  record.status = 'queued';
  reader.removeSignedPayload(record);
  reader.saveRecord(record);
  reader.statusRefreshAttempts = 6;
  reader.scheduleStatusRefresh();
  assert.equal(record.retryable, true);
});

test('account draft restoration never migrates an unintentional anonymous draft', async () => {
  const { reader, values } = await fixture();
  values.set(reader.draftStorageKey(null), JSON.stringify({ content: 'Anonymous private draft' }));
  reader.draftField = { value: '' };
  reader.restoreDraft();
  assert.equal(reader.draftField.value, '');
  values.set(reader.draftStorageKey(null), JSON.stringify({ content: 'Login draft', loginContinuation: true }));
  reader.restoreDraft();
  assert.equal(reader.draftField.value, 'Login draft');
  assert.equal(values.has(reader.draftStorageKey(null)), false);
});

test('server comments retain the reader event delivery and retry state', async () => {
  const { reader } = await fixture();
  const record = reader.buildRecord('comment', 'Text', event, prepared);
  record.status = 'failed';
  record.retryable = true;
  reader.saveRecord(record);
  reader.serverComments = [{ id: eventId, kind: 1111, content_html: 'Server text' }];
  const comments = reader.visibleComments();
  assert.equal(comments.length, 1);
  assert.equal(comments[0].retryable, true);
  assert.equal(comments[0].content_html, 'Server text');
});

test('login navigation does not discard a draft when persistence fails', async () => {
  const { reader, navigated } = await fixture();
  reader.accountPubkey = null;
  reader.storageAvailable = false;
  reader.draftField = { value: 'Keep this draft' };
  reader.loginUrl = '/login';
  reader.globalNotice = {};
  reader.goToLogin();
  assert.equal(navigated(), 0);
  assert.equal(reader.globalNotice.textContent, 'Storage unavailable');
  assert.equal(reader.draftField.value, 'Keep this draft');
});
