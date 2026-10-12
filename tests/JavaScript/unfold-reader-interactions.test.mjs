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

class Element {
  constructor() {
    this.dataset = {};
    this.childNodes = [];
    this.parentNode = null;
    this.classList = { add() {} };
  }

  appendChild(child) {
    if (child.parentNode) {
      child.parentNode.childNodes.splice(child.parentNode.childNodes.indexOf(child), 1);
    }
    child.parentNode = this;
    this.childNodes.push(child);
    return child;
  }

  replaceChildren() {
    this.childNodes.forEach((child) => { child.parentNode = null; });
    this.childNodes = [];
  }

  querySelectorAll() {
    return this.childNodes.flatMap((child) => [
      ...(child.dataset.unfoldReplySlot ? [child] : []),
      ...child.querySelectorAll(),
    ]);
  }
}

async function fixture(signer = { getPublicKey: async () => account, signEvent: async () => event }) {
  const values = new Map();
  const storage = {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, value),
    removeItem: (key) => values.delete(key),
  };
  let scheduled = 0;
  let navigated = 0;
  const document = {
    readyState: 'loading', addEventListener() {}, querySelectorAll: () => [], querySelector: () => null,
    createElement: () => new Element(), activeElement: null,
  };
  const context = createContext({
    document,
    window: {
      location: { origin: 'https://journal.example', assign() { navigated++; } }, sessionStorage: storage,
      innerHeight: 800,
      setTimeout: () => ++scheduled, clearTimeout() {}, addEventListener() {},
    },
    fetch: async () => { throw new Error('Unexpected request'); },
    signer,
    URL,
    TextEncoder,
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
    querySelector: () => null, addEventListener() {}, classList: { add() {} },
  };
  const reader = new module.namespace.UnfoldReaderInteractions(root);
  Object.assign(reader, {
    accountPubkey: account, csrfToken: 'csrf', identityResolved: true,
    labels: { unavailable: 'Unavailable', storage_unavailable: 'Storage unavailable', signer_mismatch: 'Wrong account', status_exhausted: 'Checks stopped' },
    renderThread() {}, renderComposerState() {}, updateActionState() {}, updateCountState() {},
    renderReplyPreview() {}, renderPlainText: (value) => value,
    fetchThread: async () => ({ comments: [], comments_count: 1 }),
  });
  return { reader, document, values, scheduled: () => scheduled, navigated: () => navigated };
}

async function inlineFixture() {
  const result = await fixture();
  const { reader, document } = result;
  reader.root = new Element();
  reader.composerHome = reader.root.appendChild(new Element());
  reader.composerForm = reader.composerHome.appendChild(new Element());
  reader.threadLive = reader.root.appendChild(new Element());
  reader.threadFallback = reader.root.appendChild(new Element());
  reader.loadMoreButton = {};
  reader.draftField = {
    value: 'Draft', selectionStart: 2, selectionEnd: 4,
    focus(options) { document.activeElement = this; this.focusOptions = options; },
    setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; },
  };
  reader.composerForm.getBoundingClientRect = () => ({ top: 20, bottom: 200 });
  reader.composerForm.scrollIntoView = () => { result.scrolled = true; };
  reader.submitButton = {};
  reader.loginButton = {};
  reader.composerNotice = {};
  reader.replyPreview = {};
  reader.replyAuthor = {};
  reader.replyMeta = {};
  reader.labels.parent_unavailable = 'Parent unavailable';
  reader.labels.login_required = 'Log in to reply';
  reader.threadLoaded = true;
  reader.serverComments = [
    { id: eventId, kind: 1111, created_at: 100, pubkey: account },
    { id: revision, parent_id: eventId, kind: 1111, created_at: 101, pubkey: account },
  ];
  delete reader.renderThread;
  delete reader.renderComposerState;
  delete reader.renderReplyPreview;
  reader.renderThread();
  return result;
}

