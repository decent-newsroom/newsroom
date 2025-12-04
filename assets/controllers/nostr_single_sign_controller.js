import { Controller } from '@hotwired/stimulus';
import { getSigner } from './signer_manager.js';

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
      try {
        const signer = await getSigner();
        pubkey = await signer.getPublicKey();
      } catch (_) {}
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
    console.log('[nostr_single_sign] Sign and publish triggered');

    let signer;
    try {
      this.showStatus('Connecting to signer...');
      signer = await getSigner();
      console.log('[nostr_single_sign] Signer obtained successfully');
    } catch (e) {
      console.error('[nostr_single_sign] Failed to get signer:', e);
      this.showError(`No Nostr signer available: ${e.message}. Please connect Amber or install a Nostr signer extension.`);
      return;
    }
    if (!this.publishUrlValue || !this.csrfTokenValue) {
      console.error('[nostr_single_sign] Missing config', { publishUrl: this.publishUrlValue, csrf: !!this.csrfTokenValue });
      this.showError('Missing config');
      return;
    }

    this.publishButtonTarget.disabled = true;
    try {
      this.showStatus('Getting public key...');
      const pubkey = await signer.getPublicKey();
      console.log('[nostr_single_sign] Public key obtained:', pubkey);

      const skeleton = JSON.parse(this.eventValue || '{}');
      // Update content from textarea before signing
      const textarea = this.element.querySelector('textarea');
      if (textarea) {
        skeleton.content = textarea.value;
      }
      this.ensureCreatedAt(skeleton);
      this.ensureContent(skeleton);
      skeleton.pubkey = pubkey;

      this.showStatus('Signing event…');
      console.log('[nostr_single_sign] Signing event:', skeleton);
      const signed = await signer.signEvent(skeleton);
      console.log('[nostr_single_sign] Event signed successfully');

      this.showStatus('Publishing…');
      await this.publishSigned(signed);
      console.log('[nostr_single_sign] Event published successfully');

      this.showSuccess('Published successfully! Redirecting...');

      // Redirect to reading list index after successful publish
      setTimeout(() => {
        window.location.href = '/reading-list';
      }, 1500);
    } catch (e) {
      console.error('[nostr_single_sign] Error during sign/publish:', e);
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
