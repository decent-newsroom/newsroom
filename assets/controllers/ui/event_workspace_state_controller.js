import { Controller } from '@hotwired/stimulus';
import { cache } from '@hotwired/turbo';

const STORAGE_PREFIX = 'event-workspace-state:v1:';
const DISCLOSURE_SELECTOR = 'details[data-event-workspace-disclosure]';
const MAX_RESTORED_DISCLOSURES = 4;
const activeControllers = new Set();
let lastWorkspaceAccount = null;

function clearTurboSnapshots() {
  try {
    cache.clear();
  } catch {
    // Workspace state and navigation must still work if Turbo is unavailable.
  }
}

/**
 * Keeps only disclosure IDs and scroll position for this user's current
 * workspace tab. Disclosure IDs may contain event identifiers; event contents
 * and fetched fragments are never copied into browser storage.
 */
export function clearEventWorkspaceState(storage = null) {
  clearTurboSnapshots();
  lastWorkspaceAccount = null;
  activeControllers.forEach((controller) => {
    controller.storageKey = null;
    controller.cancelScrollRestore();
  });
  try {
    const target = storage ?? window.sessionStorage;
    const keys = [];
    for (let index = 0; index < target.length; index += 1) {
      const key = target.key(index);
      if (key?.startsWith(STORAGE_PREFIX)) keys.push(key);
    }
    keys.forEach((key) => target.removeItem(key));
  } catch {
    // Storage can be disabled or unavailable; state is an optional enhancement.
  }
}

function clearOtherAccountState(user, storage) {
  const currentAccountPrefix = `${STORAGE_PREFIX}${user}:`;
  activeControllers.forEach((controller) => {
    if (controller.storageKey && !controller.storageKey.startsWith(currentAccountPrefix)) {
      controller.storageKey = null;
      controller.cancelScrollRestore();
    }
  });
  try {
    const staleKeys = [];
    for (let index = 0; index < storage.length; index += 1) {
      const key = storage.key(index);
      if (key?.startsWith(STORAGE_PREFIX) && !key.startsWith(currentAccountPrefix)) {
        staleKeys.push(key);
      }
    }
    staleKeys.forEach((key) => storage.removeItem(key));
  } catch {
    // A storage failure must never interfere with workspace navigation.
  }
}

export default class extends Controller {
  static values = {
    user: String,
    context: String,
  };

  connect() {
    activeControllers.add(this);
    this.storageKey = null;
    this.onToggle = this.onToggle.bind(this);
    this.save = this.save.bind(this);

    const user = this.userValue.trim().toLowerCase();
    const context = this.contextValue.trim();
    if (/^[a-z0-9_-]{1,128}$/.test(user) && context !== '') {
      // The first workspace in this document may follow an account change
      // outside the workspace; clear even if sessionStorage is unavailable.
      if (lastWorkspaceAccount !== user) clearTurboSnapshots();
      lastWorkspaceAccount = user;
      try {
        this.storageKey = `${STORAGE_PREFIX}${user}:${context}`;
        clearOtherAccountState(user, window.sessionStorage);
        this.restore();
      } catch {
        this.storageKey = null;
      }
    }

    this.element.addEventListener('toggle', this.onToggle, true);
    window.addEventListener('pagehide', this.save);
  }

  disconnect() {
    this.save();
    activeControllers.delete(this);
    this.cancelScrollRestore();
    this.element.removeEventListener('toggle', this.onToggle, true);
    window.removeEventListener('pagehide', this.save);
  }

  cancelScrollRestore() {
    if (this.scrollFrame !== undefined) {
      window.cancelAnimationFrame(this.scrollFrame);
      this.scrollFrame = undefined;
    }
  }

  onToggle(event) {
    if (event.target?.matches?.(DISCLOSURE_SELECTOR)) this.save();
  }

  restore() {
    if (!this.storageKey) return;

    try {
      const state = JSON.parse(window.sessionStorage.getItem(this.storageKey) || '{}');
      if (!Array.isArray(state.open)) return;

      const openIds = new Set(state.open.filter((id) => typeof id === 'string'));
      let restored = 0;
      this.element.querySelectorAll(DISCLOSURE_SELECTOR).forEach((details) => {
        const id = details.dataset.eventWorkspaceDisclosure;
        if (restored < MAX_RESTORED_DISCLOSURES && openIds.has(id)) {
          details.open = true;
          restored += 1;
        }
      });

      const scrollY = Number(state.scrollY);
      if (Number.isFinite(scrollY) && scrollY > 0) {
        this.scrollFrame = window.requestAnimationFrame(() => {
          this.scrollFrame = undefined;
          window.scrollTo(0, scrollY);
        });
      }
    } catch {
      // Ignore malformed, stale, or unavailable local UI state.
    }
  }

  save() {
    if (!this.storageKey) return;

    try {
      const open = [];
      this.element.querySelectorAll(DISCLOSURE_SELECTOR).forEach((details) => {
        const id = details.dataset.eventWorkspaceDisclosure;
        if (details.open && id && open.length < MAX_RESTORED_DISCLOSURES) open.push(id);
      });
      window.sessionStorage.setItem(this.storageKey, JSON.stringify({
        open,
        scrollY: Math.max(0, Math.round(window.scrollY || 0)),
      }));
    } catch {
      // Storage is optional and may be full or disabled.
    }
  }
}
