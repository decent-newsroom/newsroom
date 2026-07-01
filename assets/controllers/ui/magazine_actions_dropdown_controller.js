import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['menu', 'button'];

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
    this.menuTarget.style.display = 'block';
    this.menuOpen = true;
    this.buttonTarget.setAttribute('aria-expanded', 'true');

    requestAnimationFrame(() => {
      document.addEventListener('click', this._closeOnOutsideClick);
    });
  }

  close() {
    this.menuTarget.style.display = 'none';
    this.menuOpen = false;
    this.buttonTarget.setAttribute('aria-expanded', 'false');
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
      this.toast('Copied!', 'success', 2000);
      this.close();
    }).catch(() => {
      this.toast('Failed to copy', 'danger', 3000);
    });
  }

  async broadcast(event) {
    event.preventDefault();
    this.close();

    const btn = event.currentTarget;
    const eventId = btn.dataset.eventId;
    const coordinate = btn.dataset.coordinate;
    let relays;

    try {
      relays = JSON.parse(btn.dataset.relays || '[]');
    } catch {
      relays = [];
    }

    btn.classList.add('loading');

    try {
      const payload = {};

      if (eventId) {
        payload.event_id = eventId;
      }
      if (coordinate) {
        payload.coordinate = coordinate;
      }
      if (relays.length > 0) {
        payload.relays = relays;
      }

      const data = await this.postJSON('/api/broadcast-publication', payload);

      if (!data.success) {
        throw new Error(data.error || 'Broadcast failed');
      }

      this.toast(`Broadcast: ${data.broadcast.successful}/${data.broadcast.total_relays} relays`, 'success', 5000);
    } catch (error) {
      console.error('[magazine-actions] Broadcast error:', error);
      this.toast(`Broadcast failed: ${error.message}`, 'danger', 5000);
    } finally {
      btn.classList.remove('loading');
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

    console.log(`[magazine-actions] ${type}: ${message}`);
  }
}