test('reply moves the same composer before descendants, focuses it, and cancel retains draft', async () => {
  const { reader, document } = await inlineFixture();
  const form = reader.composerForm;
  reader.setReplyTarget({ id: eventId, author: 'Parent' });
  assert.equal(form.parentNode.dataset.unfoldReplySlot, eventId);
  const article = form.parentNode.parentNode;
  assert.equal(article.childNodes.at(-2), form.parentNode);
  assert.equal(document.activeElement, reader.draftField);
  assert.equal(reader.replyPreview.hidden, false);
  reader.clearReplyTarget();
  assert.equal(form.parentNode, reader.composerHome);
  assert.equal(reader.draftField.value, 'Draft');
  assert.equal(reader.replyPreview.hidden, true);
});

test('switching reply targets preserves the composer and draft', async () => {
  const { reader } = await inlineFixture();
  const form = reader.composerForm;
  reader.setReplyTarget({ id: eventId });
  reader.setReplyTarget({ id: revision });
  assert.equal(reader.composerForm, form);
  assert.equal(form.parentNode.dataset.unfoldReplySlot, revision);
  assert.equal(reader.draftField.value, 'Draft');
});

test('explicit reply scrolls only when its composer is outside the viewport', async () => {
  const result = await inlineFixture();
  const { reader } = result;
  reader.setReplyTarget({ id: eventId });
  assert.equal(result.scrolled, undefined);
  reader.composerForm.getBoundingClientRect = () => ({ top: 700, bottom: 1000 });
  reader.setReplyTarget({ id: revision });
  assert.equal(result.scrolled, true);
  result.scrolled = false;
  reader.renderThread();
  assert.equal(result.scrolled, false);
});

test('thread replacement rehomes the composer and preserves textarea focus and selection', async () => {
  const { reader, document } = await inlineFixture();
  reader.setReplyTarget({ id: revision });
  const oldSlot = reader.composerForm.parentNode;
  reader.threadCursor = 'next-page';
  reader.renderThread();
  assert.notEqual(reader.composerForm.parentNode, oldSlot);
  assert.equal(reader.composerForm.parentNode.dataset.unfoldReplySlot, revision);
  assert.equal(document.activeElement, reader.draftField);
  assert.equal(reader.draftField.selectionStart, 2);
  assert.equal(reader.draftField.selectionEnd, 4);
  assert.equal(reader.draftField.focusOptions.preventScroll, true);
  assert.equal(reader.loadMoreButton.hidden, false);
});

test('background thread rendering does not focus an inactive composer', async () => {
  const { reader, document } = await inlineFixture();
  reader.setReplyTarget({ id: eventId });
  document.activeElement = null;
  reader.renderThread();
  assert.equal(document.activeElement, null);
});

test('missing reply target preserves draft and blocks publishing with an explicit notice', async () => {
  const { reader } = await inlineFixture();
  const staleFallbackSlot = reader.threadFallback.appendChild(new Element());
  staleFallbackSlot.dataset.unfoldReplySlot = eventId;
  reader.setReplyTarget({ id: eventId });
  reader.serverComments = [];
  reader.renderThread();
  reader.renderComposerState();
  assert.equal(reader.composerForm.parentNode, reader.composerHome);
  assert.equal(reader.draftField.value, 'Draft');
  assert.equal(reader.replyTarget.id, eventId);
  assert.equal(reader.submitButton.disabled, true);
  let published = false;
  reader.publishInteraction = async () => { published = true; };
  await reader.onSubmit({ preventDefault() {} });
  assert.equal(published, false);
  assert.equal(reader.composerNotice.textContent, 'Parent unavailable');
  reader.serverComments = [{ id: eventId, kind: 1111, created_at: 100, pubkey: account }];
  reader.renderThread();
  reader.renderComposerState();
  assert.equal(reader.composerForm.parentNode.dataset.unfoldReplySlot, eventId);
  assert.equal(reader.submitButton.disabled, false);
  assert.equal(reader.composerNotice.hidden, true);
});

