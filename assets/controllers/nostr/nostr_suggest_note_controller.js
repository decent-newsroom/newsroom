import { Controller } from '@hotwired/stimulus';
import { encodeNaddr } from '../../typescript/nostr-utils.ts';
import { getRemoteSignerSession } from './signer_manager.js';

/**
 * Suggests publishing a kind 1 note after an article is published.
 * Shown on the article page via a flash-message-driven banner.
 * Opens a modal dialog where the user can compose a short note
 * that automatically includes a nostr:naddr link to the article.
 */
export default class extends Controller {
  static targets = ['dialog', 'textarea', 'status', 'banner'];
  static values = {
    title: String,
    slug: String,
    pubkey: String,
    publishUrl: String,
  };

  connect() {
    this._naddr = null;
    this._published = false;
  }

  openDialog() {
    if (!this.hasDialogTarget) return;

    // Build the naddr
    this._naddr = encodeNaddr(30023, this.pubkeyValue, this.slugValue);
    const suggested = `${this.titleValue}\n\nnostr:${this._naddr}`;

    if (this.hasTextareaTarget) {
      this.textareaTarget.value = suggested;
    }

    this.dialogTarget.style.display = 'block';
  }

  closeDialog() {
    if (this.hasDialogTarget) {
      this.dialogTarget.style.display = 'none';
    }
  }

  dismiss() {
    if (this.hasBannerTarget) {
      this.bannerTarget.remove();
    }
    this.closeDialog();
  }

  stopPropagation(event) {
    event.stopPropagation();
  }

  async publishNote() {
    if (this._published) return;

    const content = this.hasTextareaTarget ? this.textareaTarget.value.trim() : '';
    if (!content) {
      this._setStatus('Please write something first.');
      return;
    }

    this._setStatus('Preparing note for signing…');

    try {
      const naddr = this._naddr || encodeNaddr(30023, this.pubkeyValue, this.slugValue);

      // Build kind 1 event
      const noteEvent = {
        kind: 1,
        created_at: Math.floor(Date.now() / 1000),
        tags: [
          ['a', `30023:${this.pubkeyValue}:${this.slugValue}`],
        ],
        content: content,
      };

      // Sign the event
      let signedEvent;
      const remoteSession = getRemoteSignerSession();

      if (remoteSession) {
        // Remote signer (bunker) flow
        const { SimplePool, generateSecretKey, finalizeEvent } = await import('nostr-tools');
        const { hexToBytes } = await import('nostr-tools/utils');
        const { BunkerSigner } = await import('nostr-tools/nip46');

        const localSecretKey = hexToBytes(remoteSession.privkey);
        const pool = new SimplePool({
          enableReconnect: true,
          automaticallyAuth: () => (evt) => finalizeEvent(evt, localSecretKey)
        });

        let signer;
        if (remoteSession.bunkerPointer) {
          signer = BunkerSigner.fromBunker(localSecretKey, remoteSession.bunkerPointer, { pool });
          await signer.connect();
        } else {
          throw new Error('No bunker pointer in remote session');
        }

        this._setStatus('Requesting signature from remote signer…');
        signedEvent = await signer.signEvent(noteEvent);
        try { signer.close?.(); } catch (_) {}
      } else if (window.nostr) {
        // NIP-07 extension flow
        this._setStatus('Requesting signature from Nostr extension…');
        noteEvent.pubkey = await window.nostr.getPublicKey();
        signedEvent = await window.nostr.signEvent(noteEvent);
      } else {
        this._setStatus('No Nostr signer available. Please install a NIP-07 extension.');
        return;
      }

      // Publish via backend
      this._setStatus('Publishing note to relays…');
      const response = await fetch(this.publishUrlValue, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ event: signedEvent }),
      });

      const result = await response.json();

      if (result.success) {
        this._published = true;
        this._setStatus(`Note published to ${result.successCount} relay${result.successCount !== 1 ? 's' : ''}!`);
        if (typeof window.showToast === 'function') {
          window.showToast(`✓ Note published to ${result.successCount} relay${result.successCount !== 1 ? 's' : ''}`, 'success', 5000);
        }
        setTimeout(() => this.dismiss(), 2000);
      } else {
        this._setStatus('Publishing failed: ' + (result.error || 'Unknown error'));
      }
    } catch (error) {
      console.error('[suggest-note] Publishing error:', error);
      this._setStatus('Error: ' + (error.message || 'Unknown error'));
    }
  }

  _setStatus(msg) {
    if (this.hasStatusTarget) {
      this.statusTarget.textContent = msg;
    }
  }
}


