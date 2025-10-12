import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['status', 'publishButton', 'computedPreview'];
  static values = {
    event: String,
    publishUrl: String,
    csrfToken: String
  };

  async connect() {
    try {
      await this.preparePreview();
    } catch (_) {}
  }

  async preparePreview() {
    try {
      const skeleton = JSON.parse(this.eventValue || '{}');
      let pubkey = '<pubkey>';
      if (window.nostr && typeof window.nostr.getPublicKey === 'function') {
        try { pubkey = await window.nostr.getPublicKey(); } catch (_) {}
      }
      const preview = JSON.parse(JSON.stringify(skeleton));
      preview.pubkey = pubkey;
      // Update content from textarea if present
      const textarea = this.element.querySelector('textarea');
      if (textarea) {
        preview.content = textarea.value;
      }
      if (this.hasComputedPreviewTarget) {
        this.computedPreviewTarget.textContent = JSON.stringify(preview, null, 2);
      }
    } catch (_) {}
  }

  async signAndPublish(event) {
    event.preventDefault();

    if (!window.nostr) {
      this.showError('Nostr extension not found');
      return;
    }
    if (!this.publishUrlValue || !this.csrfTokenValue) {
      this.showError('Missing config');
      return;
    }

    this.publishButtonTarget.disabled = true;
    try {
      const pubkey = await window.nostr.getPublicKey();
      const skeleton = JSON.parse(this.eventValue || '{}');
      // Update content from textarea before signing
      const textarea = this.element.querySelector('textarea');
      if (textarea) {
        skeleton.content = textarea.value;
      }
      this.ensureCreatedAt(skeleton);
      this.ensureContent(skeleton);
      skeleton.pubkey = pubkey;

      this.showStatus('Signing feedback…');
      const signed = await window.nostr.signEvent(skeleton);

      this.showStatus('Publishing…');
      await this.publishSigned(signed);

      this.showSuccess('Published feedback successfully');
    } catch (e) {
      console.error(e);
      this.showError(e.message || 'Publish failed');
    } finally {
      this.publishButtonTarget.disabled = false;
    }
  }

  async publishSigned(signedEvent) {
    const res = await fetch(this.publishUrlValue, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': this.csrfTokenValue,
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({ event: signedEvent })
    });
    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      throw new Error(data.error || `HTTP ${res.status}`);
    }
    return res.json();
  }

  ensureCreatedAt(evt) {
    if (!evt.created_at) evt.created_at = Math.floor(Date.now() / 1000);
  }
  ensureContent(evt) {
    if (typeof evt.content !== 'string') evt.content = '';
  }

  showStatus(message) {
    if (this.hasStatusTarget) {
      this.statusTarget.innerHTML = `<div class="alert alert-info">${message}</div>`;
    }
  }
  showSuccess(message) {
    if (this.hasStatusTarget) {
      this.statusTarget.innerHTML = `<div class="alert alert-success">${message}</div>`;
    }
  }
  showError(message) {
    if (this.hasStatusTarget) {
      this.statusTarget.innerHTML = `<div class="alert alert-danger">${message}</div>`;
    }
  }
}
