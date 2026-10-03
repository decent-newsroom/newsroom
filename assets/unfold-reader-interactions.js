import { getRemoteSignerSession, getSigner } from './controllers/nostr/signer_manager.js';

const ROOT_SELECTOR = '[data-unfold-interactions]';
const ACTIONS = ['comment', 'reply', 'like', 'repost'];
const COMMENT_ACTIONS = new Set(['comment', 'reply']);
const PENDING_STATUSES = new Set(['pending', 'queued', 'partial']);
const RETRYABLE_HTTP_STATUSES = new Set([408, 425, 429, 500, 502, 503, 504]);
const NON_RETRYABLE_HTTP_STATUSES = new Set([400, 401, 403, 404, 409, 410, 422]);
const STATUS_REFRESH_LIMIT = 6;
const STATUS_REFRESH_DELAY_MS = 5000;

function safeJsonParse(raw, fallback = {}) {
  if (typeof raw !== 'string' || raw.trim() === '') {
    return fallback;
  }

  try {
    return JSON.parse(raw);
  } catch {
    return fallback;
  }
}

function safeNumber(value, fallback = 0) {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : fallback;
}

function safeString(value, fallback = '') {
  return typeof value === 'string' ? value : fallback;
}

function isPlainObject(value) {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value);
}

function createElement(tagName, className = '', textContent = null) {
  const element = document.createElement(tagName);
  if (className) {
    element.className = className;
  }
  if (textContent !== null) {
    element.textContent = textContent;
  }
  return element;
}

function buildUrl(base, params = {}) {
  const url = new URL(base, window.location.origin);
  Object.entries(params).forEach(([key, value]) => {
    if (value === undefined || value === null || value === '') {
      return;
    }
    url.searchParams.set(key, String(value));
  });
  return url.toString();
}

function formatTimestamp(value) {
  const timestamp = Number(value);
  if (!Number.isFinite(timestamp)) {
    return '';
  }

  return new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(timestamp * 1000));
}

export class UnfoldReaderInteractions {
  constructor(root) {
    this.root = root;
    this.coordinate = safeString(root.dataset.coordinate);
    this.commentsUrl = safeString(root.dataset.commentsUrl, '/unfold/api/interactions');
    this.meUrl = safeString(root.dataset.meUrl, '/unfold/api/interactions/me');
    this.prepareUrl = safeString(root.dataset.prepareUrl, '/unfold/api/interactions/prepare');
    this.publishUrl = safeString(root.dataset.publishUrl, '/unfold/api/interactions/publish');
    this.statusUrl = safeString(root.dataset.statusUrl, '/unfold/api/interactions/status');
    this.retryUrl = safeString(root.dataset.retryUrl, '/unfold/api/interactions/retry');
    this.labels = safeJsonParse(root.dataset.labels, {});
    this.pageSize = safeNumber(root.dataset.pageSize, 25);
    this.maxDepth = Math.max(1, safeNumber(root.dataset.maxDepth, 6));
    this.totalCommentCount = safeNumber(root.dataset.commentsCount, 0);
    this.threadCursor = null;
    this.serverComments = [];
    this.threadLoaded = false;
    this.localComments = new Map();
    this.pendingRecords = new Map();
    this.latestRecordIds = new Map();
    this.actionLocks = new Set();
    this.accountPubkey = null;
    this.loginUrl = null;
    this.csrfToken = null;
    this.liked = false;
    this.reposted = false;
    this.likesCount = 0;
    this.repostsCount = 0;
    this.repostAvailable = true;
    this.loadingThread = false;
    this.loadingIdentity = false;
    this.storageAvailable = true;
    this.pendingHint = null;
    this.remoteSignerSession = false;
    this.storage = null;
    this.identityResolved = false;
    this.statusRefreshTimer = null;
    this.statusRefreshAttempts = 0;
    this.statusRefreshLimit = STATUS_REFRESH_LIMIT;

    this.composerForm = root.querySelector('[data-unfold-composer-form]');
    this.draftField = root.querySelector('[data-unfold-draft]');
    this.submitButton = root.querySelector('[data-unfold-submit]');
    this.loginButton = root.querySelector('[data-unfold-login-button]');
    this.replyPreview = root.querySelector('[data-unfold-reply-preview]');
    this.replyAuthor = root.querySelector('[data-unfold-reply-author]');
    this.replyMeta = root.querySelector('[data-unfold-reply-meta]');
    this.replyCancelButton = root.querySelector('[data-unfold-reply-cancel]');
    this.globalNotice = root.querySelector('[data-unfold-global-notice]');
    this.composerNotice = root.querySelector('[data-unfold-composer-notice]');
    this.threadNotice = root.querySelector('[data-unfold-thread-notice]');
    this.likeButton = root.querySelector('[data-unfold-like-action]');
    this.likeLabel = root.querySelector('[data-unfold-like-label]');
    this.likeCount = root.querySelector('[data-unfold-like-count]');
    this.repostButton = root.querySelector('[data-unfold-repost-action]');
    this.repostLabel = root.querySelector('[data-unfold-repost-label]');
    this.repostCount = root.querySelector('[data-unfold-repost-count]');
    this.threadLive = root.querySelector('[data-unfold-thread-live]');
    this.threadFallback = root.querySelector('[data-unfold-fallback]');
    this.loadMoreButton = root.querySelector('[data-unfold-load-more]');

    try {
      this.storage = window.sessionStorage;
    } catch {
      this.storage = null;
      this.storageAvailable = false;
    }
  }

  async init() {
    if (this.root.dataset.unfoldInteractionsReady === '1') {
      return;
    }

    this.root.dataset.unfoldInteractionsReady = '1';
    window.addEventListener('pagehide', () => {
      this.disposed = true;
      this.stopStatusRefreshLoop();
    }, { once: true });
    this.root.addEventListener('click', (event) => this.onClick(event));
    if (this.composerForm) {
      this.composerForm.addEventListener('submit', (event) => this.onSubmit(event));
    }
    if (this.draftField) {
      this.draftField.addEventListener('input', () => this.onDraftInput());
    }
    if (this.replyCancelButton) {
      this.replyCancelButton.addEventListener('click', (event) => {
        event.preventDefault();
        this.clearReplyTarget();
      });
    }

    this.setGlobalNotice(this.labels.loading || '');
    this.renderComposerState();
    this.updateActionState();
    this.updateCountState();

    await this.loadInitialData();
  }

  async loadInitialData() {
    this.loadingThread = true;
    this.loadingIdentity = true;

    const [threadResult, meResult] = await Promise.allSettled([
      this.fetchThread(),
      this.fetchIdentity(),
    ]);

    if (threadResult.status === 'fulfilled') {
      this.applyThread(threadResult.value);
    } else {
      this.setThreadNotice(this.labels.unavailable || threadResult.reason?.message || '');
    }

    if (meResult.status === 'fulfilled') {
      this.applyIdentity(meResult.value);
    } else {
      this.setGlobalNotice(this.labels.unavailable || meResult.reason?.message || '');
    }

    this.identityResolved = meResult.status === 'fulfilled';
    this.restoreDraft();
    this.restorePendingRecords();
    this.reconcileStoredRecords();
    await this.refreshPendingStatuses(true);
    this.renderThread();
    this.renderComposerState();
    this.updateActionState();
    this.updateCountState();
    this.scheduleStatusRefresh();

    if (this.threadLoaded) {
      this.root.classList.add('is-ready');
    }

    this.loadingThread = false;
    this.loadingIdentity = false;
  }

