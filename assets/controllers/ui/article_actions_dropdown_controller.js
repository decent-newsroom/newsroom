import { Controller } from '@hotwired/stimulus';

/**
 * Overflow actions for article pages.
 *
 * The primary social actions live in ui--article-social-actions. This controller
 * keeps the secondary menu focused on Nostr identifiers, relay broadcast, and
 * highlight visibility.
 */
export default class extends Controller {
  static targets = ['trigger', 'menu'];

  connect() {
    this.menuOpen = false;
    this._closeOnOutsideClick = this._closeOnOutsideClick.bind(this);
  }

  disconnect() {
    document.removeEventListener('click', this._closeOnOutsideClick);
  }

  toggle(event) {
    event.stopPropagation();

    if (this.menuOpen) {
      this.close();
      return;
    }

    this.open();
  }

  open() {
    this.menuTarget.classList.add('show');
    this.menuOpen = true;
    this.triggerTarget.setAttribute('aria-expanded', 'true');

    requestAnimationFrame(() => {
      document.addEventListener('click', this._closeOnOutsideClick);
    });
  }

  close() {
    this.menuTarget.classList.remove('show');
    this.menuOpen = false;
    this.triggerTarget.setAttribute('aria-expanded', 'false');
    document.removeEventListener('click', this._closeOnOutsideClick);
  }

  _closeOnOutsideClick(event) {
    if (!this.element.contains(event.target)) {
      this.close();
    }
  }

  copy(event) {
    event.preventDefault();

    const text = event.currentTarget.dataset.copy;
    if (!text) {
      return;
    }

    navigator.clipboard.writeText(text).then(() => {
      this.toast('Copied!', 'success');
      this.close();
    }).catch(() => {
      this.toast('Failed to copy', 'danger');
    });
  }

  async broadcast(event) {
    return this._doBroadcast(event, null);
  }

  async broadcastEssayist(event) {
    return this._doBroadcast(event, 'Essayist');
  }

  async _doBroadcast(event, targetLabel) {
    event.preventDefault();
    this.close();

    const btn = event.currentTarget;
    const coordinate = btn.dataset.coordinate;
    const articleId = btn.dataset.articleId;
    const label = targetLabel || btn.dataset.targetLabel || null;
    let relays;
    try {
      relays = JSON.parse(btn.dataset.relays || '[]');
    } catch {
      relays = [];
    }

    btn.classList.add('loading');

    try {
      const payload = {};
      if (articleId) {
        payload.article_id = parseInt(articleId, 10);
      }
      if (coordinate) {
        payload.coordinate = coordinate;
      }
      if (relays.length > 0) {
        payload.relays = relays;
      }

      const data = await this.postJSON('/api/broadcast-article', payload);

      if (!data.success) {
        throw new Error(data.error || 'Broadcast failed');
      }

      const where = label ? ` to ${label}` : '';
      this.toast(`Broadcast${where}: ${data.broadcast.successful}/${data.broadcast.total_relays} relays`, 'success', 5000);
    } catch (error) {
      console.error('[article-actions] Broadcast error:', error);
      this.toast(`Broadcast failed: ${error.message}`, 'danger', 5000);
    } finally {
      btn.classList.remove('loading');
    }
  }

  toggleHighlights(event) {
    event.preventDefault();
    this.close();

    const article = this.element.closest('[data-controller*="ui--highlights-toggle"]');
    if (!article) {
      return;
    }

    const btn = article.querySelector('[data-ui--highlights-toggle-target="button"]');
    if (btn) {
      btn.click();
    }
  }

  async postJSON(url, body) {
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify(body),
    });

    const contentType = response.headers.get('content-type');
    if (!contentType || !contentType.includes('application/json')) {
      throw new Error(`Server returned non-JSON response (${response.status})`);
    }

    const data = await response.json();
    if (!response.ok) {
      throw new Error(data.error || `HTTP ${response.status}`);
    }

    return data;
  }

  toast(message, type = 'info', duration = 3000) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, type, duration);
      return;
    }

    console.log(`[article-actions] ${type}: ${message}`);
  }
}