test('anonymous reply shows the local login prompt and parent preview', async () => {
  const { reader } = await inlineFixture();
  reader.accountPubkey = null;
  reader.setReplyTarget({ id: eventId, author: 'Parent' });
  assert.equal(reader.composerForm.parentNode.dataset.unfoldReplySlot, eventId);
  assert.equal(reader.loginButton.hidden, false);
  assert.equal(reader.submitButton.hidden, true);
  assert.equal(reader.replyPreview.hidden, false);
  assert.equal(reader.composerNotice.textContent, 'Log in to reply');
});

test('restored account reply remounts inline without stealing focus', async () => {
  const { reader, document, values } = await inlineFixture();
  reader.draftField.value = '';
  values.set(reader.draftStorageKey(), JSON.stringify({
    content: 'Saved reply', reply: { id: revision, author: 'Parent' },
  }));
  reader.restoreDraft();
  reader.renderThread();
  reader.renderComposerState();
  assert.equal(reader.composerForm.parentNode.dataset.unfoldReplySlot, revision);
  assert.equal(reader.draftField.value, 'Saved reply');
  assert.equal(document.activeElement, null);
});

test('accepted matching reply clears the draft and restores composer home', async () => {
  const { reader } = await inlineFixture();
  reader.setReplyTarget({ id: eventId });
  reader.maybeClearSubmittedDraft({ action: 'reply', content: 'Draft', parentId: eventId });
  assert.equal(reader.composerForm.parentNode, reader.composerHome);
  assert.equal(reader.draftField.value, '');
  assert.equal(reader.replyTarget, null);
});

test('pending status for a different draft does not discard current inline reply', async () => {
  const { reader } = await inlineFixture();
  reader.setReplyTarget({ id: eventId });
  reader.maybeClearSubmittedDraft({ action: 'reply', content: 'Older draft', parentId: eventId });
  assert.equal(reader.composerForm.parentNode.dataset.unfoldReplySlot, eventId);
  assert.equal(reader.draftField.value, 'Draft');
});

for (const action of ['comment', 'reply']) {
  test(`${action} uses distinct empty and UTF-8 overflow messages at the exact byte boundary`, async () => {
    const { reader } = await fixture();
    reader.replyTarget = action === 'reply' ? { id: eventId } : null;
    reader.composerNotice = {};
    reader.labels.invalid_comment = 'Write something before posting.';
    reader.labels.comment_too_long = 'Too long';
    reader.draftField = { value: ' \n\t' };
    const published = [];
    reader.publishInteraction = async (type, content) => { published.push([type, content]); };
    await reader.onSubmit({ preventDefault() {} });
    assert.equal(reader.composerNotice.textContent, reader.labels.invalid_comment);
    reader.draftField.value = 'é'.repeat(2000);
    await reader.onSubmit({ preventDefault() {} });
    assert.equal(published.length, 1);
    assert.equal(published[0][0], action);
    reader.draftField.value += 'a';
    await reader.onSubmit({ preventDefault() {} });
    assert.equal(published.length, 1);
    assert.equal(reader.composerNotice.textContent, 'Too long');
  });
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

test('initial discussion loading status is cleared after both initial requests succeed', async () => {
  const { reader } = await fixture();
  reader.labels = { loading: 'Loading discussion', unavailable: 'Unavailable' };
  reader.threadNotice = {};
  reader.globalNotice = {};
  reader.fetchThread = async () => ({ comments: [], comments_count: 0, next_cursor: '', refresh_status: '' });
  reader.fetchIdentity = async () => ({
    pubkey: null, login_url: '/login', csrf_token: '', liked: false, reposted: false,
    repost_available: true, likes: 0, reposts: 0,
  });

  await reader.init();

  assert.equal(reader.threadNotice.textContent, '');
  assert.equal(reader.threadNotice.hidden, true);
  assert.equal(reader.globalNotice.textContent, '');
  assert.equal(reader.globalNotice.hidden, true);
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