  async fetchThread(cursor = null) {
    const url = buildUrl(this.commentsUrl, {
      coordinate: this.coordinate,
      cursor,
    });

    const response = await fetch(url, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    });
    const data = await response.json().catch(() => null);

    if (!response.ok) {
      const error = new Error(data?.error || this.labels.unavailable || `HTTP ${response.status}`);
      error.status = response.status;
      error.key = data?.error_key;
      throw error;
    }

    return this.validateThreadResponse(data);
  }

  async fetchIdentity() {
    const url = buildUrl(this.meUrl, {
      coordinate: this.coordinate,
    });

    const response = await fetch(url, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    });
    const data = await response.json().catch(() => null);

    if (!response.ok) {
      const error = new Error(data?.error || this.labels.unavailable || `HTTP ${response.status}`);
      error.status = response.status;
      error.key = data?.error_key;
      throw error;
    }

    return this.validateIdentityResponse(data);
  }

  validateThreadResponse(data) {
    if (!isPlainObject(data) || !Array.isArray(data.comments)) {
      throw this.makeProtocolError('unfold_interactions.invalid_response');
    }

    return {
      comments: data.comments,
      comments_count: Number.isFinite(Number(data.comments_count)) ? Number(data.comments_count) : data.comments.length,
      next_cursor: typeof data.next_cursor === 'string' ? data.next_cursor : '',
      refresh_status: typeof data.refresh_status === 'string' ? data.refresh_status : '',
    };
  }

  validateIdentityResponse(data) {
    if (!isPlainObject(data) || (data.pubkey !== null && !/^[a-f0-9]{64}$/.test(data.pubkey || ''))
      || typeof data.login_url !== 'string'
      || (data.pubkey !== null && (typeof data.csrf_token !== 'string' || data.csrf_token === ''))
      || ['liked', 'reposted', 'repost_available'].some((key) => typeof data[key] !== 'boolean')
      || ['likes', 'reposts'].some((key) => !Number.isInteger(data[key]) || data[key] < 0)) {
      throw this.makeProtocolError('unfold_interactions.invalid_response');
    }

    return {
      pubkey: data.pubkey,
      login_url: typeof data.login_url === 'string' ? data.login_url : '',
      csrf_token: typeof data.csrf_token === 'string' ? data.csrf_token : '',
      liked: Boolean(data.liked),
      reposted: Boolean(data.reposted),
      likes: Number.isFinite(Number(data.likes)) ? Number(data.likes) : 0,
      reposts: Number.isFinite(Number(data.reposts)) ? Number(data.reposts) : 0,
      repost_available: data.repost_available !== false,
    };
  }

  validatePrepareResponse(data) {
    if (!isPlainObject(data)) {
      throw this.makeProtocolError('unfold_interactions.invalid_response');
    }

    if (data.unchanged) {
      return { unchanged: true };
    }

    if (!isPlainObject(data.event) || data.event.pubkey !== this.accountPubkey
      || !Number.isInteger(data.event.kind) || !Number.isInteger(data.event.created_at)
      || !Array.isArray(data.event.tags) || typeof data.event.content !== 'string'
      || !/^[a-f0-9]{64}$/.test(data.target_event_id || '')) {
      throw this.makeProtocolError('unfold_interactions.invalid_response');
    }

    return {
      event: data.event,
      target_event_id: typeof data.target_event_id === 'string' ? data.target_event_id : '',
      unchanged: false,
    };
  }

  validateDeliveryResponse(data) {
    if (!isPlainObject(data) || !['queued', 'published', 'partial', 'failed'].includes(data.status)
      || !/^[a-f0-9]{64}$/.test(data.event_id || '')
      || ['local_commit', 'published', 'relay_complete', 'retryable'].some((key) => typeof data[key] !== 'boolean')) {
      throw this.makeProtocolError('unfold_interactions.invalid_response');
    }

    return {
      status: data.status,
      local_commit: Boolean(data.local_commit),
      published: Boolean(data.published),
      relay_complete: Boolean(data.relay_complete),
      retryable: Boolean(data.retryable),
      relay_results: isPlainObject(data.relay_results) ? data.relay_results : {},
      error: typeof data.error === 'string' ? data.error : '',
      event_id: typeof data.event_id === 'string' ? data.event_id : '',
    };
  }

  makeProtocolError(key) {
    const error = new Error(this.labels.unavailable || 'Unexpected response');
    error.key = key;
    return error;
  }

  applyThread(data) {
    this.serverComments = Array.isArray(data.comments) ? data.comments : [];
    this.threadCursor = safeString(data.next_cursor, '') || null;
    this.totalCommentCount = safeNumber(data.comments_count, this.serverComments.length);
    this.threadLoaded = true;

    if (data.refresh_status === 'unavailable' && this.serverComments.length > 0) {
      this.setThreadNotice(this.labels.unavailable || '');
    } else if (data.refresh_status === 'unavailable') {
      this.setThreadNotice(this.labels.unavailable || '');
    } else {
      this.setThreadNotice('');
    }
  }

  applyIdentity(data) {
    this.accountPubkey = safeString(data.pubkey, '') || null;
    this.loginUrl = safeString(data.login_url, '') || null;
    this.csrfToken = safeString(data.csrf_token, '') || null;
    this.liked = Boolean(data.liked);
    this.reposted = Boolean(data.reposted);
    this.likesCount = safeNumber(data.likes, 0);
    this.repostsCount = safeNumber(data.reposts, 0);
    this.repostAvailable = data.repost_available !== false;
    this.remoteSignerSession = Boolean(getRemoteSignerSession());

    if (!this.accountPubkey) {
      this.setComposerNotice(this.labels.login_required || '');
      this.showLoginControls();
      return;
    }

    this.showAuthenticatedControls();
  }

  showLoginControls() {
    if (this.submitButton) {
      this.submitButton.hidden = true;
    }
    if (this.loginButton) {
      this.loginButton.hidden = false;
      if (this.loginUrl) {
        this.loginButton.href = this.loginUrl;
      }
    }
  }

  showAuthenticatedControls() {
    if (this.submitButton) {
      this.submitButton.hidden = false;
    }
    if (this.loginButton) {
      this.loginButton.hidden = true;
    }
  }

  restoreDraft() {
    if (!this.identityResolved) {
      return;
    }

    const accountKey = this.draftStorageKey(this.accountPubkey);
    let stored = this.readStorage(accountKey);
    if (!stored && this.accountPubkey) {
      const anonKey = this.draftStorageKey(null);
      const anonStored = this.readStorage(anonKey);
      if (anonStored?.loginContinuation === true) {
        stored = anonStored;
        if (this.writeStorage(accountKey, anonStored)) this.removeStorage(anonKey);
      }
    }

    if (!stored) {
      return;
    }

    const content = safeString(stored.content, '');
    if (this.draftField && this.draftField.value === '') {
      this.draftField.value = content;
    }
    if (stored.reply && !this.replyTarget?.id) {
      this.replyTarget = {
        id: safeString(stored.reply.id, '') || null,
        author: safeString(stored.reply.author, '') || null,
        time: safeString(stored.reply.time, '') || null,
      };
      this.renderReplyPreview();
    }
  }

  restorePendingRecords() {
    if (!this.accountPubkey) {
      return;
    }

    const history = this.readHistory();
    if (!history) {
      return;
    }

    Object.entries(history.records).forEach(([eventId, rawRecord]) => {
      const record = this.normalizeStoredRecord(rawRecord, eventId);
      if (!record || record.accountPubkey !== this.accountPubkey) {
        return;
      }

      if (record.action === 'like' && this.liked) {
        record.countApplied = true;
      }
      if (record.action === 'repost' && this.reposted) {
        record.countApplied = true;
      }

      this.pendingRecords.set(record.eventId, record);
      this.localComments.set(record.eventId, this.recordToComment(record));
    });

    Object.entries(history.latest).forEach(([action, eventId]) => {
      if (this.pendingRecords.has(eventId)) {
        this.latestRecordIds.set(action, eventId);
      }
    });
  }

  reconcileStoredRecords() {
    if (this.localComments.size > 0 && !this.threadLoaded) {
      this.threadLoaded = true;
      this.root.classList.add('is-ready');
    }
  }

  async refreshPendingStatuses(includeRejected = false) {
    const records = [...this.pendingRecords.values()].filter((record) => {
      const status = safeString(record?.status, '');
      return PENDING_STATUSES.has(status) || (status === 'failed' && (record?.retryable || includeRejected));
    });
    if (records.length === 0) {
      this.stopStatusRefreshLoop();
      return;
    }

    for (const record of records) {
      if (!record || !record.eventId) {
        continue;
      }
      if (record.accountPubkey && this.accountPubkey && record.accountPubkey !== this.accountPubkey) {
        continue;
      }

      try {
        const status = await this.fetchStatus(record.eventId);
        if (status) {
          this.applyDeliveryResult(record.action, record, status);
        } else {
          record.retryable = true;
          record.error = this.labels.unavailable || record.error || '';
          this.saveRecord(record);
        }
      } catch (error) {
        this.handleActionError(record.action, error, record);
      }
    }
    this.renderThread();
    this.renderComposerState();
    this.updateActionState();
    this.updateCountState();

    if (this.hasRefreshableRecords()) {
      this.scheduleStatusRefresh();
    } else {
      this.stopStatusRefreshLoop();
    }
  }

  async fetchStatus(eventId) {
    const url = buildUrl(this.statusUrl, {
      coordinate: this.coordinate,
      event_id: eventId,
    });

    const response = await fetch(url, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    });
    const data = await response.json().catch(() => null);

    if (response.status === 404 && data?.error_key === 'unfold_interactions.delivery_unavailable') {
      return null;
    }

    if (!response.ok) {
      const error = new Error(data?.error || this.labels.unavailable || `HTTP ${response.status}`);
      error.status = response.status;
      error.key = data?.error_key;
      throw error;
    }

    return this.validateDeliveryResponse(data);
  }

  async onSubmit(event) {
    event.preventDefault();
    const action = this.replyTarget?.id ? 'reply' : 'comment';

    if (!this.accountPubkey) {
      this.saveDraft();
      this.goToLogin();
      return;
    }

    if (this.isActionLocked(action)) {
      this.setComposerNotice(this.labels.pending || '');
      return;
    }

    if (this.hasBlockingComposerRecord(action)) {
      this.setComposerNotice(this.labels.pending || this.labels.saved || '');
      return;
    }

    const content = this.getDraftValue();
    if (action === 'comment' && !content.trim()) {
      this.setComposerNotice(this.labels.invalid_comment || '');
      return;
    }
    if (action === 'reply' && !this.replyTarget?.id) {
      this.setComposerNotice(this.labels.invalid_parent || '');
      return;
    }

    await this.publishInteraction(action, content);
  }

  async publishInteraction(action, content) {
    if (!this.beginActionLock(action)) {
      this.setComposerNotice(this.labels.pending || '');
      return;
    }

    let record = null;
    const parent = this.replyTarget ? { ...this.replyTarget } : null;
    try {
      const signer = await this.acquireSigner();
      if (!signer) {
        return;
      }

      this.setComposerNotice(this.labels.signing || '');

      const prepareBody = {
        coordinate: this.coordinate,
        action,
        content: COMMENT_ACTIONS.has(action) ? content : undefined,
        _token: this.csrfToken,
      };

      if (action === 'reply' && parent?.id) {
        prepareBody.parent_id = parent.id;
      }

      const prepared = this.validatePrepareResponse(await this.postJson(this.prepareUrl, prepareBody));
      if (prepared.unchanged) {
        this.applyAuthoritativeActionState(action, true);
        this.updateActionState();
        this.setComposerNotice(this.labels.saved || '');
        return;
      }

      const signedEvent = await signer.signEvent(prepared.event);

      record = this.buildRecord(action, content, signedEvent, prepared, parent);
      if (!this.saveRecord(record)) {
        this.setComposerNotice(this.labels.storage_unavailable || '');
        return;
      }
      this.statusRefreshAttempts = 0;

      const publishBody = {
        coordinate: this.coordinate,
        action,
        _token: this.csrfToken,
        event: signedEvent,
      };

      if (action === 'reply' && record.parentId) {
        publishBody.parent_id = record.parentId;
      }

      const delivery = this.validateDeliveryResponse(await this.postJson(this.publishUrl, publishBody));
      this.applyDeliveryResult(action, record, delivery);
    } catch (error) {
      this.handleActionError(action, error, record);
    } finally {
      this.releaseActionLock(action);
      this.renderThread();
      this.renderComposerState();
      this.updateActionState();
      this.updateCountState();
      this.scheduleStatusRefresh();
    }
  }

  async publishLike() {
    if (!this.accountPubkey) {
      this.goToLogin();
      return;
    }

    if (this.liked || this.hasBlockingRecord('like') || this.isActionLocked('like')) {
      return;
    }

    await this.publishNonComment('like');
  }

  async publishRepost() {
    if (!this.accountPubkey) {
      this.goToLogin();
      return;
    }

    if (this.reposted || !this.repostAvailable || this.hasBlockingRecord('repost') || this.isActionLocked('repost')) {
      return;
    }

    if (!window.confirm(this.labels.confirm_repost || '')) {
      return;
    }

    await this.publishNonComment('repost');
  }

  async publishNonComment(action) {
    if (!this.beginActionLock(action)) {
      this.setActionNotice(this.labels.pending || '');
      return;
    }

    let record = null;
    try {
      const signer = await this.acquireSigner();
      if (!signer) {
        return;
      }

      this.setActionNotice(this.labels.signing || '');

      const prepared = this.validatePrepareResponse(await this.postJson(this.prepareUrl, {
        coordinate: this.coordinate,
        action,
        _token: this.csrfToken,
      }));

      if (prepared.unchanged) {
        this.applyAuthoritativeActionState(action, true);
        this.updateActionState();
        this.setActionNotice(this.labels.saved || '');
        return;
      }

      const signedEvent = await signer.signEvent(prepared.event);

      record = this.buildRecord(action, '', signedEvent, prepared);
      if (!this.saveRecord(record)) {
        this.setActionNotice(this.labels.storage_unavailable || '');
        return;
      }
      this.statusRefreshAttempts = 0;

      const delivery = this.validateDeliveryResponse(await this.postJson(this.publishUrl, {
        coordinate: this.coordinate,
        action,
        _token: this.csrfToken,
        event: signedEvent,
      }));
      this.applyDeliveryResult(action, record, delivery);
    } catch (error) {
      this.handleActionError(action, error, record);
    } finally {
      this.releaseActionLock(action);
      this.renderThread();
      this.updateActionState();
      this.updateCountState();
      this.scheduleStatusRefresh();
    }
  }

  applyDeliveryResult(action, record, delivery) {
    if (delivery.event_id !== record.eventId) {
      throw this.makeProtocolError('unfold_interactions.invalid_response');
    }
    const newlyAcceptedComment = COMMENT_ACTIONS.has(action) && !record.localCommit && delivery.local_commit;
    record.status = safeString(delivery.status, 'queued') || 'queued';
    record.localCommit = Boolean(delivery.local_commit);
    record.published = Boolean(delivery.published);
    record.relayComplete = Boolean(delivery.relay_complete);
    record.retryable = Boolean(delivery.retryable);
    record.relayResults = delivery.relay_results || {};
    record.error = safeString(delivery.error, '') || null;
    if (record.localCommit) {
      if (action === 'like') {
        if (!this.liked) this.likesCount += 1;
        this.liked = true;
      }
      if (action === 'repost') {
        if (!this.reposted) this.repostsCount += 1;
        this.reposted = true;
      }
      record.countApplied = true;
    }

    if (!record.retryable && record.status === 'failed' && !delivery.published) {
      this.removeSignedPayload(record);
    }

    this.saveRecord(record);
    this.localComments.set(record.eventId, this.recordToComment(record));
    if (newlyAcceptedComment) {
      this.fetchThread().then((data) => {
        this.totalCommentCount = data.comments_count;
        const comments = new Map(this.serverComments.map((item) => [item.id, item]));
        data.comments.forEach((item) => comments.set(item.id, item));
        this.serverComments = [...comments.values()];
        this.renderThread();
        this.updateCountState();
      }).catch((error) => this.setThreadNotice(error.message || this.labels.unavailable || ''));
    }

    if (COMMENT_ACTIONS.has(action) && record.localCommit) {
      this.maybeClearSubmittedDraft(record);
    }

    if (delivery.status === 'published') {
      this.pendingHint = this.labels.published || '';
    } else if (delivery.status === 'partial') {
      this.pendingHint = this.labels.partial || '';
    } else if (delivery.status === 'failed') {
      this.pendingHint = delivery.error || this.labels.failed || '';
    } else {
      this.pendingHint = this.labels.queued || '';
    }

    this.setComposerNotice(COMMENT_ACTIONS.has(action) ? this.pendingHint : '');
    this.setActionNotice(!COMMENT_ACTIONS.has(action) ? this.pendingHint : '');

    if (COMMENT_ACTIONS.has(action) && record.localCommit) {
      this.threadLoaded = true;
      this.root.classList.add('is-ready');
    }
  }

  async retryRecord(action, eventId = null) {
    const record = eventId
      ? this.getRecord(eventId)
      : this.getLatestRecord(action);
    if (!record || !record.eventId) {
      return;
    }

    const retryAction = record.action || action;
    const retryBody = {
      coordinate: this.coordinate,
      action: retryAction,
      _token: this.csrfToken,
    };

    if (retryAction === 'reply' && record.parentId) {
      retryBody.parent_id = record.parentId;
    }

    if (!record.event && !record.localCommit) {
      this.setActionNotice(this.labels.unavailable || '');
      return;
    }

    if (!this.beginActionLock(`retry:${record.eventId}`)) {
      return;
    }

    this.statusRefreshAttempts = 0;

    try {
      const status = await this.fetchStatus(record.eventId);
      if (!status) {
        if (!record.event) {
          const error = new Error(this.labels.delivery_unavailable || this.labels.unavailable || '');
          error.status = 404;
          throw error;
        }
        const delivery = this.validateDeliveryResponse(await this.postJson(this.publishUrl, {
          ...retryBody,
          event: record.event,
        }));
        this.applyDeliveryResult(retryAction, record, delivery);
      } else if (status.status === 'published' || (status.status === 'failed' && !status.retryable)) {
        this.applyDeliveryResult(retryAction, record, status);
      } else {
        const delivery = this.validateDeliveryResponse(await this.postJson(this.retryUrl, {
          coordinate: this.coordinate,
          event_id: record.eventId,
          _token: this.csrfToken,
        }));
        this.applyDeliveryResult(retryAction, record, delivery);
      }
    } catch (error) {
      this.handleActionError(retryAction, error, record);
    } finally {
      this.releaseActionLock(`retry:${record.eventId}`);
      this.updateActionState();
      this.updateCountState();
      this.renderThread();
      this.scheduleStatusRefresh();
    }
  }

  buildRecord(action, content, signedEvent, prepared, parent = this.replyTarget) {
    return {
      action,
      accountPubkey: this.accountPubkey,
      coordinate: this.coordinate,
      eventId: signedEvent.id,
      event: signedEvent,
      content,
      createdAt: signedEvent.created_at,
      parentId: action === 'reply' ? parent?.id || null : null,
      parentAuthor: action === 'reply' ? parent?.author || null : null,
      parentTime: action === 'reply' ? parent?.time || null : null,
      status: 'pending',
      localCommit: false,
      published: false,
      relayComplete: false,
      retryable: false,
      relayResults: {},
      error: null,
      targetEventId: prepared.target_event_id || null,
      countApplied: false,
    };
  }

  recordToComment(record) {
    if (!record) {
      return null;
    }

    const status = safeString(record.status, 'pending') || 'pending';
    const isPending = ['pending', 'queued', 'partial', 'failed'].includes(status);
    const authorName = this.formatAuthorName(record.accountPubkey);
    const actionLabel = record.action === 'like'
      ? (this.labels.liked || this.labels.like || 'Liked')
      : record.action === 'repost'
        ? (this.labels.reposted || this.labels.repost || 'Reposted')
        : '';
    const contentHtml = COMMENT_ACTIONS.has(record.action) && record.content
      ? this.renderPlainText(record.content)
      : actionLabel ? `<p>${actionLabel}</p>` : '';

    return {
      id: record.eventId,
      kind: record.action === 'like' ? 7 : record.action === 'repost' ? 16 : 1111,
      action: record.action,
      pubkey: record.accountPubkey,
      content_html: contentHtml,
      content: record.content,
      created_at: record.createdAt,
      created_at_formatted: formatTimestamp(record.createdAt),
      author: {
        name: authorName,
        pic: null,
        pubkey: record.accountPubkey,
        url: record.authorUrl || null,
      },
      parent_id: record.parentId || null,
      is_zap: false,
      is_local_pending: isPending,
      status,
      retryable: Boolean(record.retryable),
      error: record.error || null,
      published: Boolean(record.published),
      reply_parent_unavailable: Boolean(record.parentId && !this.findComment(record.parentId)),
      parent_author: record.parentAuthor || null,
      parent_time: record.parentTime || null,
      zap_amount: record.zapAmount || null,
    };
  }

  renderThread() {
    if (!this.threadLive || !this.threadFallback) {
      return;
    }

    const comments = this.visibleComments();
    this.threadLive.replaceChildren();

    if (comments.length === 0) {
      if (!this.threadLoaded) {
        this.threadFallback.hidden = false;
        this.threadLive.hidden = true;
      } else {
        this.threadFallback.hidden = true;
        this.threadLive.hidden = false;
        const empty = createElement('p', 'unfold-interactions__empty', this.labels.empty || '');
        this.threadLive.appendChild(empty);
      }
      return;
    }

    const tree = this.buildTree(comments);
    const list = this.renderCommentList(tree, 0);
    this.threadLive.appendChild(list);
    this.threadLive.hidden = false;
    this.threadFallback.hidden = true;

    if (this.threadCursor) {
      this.loadMoreButton.hidden = false;
    } else {
      this.loadMoreButton.hidden = true;
    }
  }

  visibleComments() {
    const combined = this.serverComments.map((comment) => {
      const record = this.pendingRecords.get(comment.id);
      return record ? {
        ...comment, action: record.action, status: record.status,
        retryable: record.retryable, error: record.error, published: record.published,
      } : comment;
    });
    for (const comment of this.localComments.values()) {
      if (!combined.some((item) => item.id === comment.id)) {
        combined.push(comment);
      }
    }
    return combined;
  }

  buildTree(items) {
    const nodes = new Map();
    const roots = [];

    items.forEach((item) => {
      nodes.set(item.id, { ...item, children: [] });
    });

    for (const node of nodes.values()) {
      const parentId = safeString(node.parent_id, '') || null;
      if (parentId && nodes.has(parentId)) {
        nodes.get(parentId).children.push(node);
      } else {
        roots.push(node);
      }
    }

    const sortChildren = (list, depth = 0) => {
      list.sort((a, b) => {
        if (depth === 0) {
          return Number(b.created_at || 0) - Number(a.created_at || 0);
        }
        return Number(a.created_at || 0) - Number(b.created_at || 0);
      });
      list.forEach((node) => sortChildren(node.children, depth + 1));
    };
    sortChildren(roots, 0);

    return roots;
  }

  renderCommentList(nodes, depth) {
    const list = createElement('ol', 'unfold-interactions__list');
    nodes.forEach((node) => {
      list.appendChild(this.renderCommentItem(node, depth));
    });
    return list;
  }

  renderCommentItem(comment, depth) {
    const listItem = createElement('li', 'unfold-interactions__item');
    const normalizedDepth = Math.min(depth, this.maxDepth - 1);
    const article = createElement(
      'article',
      `unfold-interactions__comment unfold-interactions__comment--depth-${normalizedDepth}${comment.is_local_pending ? ' unfold-interactions__comment--pending' : ''}`,
    );
    article.dataset.commentId = comment.id;
    if (comment.parent_id) {
      article.dataset.parentId = comment.parent_id;
    }

    const header = createElement('header', 'unfold-interactions__comment-meta');
    if (comment.author?.pic) {
      const avatar = createElement('img', 'unfold-interactions__avatar');
      avatar.src = comment.author.pic;
      avatar.alt = comment.author.name || '';
      avatar.loading = 'lazy';
      header.appendChild(avatar);
    }

    const authorWrap = createElement('div', 'unfold-interactions__comment-author-wrap');
    if (comment.author?.url) {
      const author = createElement('a', 'unfold-interactions__comment-author', comment.author.name || this.formatAuthorName(comment.pubkey));
      author.href = comment.author.url;
      author.rel = 'noopener noreferrer';
      authorWrap.appendChild(author);
    } else {
      authorWrap.appendChild(createElement('span', 'unfold-interactions__comment-author', comment.author?.name || this.formatAuthorName(comment.pubkey)));
    }

    const time = createElement('p', 'unfold-interactions__comment-time', comment.created_at_formatted || formatTimestamp(comment.created_at));
    authorWrap.appendChild(time);
    header.appendChild(authorWrap);
    article.appendChild(header);

    if (comment.is_zap) {
      const zap = createElement('p', 'unfold-interactions__comment-zap');
      const parts = [this.labels.zap || 'Zap'];
      if (comment.zap_amount) {
        parts.push(String(comment.zap_amount), this.labels.sats || 'sat');
      }
      zap.textContent = parts.join(' · ');
      article.appendChild(zap);
    }

    const body = createElement('div', 'unfold-interactions__comment-content');
    if (comment.is_local_pending && !comment.published && comment.kind === 1111) {
      body.classList.add('unfold-interactions__comment-content--draft');
      body.textContent = comment.content || '';
    } else if (comment.content_html) {
      body.innerHTML = comment.content_html || '';
    } else if (comment.action === 'like' || comment.action === 'repost') {
      body.textContent = comment.action === 'like'
        ? (this.labels.liked || this.labels.like || 'Liked')
        : (this.labels.reposted || this.labels.repost || 'Reposted');
    } else {
      body.textContent = '';
    }
    article.appendChild(body);

    if (comment.parent_id && !this.findComment(comment.parent_id)) {
      const parentNote = createElement('p', 'unfold-interactions__parent-note', this.labels.parent_unavailable || '');
      article.appendChild(parentNote);
    }

    const meta = createElement('div', 'unfold-interactions__comment-actions');
    if (comment.kind === 1111 && !comment.is_zap) {
      const replyButton = createElement('button', 'unfold-interactions__button');
      replyButton.type = 'button';
      replyButton.dataset.unfoldReplyTarget = comment.id;
      replyButton.dataset.unfoldReplyAuthor = comment.author?.name || this.formatAuthorName(comment.pubkey);
      replyButton.dataset.unfoldReplyTime = comment.created_at_formatted || formatTimestamp(comment.created_at);
      replyButton.textContent = this.labels.reply || 'Reply';
      meta.appendChild(replyButton);
    }

    if (comment.retryable && comment.status && comment.status !== 'published') {
      const retryButton = createElement('button', 'unfold-interactions__button');
      retryButton.type = 'button';
      retryButton.dataset.unfoldRetryEvent = comment.id;
      retryButton.dataset.unfoldRetryAction = comment.action || (comment.parent_id ? 'reply' : 'comment');
      retryButton.textContent = this.labels.retry || 'Retry';
      meta.appendChild(retryButton);
    }

    if (meta.childNodes.length > 0) {
      article.appendChild(meta);
    }

    const status = this.commentStatusLabel(comment);
    if (status) {
      const badge = createElement('p', 'unfold-interactions__comment-state', status);
      article.appendChild(badge);
    }

    if (Array.isArray(comment.children) && comment.children.length > 0) {
      article.appendChild(this.renderCommentList(comment.children, depth + 1));
    }

    listItem.appendChild(article);
    return listItem;
  }

  findComment(id) {
    return this.visibleComments().find((comment) => comment.id === id) || null;
  }

  commentStatusLabel(comment) {
    if (!comment.status) {
      return '';
    }

    switch (comment.status) {
      case 'queued':
        return this.labels.queued || '';
      case 'partial':
        return this.labels.partial || '';
      case 'failed':
        return comment.error || this.labels.failed || '';
      case 'published':
        return this.labels.published || '';
      case 'pending':
        return this.labels.pending || '';
      default:
        return '';
    }
  }

  renderComposerState() {
    if (!this.composerForm || !this.draftField) {
      return;
    }

    const action = this.replyTarget?.id ? 'reply' : 'comment';
    const record = this.getLatestRecord(action);
    const blocking = this.hasBlockingComposerRecord(action);

    this.draftField.disabled = false;
    if (this.submitButton) {
      this.submitButton.hidden = !this.accountPubkey;
      this.submitButton.disabled = !this.accountPubkey || blocking || !this.canPublishCurrentDraft(action, record);
      this.submitButton.textContent = action === 'reply'
        ? (this.labels.reply || 'Reply')
        : (this.labels.publish_comment || 'Post comment');
    }

    if (this.loginButton) {
      this.loginButton.hidden = Boolean(this.accountPubkey);
      if (this.loginUrl) {
        this.loginButton.href = this.loginUrl;
      }
    }

    if (!this.accountPubkey) {
      this.setComposerNotice(this.labels.login_required || '');
      return;
    }

    if (record && blocking) {
      this.setComposerNotice(this.commentStatusLabel(this.recordToComment(record)) || this.labels.pending || '');
      return;
    }

    this.renderReplyPreview();
  }

  renderReplyPreview() {
    if (!this.replyPreview || !this.replyAuthor || !this.replyMeta) {
      return;
    }

    if (!this.replyTarget?.id) {
      this.replyPreview.hidden = true;
      this.replyAuthor.textContent = '';
      this.replyMeta.textContent = '';
      return;
    }

    this.replyPreview.hidden = false;
    this.replyAuthor.textContent = `${this.labels.replying_to || 'Replying to'} ${this.replyTarget.author || this.formatAuthorName(this.replyTarget.id)}`;
    this.replyMeta.textContent = this.replyTarget.time ? ` · ${this.replyTarget.time}` : '';
  }

  updateActionState() {
    if (this.likeButton) {
      this.likeButton.disabled = !this.identityResolved || this.liked || this.hasBlockingRecord('like');
      this.likeButton.setAttribute('aria-pressed', this.liked ? 'true' : 'false');
    }

    if (this.likeLabel) {
      this.likeLabel.textContent = this.liked ? (this.labels.liked || 'Liked') : (this.labels.like || 'Like');
    }

    if (this.likeCount) {
      const display = this.likesCount > 0 ? String(this.likesCount) : '';
      this.likeCount.textContent = display;
      this.likeCount.hidden = display === '';
    }

    if (this.repostButton) {
      this.repostButton.disabled = !this.identityResolved || this.reposted || !this.repostAvailable || this.hasBlockingRecord('repost');
      this.repostButton.setAttribute('aria-pressed', this.reposted ? 'true' : 'false');
      if (!this.repostAvailable) {
        this.repostButton.title = this.labels.repost_unavailable || '';
      } else {
        this.repostButton.removeAttribute('title');
      }
    }

    if (this.repostLabel) {
      this.repostLabel.textContent = this.reposted ? (this.labels.reposted || 'Reposted') : (this.labels.repost || 'Repost');
    }

    if (this.repostCount) {
      const display = this.repostsCount > 0 ? String(this.repostsCount) : '';
      this.repostCount.textContent = display;
      this.repostCount.hidden = display === '';
    }
  }

  updateCountState() {
    if (!this.root) {
      return;
    }

    const countTarget = this.root.querySelector('[data-unfold-count]');
    if (countTarget) {
      countTarget.textContent = String(this.totalCommentCount);
    }

    if (this.loadMoreButton) {
      this.loadMoreButton.hidden = !this.threadCursor || !this.threadLoaded;
      this.loadMoreButton.textContent = this.labels.load_more || 'Load more comments';
    }
  }

  normalizeStoredRecord(record, eventId = null) {
    if (!isPlainObject(record)) {
      return null;
    }

    const normalizedEventId = typeof record.eventId === 'string' ? record.eventId : eventId;
    const action = ACTIONS.includes(record.action) ? record.action : null;
    if (!normalizedEventId || !action || typeof record.accountPubkey !== 'string') {
      return null;
    }

    return {
      ...record,
      eventId: normalizedEventId,
      action,
      accountPubkey: record.accountPubkey,
      status: typeof record.status === 'string' ? record.status : 'pending',
      localCommit: Boolean(record.localCommit),
      published: Boolean(record.published),
      relayComplete: Boolean(record.relayComplete),
      retryable: Boolean(record.retryable),
      relayResults: isPlainObject(record.relayResults) ? record.relayResults : {},
      error: typeof record.error === 'string' ? record.error : null,
      countApplied: Boolean(record.countApplied),
    };
  }

  isActionLocked(action) {
    return this.actionLocks.has(COMMENT_ACTIONS.has(action) ? 'composer' : action);
  }

  beginActionLock(action) {
    const key = COMMENT_ACTIONS.has(action) ? 'composer' : action;
    if (this.actionLocks.has(key)) {
      return false;
    }

    this.actionLocks.add(key);
    return true;
  }

  releaseActionLock(action) {
    this.actionLocks.delete(COMMENT_ACTIONS.has(action) ? 'composer' : action);
  }

  applyAuthoritativeActionState(action, active) {
    if (action === 'like') {
      this.liked = active;
    } else if (action === 'repost') {
      this.reposted = active;
    }
  }

  maybeClearSubmittedDraft(record) {
    if (!record || !COMMENT_ACTIONS.has(record.action)) {
      return;
    }

    const currentDraft = this.getDraftValue();
    const currentReplyId = this.replyTarget?.id || null;
    if (currentDraft !== record.content || currentReplyId !== (record.parentId || null)) {
      return;
    }

    if (this.draftField) {
      this.draftField.value = '';
    }
    this.replyTarget = null;
    this.saveDraft();
    this.renderReplyPreview();
  }

  hasRefreshableRecords() {
    return [...this.pendingRecords.values()].some((record) => {
      const status = safeString(record.status, '');
      return PENDING_STATUSES.has(status) || (status === 'failed' && record.retryable);
    });
  }

  scheduleStatusRefresh() {
    if (this.disposed) return;
    if (!this.hasRefreshableRecords()) {
      this.stopStatusRefreshLoop();
      return;
    }

    if (this.statusRefreshTimer) {
      return;
    }

    if (this.statusRefreshAttempts >= this.statusRefreshLimit) {
      this.pendingRecords.forEach((record) => {
        if (PENDING_STATUSES.has(record.status)) {
          record.retryable = Boolean(record.event) || record.localCommit;
          this.saveRecord(record);
        }
      });
      this.renderThread();
      this.setThreadNotice(this.labels.status_exhausted || this.labels.unavailable || '');
      return;
    }

    this.statusRefreshTimer = window.setTimeout(async () => {
      this.statusRefreshTimer = null;
      this.statusRefreshAttempts += 1;
      await this.refreshPendingStatuses();
      if (this.hasRefreshableRecords()) {
        this.scheduleStatusRefresh();
      }
    }, STATUS_REFRESH_DELAY_MS);
  }

  stopStatusRefreshLoop() {
    if (this.statusRefreshTimer) {
      window.clearTimeout(this.statusRefreshTimer);
      this.statusRefreshTimer = null;
    }
    this.statusRefreshAttempts = 0;
  }

  isRetryableTransportError(error) {
    const status = Number(error?.status);
    if (RETRYABLE_HTTP_STATUSES.has(status)) {
      return true;
    }
    if (!status && error?.message) {
      return true;
    }
    const key = String(error?.key || '');
    return key === 'unfold_interactions.invalid_response' || key === 'unfold_interactions.unavailable';
  }

  async loadMore() {
    if (!this.threadCursor || this.loadingThread) {
      return;
    }

    this.loadingThread = true;
    this.setThreadNotice(this.labels.loading || '');
    try {
      const data = await this.fetchThread(this.threadCursor);
      this.serverComments = [...this.serverComments, ...(Array.isArray(data.comments) ? data.comments : [])];
      this.threadCursor = safeString(data.next_cursor, '') || null;
      this.totalCommentCount = safeNumber(data.comments_count, this.totalCommentCount);
      this.renderThread();
      this.reconcileStoredRecords();
      this.setThreadNotice('');
    } catch (error) {
      this.setThreadNotice(error.message || this.labels.unavailable || '');
    } finally {
      this.loadingThread = false;
      this.updateCountState();
      this.updateActionState();
    }
  }

  hasBlockingRecord(action) {
    const record = this.getLatestRecord(action);
    if (!record) {
      return this.isActionLocked(action);
    }

    return this.isActionLocked(action)
      || ['pending', 'queued', 'partial'].includes(record.status)
      || (record.status === 'failed' && record.retryable);
  }

  canPublishCurrentDraft(action, record) {
    if (!record) {
      return true;
    }

    if (COMMENT_ACTIONS.has(action)) {
      if (record.localCommit) return true;
      const draft = this.getDraftValue();
      const parentId = this.replyTarget?.id || null;
      return record.content !== draft || record.parentId !== parentId;
    }

    return false;
  }

  hasBlockingComposerRecord(action) {
    if (this.isActionLocked(action)) {
      return true;
    }

    return [...this.pendingRecords.values()].some((record) =>
      COMMENT_ACTIONS.has(record.action) && !record.localCommit
      && (PENDING_STATUSES.has(record.status) || (record.status === 'failed' && record.retryable)));
  }

  draftStorageKey(accountPubkey = this.accountPubkey) {
    return ['unfold-interactions', 'draft', window.location.origin, this.coordinate, accountPubkey || 'anon'].join(':');
  }

  historyStorageKey(accountPubkey = this.accountPubkey) {
    return ['unfold-interactions', 'history', window.location.origin, this.coordinate, accountPubkey || 'anon'].join(':');
  }

  readStorage(key) {
    if (!this.storageAvailable || !this.storage) {
      return null;
    }

    try {
      const raw = this.storage.getItem(key);
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  }

  writeStorage(key, value) {
    if (!this.storageAvailable || !this.storage) {
      return false;
    }

    try {
      this.storage.setItem(key, JSON.stringify(value));
      return true;
    } catch {
      this.storageAvailable = false;
      this.setGlobalNotice(this.labels.storage_unavailable || '');
      return false;
    }
  }

  removeStorage(key) {
    if (!this.storageAvailable || !this.storage) {
      return false;
    }

    try {
      this.storage.removeItem(key);
      return true;
    } catch {
      this.storageAvailable = false;
      return false;
    }
  }

  saveDraft(loginContinuation = false) {
    if (!this.identityResolved) return false;
    return this.writeStorage(this.draftStorageKey(), {
      loginContinuation,
      content: this.getDraftValue(),
      reply: this.replyTarget ? {
        id: this.replyTarget.id,
        author: this.replyTarget.author,
        time: this.replyTarget.time,
      } : null,
    });
  }

  saveRecord(record) {
    if (!record?.eventId) {
      return false;
    }

    const records = {};
    this.pendingRecords.forEach((item, eventId) => {
      records[eventId] = item;
    });
    records[record.eventId] = record;

    const latest = {};
    this.latestRecordIds.forEach((eventId, action) => {
      latest[action] = eventId;
    });
    latest[record.action] = record.eventId;

    if (!this.writeStorage(this.historyStorageKey(), { records, latest })) {
      return false;
    }

    this.pendingRecords.set(record.eventId, record);
    this.latestRecordIds.set(record.action, record.eventId);
    this.localComments.set(record.eventId, this.recordToComment(record));
    return true;
  }

  readHistory() {
    const stored = this.readStorage(this.historyStorageKey());
    if (!isPlainObject(stored)) {
      return null;
    }

    return {
      records: isPlainObject(stored.records) ? stored.records : {},
      latest: isPlainObject(stored.latest) ? stored.latest : {},
    };
  }

  persistHistory() {
    const records = {};
    this.pendingRecords.forEach((record, eventId) => {
      records[eventId] = record;
    });

    const latest = {};
    this.latestRecordIds.forEach((eventId, action) => {
      latest[action] = eventId;
    });

    return this.writeStorage(this.historyStorageKey(), { records, latest });
  }

  getLatestRecord(action) {
    const eventId = this.latestRecordIds.get(action);
    return eventId ? (this.pendingRecords.get(eventId) || null) : null;
  }

  getRecord(eventId) {
    return this.pendingRecords.get(eventId) || null;
  }

  removeSignedPayload(record) {
    if (!record) {
      return;
    }

    delete record.event;
    record.retryable = false;
  }

  getDraftValue() {
    return safeString(this.draftField?.value, '');
  }

  onDraftInput() {
    this.saveDraft();
    this.renderComposerState();
  }

  onClick(event) {
    const button = event.target.closest('[data-unfold-reply-target], [data-unfold-retry-event], [data-unfold-load-more], [data-unfold-like-action], [data-unfold-repost-action], [data-unfold-login-button], [data-unfold-connect-signer]');
    if (!button) {
      return;
    }

    if (button.matches('[data-unfold-reply-target]')) {
      event.preventDefault();
      this.setReplyTarget({
        id: button.dataset.unfoldReplyTarget || null,
        author: button.dataset.unfoldReplyAuthor || null,
        time: button.dataset.unfoldReplyTime || null,
      });
      return;
    }

    if (button.matches('[data-unfold-retry-event]')) {
      event.preventDefault();
      this.retryRecord(
        safeString(button.dataset.unfoldRetryAction, '') || 'comment',
        safeString(button.dataset.unfoldRetryEvent, '') || null,
      ).catch(() => {});
      return;
    }

    if (button.matches('[data-unfold-load-more]')) {
      event.preventDefault();
      this.loadMore().catch(() => {});
      return;
    }

    if (button.matches('[data-unfold-like-action]')) {
      event.preventDefault();
      this.publishLike().catch(() => {});
      return;
    }

    if (button.matches('[data-unfold-repost-action]')) {
      event.preventDefault();
      this.publishRepost().catch(() => {});
      return;
    }

    if (button.matches('[data-unfold-login-button]')) {
      event.preventDefault();
      this.saveDraft();
      this.goToLogin();
      return;
    }

  }

  setReplyTarget(reply) {
    this.replyTarget = {
      id: safeString(reply.id, '') || null,
      author: safeString(reply.author, '') || null,
      time: safeString(reply.time, '') || null,
    };
    this.saveDraft();
    this.renderComposerState();
  }

  clearReplyTarget() {
    this.replyTarget = null;
    this.saveDraft();
    this.renderComposerState();
  }

  async acquireSigner() {
    try {
      const signer = await getSigner();
      if (!signer) {
        throw new Error('signer_required');
      }
      if ((await signer.getPublicKey()) !== this.accountPubkey) {
        const error = new Error(this.labels.signer_mismatch || 'signer_mismatch');
        error.key = 'unfold_interactions.signer_mismatch';
        throw error;
      }
      return signer;
    } catch (error) {
      const message = String(error?.message || error || '');
      if (message.includes('No signer available')) {
        this.showSignerPrompt();
      } else if (error.key === 'unfold_interactions.signer_mismatch' || message.includes('signer_mismatch')) {
        this.setComposerNotice(this.labels.signer_mismatch || '');
        document.querySelector('.unfold-reader-signer button')?.focus();
      } else {
        this.setComposerNotice(this.labels.signer_required || message);
        this.showSignerPrompt();
      }
      return null;
    }
  }

  showSignerPrompt() {
    this.setComposerNotice(this.labels.signer_required || '');
    this.setActionNotice(this.labels.signer_required || '');
    const bridgeButton = document.querySelector('.unfold-reader-signer button');
    if (bridgeButton instanceof HTMLElement) {
      bridgeButton.focus();
    }
  }

  goToLogin() {
    if (!this.loginUrl) {
      this.setGlobalNotice(this.labels.login_required || '');
      return;
    }
    if (!this.accountPubkey && (this.getDraftValue() !== '' || this.replyTarget)
      && !this.saveDraft(true)) {
      this.setGlobalNotice(this.labels.storage_unavailable || '');
      return;
    }

    if (window.Turbo?.visit) {
      window.Turbo.visit(this.loginUrl);
      return;
    }

    window.location.assign(this.loginUrl);
  }

  handleActionError(action, error, record = null) {
    const message = String(error?.message || error || '');
    const key = error?.key || '';
    const failureRecord = record || error?.record || null;
    if (message.includes('signer_mismatch') || key === 'unfold_interactions.signer_mismatch') {
      this.setComposerNotice(this.labels.signer_mismatch || '');
      this.showSignerPrompt();
      if (!failureRecord) {
        return;
      }
    }

    if (key === 'unfold_interactions.login_required') {
      this.setComposerNotice(this.labels.login_required || '');
      this.goToLogin();
      if (!failureRecord) {
        return;
      }
    }

    if (key === 'unfold_interactions.invalid_comment') {
      this.setComposerNotice(this.labels.invalid_comment || '');
      if (!failureRecord) {
        return;
      }
    }

    if (key === 'unfold_interactions.invalid_parent') {
      this.setComposerNotice(this.labels.invalid_parent || '');
      if (!failureRecord) {
        return;
      }
    }

    if (key === 'unfold_interactions.repost_unavailable') {
      this.setActionNotice(this.labels.repost_unavailable || '');
      if (!failureRecord) {
        return;
      }
    }

    if (key === 'unfold_interactions.rate_limited') {
      this.setActionNotice(this.labels.rate_limited || '');
      if (!failureRecord) {
        return;
      }
    }

    if (key === 'unfold_interactions.access_denied') {
      this.setGlobalNotice(this.labels.access_denied || '');
      if (!failureRecord) {
        return;
      }
    }

    if (key === 'unfold_interactions.target_unavailable') {
      this.setGlobalNotice(this.labels.target_unavailable || '');
      if (!failureRecord) {
        return;
      }
    }

    if (key === 'unfold_interactions.stale_target') {
      this.setComposerNotice(this.labels.stale_target || '');
      if (!failureRecord) {
        return;
      }
    }

    if (key === 'unfold_interactions.unavailable' && !failureRecord) {
      this.setGlobalNotice(this.labels.unavailable || '');
      return;
    }

    if (failureRecord) {
      const retryable = this.isRetryableTransportError(error) && !NON_RETRYABLE_HTTP_STATUSES.has(Number(error.status));
      failureRecord.status = 'failed';
      failureRecord.retryable = retryable;
      failureRecord.error = message || this.labels.unavailable || '';
      if (!retryable) {
        this.removeSignedPayload(failureRecord);
      }
      this.saveRecord(failureRecord);
      if (!retryable && failureRecord.action === 'reply' && (key === 'unfold_interactions.invalid_parent' || key === 'unfold_interactions.target_unavailable' || key === 'unfold_interactions.stale_target')) {
        this.replyTarget = null;
        this.renderReplyPreview();
      }
    }

    if (action === 'like' || action === 'repost') {
      this.setActionNotice(message || this.labels.unavailable || '');
    } else {
      this.setComposerNotice(message || this.labels.unavailable || '');
    }
  }

  async loadMoreIfNeeded() {
    if (!this.threadCursor) {
      return;
    }
    await this.loadMore();
  }

  async postJson(url, body) {
    const response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(body),
    });
    const data = await response.json().catch(() => null);
    if (!response.ok) {
      const error = new Error(data?.error || this.labels.unavailable || `HTTP ${response.status}`);
      error.status = response.status;
      error.key = data?.error_key;
      error.data = data;
      throw error;
    }
    return data;
  }

  renderPlainText(value) {
    const container = createElement('div', 'unfold-interactions__comment-content unfold-interactions__comment-content--draft');
    container.textContent = value || '';
    return container.outerHTML;
  }

  formatAuthorName(pubkey) {
    if (!pubkey) {
      return this.labels.anonymous || 'Anonymous';
    }
    return `${pubkey.slice(0, 12)}…`;
  }

  setGlobalNotice(message) {
    if (!this.globalNotice) {
      return;
    }
    this.globalNotice.textContent = message || '';
    this.globalNotice.hidden = !message;
  }

  setComposerNotice(message) {
    if (!this.composerNotice) {
      return;
    }
    this.composerNotice.textContent = message || '';
    this.composerNotice.hidden = !message;
  }

  setThreadNotice(message) {
    if (!this.threadNotice) {
      return;
    }
    this.threadNotice.textContent = message || '';
    this.threadNotice.hidden = !message;
  }

  setActionNotice(message) {
    if (!this.globalNotice) {
      return;
    }
    this.globalNotice.textContent = message || '';
    this.globalNotice.hidden = !message;
  }
}

function bootstrap() {
  document.querySelectorAll(ROOT_SELECTOR).forEach((root) => {
    if (root.dataset.unfoldInteractionsReady === '1') {
      return;
    }

    const controller = new UnfoldReaderInteractions(root);
    controller.init().catch((error) => {
      console.error('[unfold-reader-interactions] Failed to initialize', error);
    });
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootstrap, { once: true });
} else {
  bootstrap();
}

document.addEventListener('turbo:load', bootstrap);
document.addEventListener('turbo:frame-load', bootstrap);
