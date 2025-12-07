import { Controller } from '@hotwired/stimulus';
import { getSigner, getRemoteSignerSession } from './signer_manager.js';

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

      // Only try to get pubkey if extension is available
      // Don't attempt remote signer connection during preview (it would timeout)
      if (window.nostr && typeof window.nostr.getPublicKey === 'function') {
        try {
          pubkey = await window.nostr.getPublicKey();
        } catch (_) {}
      } else {
        // If remote signer session exists, show placeholder
        const session = getRemoteSignerSession();
        if (session) {
          pubkey = '<will be obtained from remote signer>';
        }
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
    console.log('[nostr_single_sign] Sign and publish triggered');

    const session = getRemoteSignerSession();
    console.log('[nostr_single_sign] Remote signer session:', session);

    let signer;
    try {
      this.showStatus('Connecting to signer...');
      console.log('[nostr_single_sign] Calling getSigner()...');

      // getSigner() handles caching and reuses existing connection if available
      signer = await getSigner();
      console.log('[nostr_single_sign] Signer obtained successfully');

      // Verify connection works
      const testPubkey = await signer.getPublicKey();
      console.log('[nostr_single_sign] Signer verified, pubkey:', testPubkey);

    } catch (e) {
      console.error('[nostr_single_sign] Failed to get signer:', e);
      const session = getRemoteSignerSession();
      if (session && e.message.includes('unavailable')) {
        this.showError('Amber connection lost. Please use a Nostr browser extension (like nos2x or Alby) to sign, or reconnect Amber from the login page.');
      } else {
        this.showError(`No Nostr signer available: ${e.message}. Please connect Amber or install a Nostr signer extension.`);
      }
      return;
    }

    if (!this.publishUrlValue || !this.csrfTokenValue) {
      console.error('[nostr_single_sign] Missing config', { publishUrl: this.publishUrlValue, csrf: !!this.csrfTokenValue });
      this.showError('Missing config');
      return;
    }

    this.publishButtonTarget.disabled = true;
    try {
      this.showStatus('Preparing event...');
      const pubkey = await signer.getPublicKey();
      console.log('[nostr_single_sign] Public key obtained:', pubkey);

      const skeleton = JSON.parse(this.eventValue || '{}');
      // Update content from textarea if present
      const textarea = this.element.querySelector('textarea');
      if (textarea) {
        skeleton.content = textarea.value;
      }
      this.ensureCreatedAt(skeleton);
      this.ensureContent(skeleton);
      skeleton.pubkey = pubkey;

      this.showStatus('Sending event to signer for signature...');
      console.log('[nostr_single_sign] Signing event:', skeleton);
      const signed = await signer.signEvent(skeleton);
      console.log('[nostr_single_sign] Event signed successfully:', signed);

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
