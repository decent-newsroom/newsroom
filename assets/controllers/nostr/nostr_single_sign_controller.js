import { Controller } from '@hotwired/stimulus';
import { getSigner, getRemoteSignerSession } from './signer_manager.js';

export default class extends Controller {
  static targets = ['status', 'publishButton', 'computedPreview'];
  static values = {
    event: String,
    publishUrl: String,
    redirectUrl: String  // Optional: redirect URL after successful publish
  };

  // Make targets optional for event-driven usage
  get hasStatusTarget() {
    return this.targets.has('status');
  }
  get hasPublishButtonTarget() {
    return this.targets.has('publishButton');
  }
  get hasComputedPreviewTarget() {
    return this.targets.has('computedPreview');
  }

  async connect() {
    try {
      await this.preparePreview();
    } catch (_) {}
    // Listen for nostr:sign event for editor handoff
    this.handleSignEvent = this.handleSignEvent.bind(this);
    (this.element.closest('.editor-layout') || document).addEventListener('nostr:sign', this.handleSignEvent);
  }

  disconnect() {
    (this.element.closest('.editor-layout') || document).removeEventListener('nostr:sign', this.handleSignEvent);
  }

  handleSignEvent(e) {
    const { nostrEvent, formData } = e.detail;
    // Update eventValue for signing
    this.showStatus('Event received for signing. Ready to sign and publish.');
    this.eventValue = JSON.stringify(nostrEvent);
    // Store formData for later use in publishing
    this.handoffFormData = formData;
    // Trigger signing
    this.signAndPublish(new Event('submit')).then(r => {
      this.showStatus('Signing process completed.');
    });
  }

  async preparePreview() {
    try {
      const skeleton = JSON.parse(this.eventValue || '{}');
      let pubkey = '<pubkey>';

      // Check for remote signer session first - don't use extension if remote signer is active
      const session = getRemoteSignerSession();
      if (session) {
        pubkey = '<will be obtained from remote signer>';
      } else if (window.nostr && typeof window.nostr.getPublicKey === 'function') {
        // Only try to get pubkey from extension if no remote signer session exists
        try {
          pubkey = await window.nostr.getPublicKey();
        } catch (_) {}
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

    if (!this.publishUrlValue) {
      console.error('[nostr_single_sign] Missing config', { publishUrl: this.publishUrlValue});
      this.showError('Missing config');
      return;
    }

    if (this.hasPublishButtonTarget) {
      this.publishButtonTarget.disabled = true;
    }
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
      const result = await this.publishSigned(signed);
      console.log('[nostr_single_sign] Event published successfully');

      // Handle redirect based on whether it's a draft or published article
      if (result.isDraft) {
        this.showSuccess('Draft saved successfully!');
        // Stay on current page for drafts
      } else {
        this.showSuccess(result.message || 'Published successfully! Redirecting...');
        // Redirect: use URL from response, then from value, then default
        const redirectUrl = result.redirectUrl || this.redirectUrlValue || '/reading-list';
        setTimeout(() => {
          window.location.href = redirectUrl;
        }, 1500);
      }
    } catch (e) {
      console.error('[nostr_single_sign] Error during sign/publish:', e);
      this.showError(e.message || 'Publish failed');
    } finally {
      if (this.hasPublishButtonTarget) {
        this.publishButtonTarget.disabled = false;
      }
    }
  }

  async publishSigned(signedEvent) {
    // Build request body - include formData if available (from editor handoff)
    const body = { event: signedEvent };
    if (this.handoffFormData) {
      body.formData = this.handoffFormData;
    }

    const res = await fetch(this.publishUrlValue, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify(body)
    });
    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      throw new Error(data.error || `HTTP ${res.status}`);
    }

    const result = await res.json();

    // Display relay results if available
    if (result.relayResults) {
      this.displayRelayResults(result.relayResults);
    }

    return result;
  }

  ensureCreatedAt(evt) {
    if (!evt.created_at) evt.created_at = Math.floor(Date.now() / 1000);
  }
  ensureContent(evt) {
    if (typeof evt.content !== 'string') evt.content = '';
  }

  displayRelayResults(relayResults) {
    console.log('[nostr_single_sign] Relay results:', relayResults);

    // Handle error case
    if (relayResults.error) {
      window.showToast(`Relay error: ${relayResults.error}`, 'warning', 6000);
      return;
    }

    // Parse relay results from backend
    if (!Array.isArray(relayResults) || relayResults.length === 0) {
      window.showToast('Published to relays (status unknown)', 'info', 4000);
      return;
    }

    // Count successes, failures, and unknowns
    let successCount = 0;
    let failureCount = 0;
    let unknownCount = 0;
    const failedRelays = [];
    const unknownRelays = [];

    relayResults.forEach((result) => {
      const relayUrl = result.relay || 'Unknown relay';

      if (result.success) {
        successCount++;
      } else if (result.type === 'auth') {
        // AUTH responses are unknown status - not confirmed but not failed
        unknownCount++;
        unknownRelays.push(relayUrl);
      } else {
        // Other non-success responses are failures
        failureCount++;
        failedRelays.push(relayUrl);
      }
    });

    // Show summary toast
    if (successCount > 0 && failureCount === 0 && unknownCount === 0) {
      // Perfect success
      window.showToast(`✓ Published to ${successCount} relay${successCount > 1 ? 's' : ''}`, 'success', 5000);
    } else if (successCount > 0 && unknownCount > 0 && failureCount === 0) {
      // Success with some unknowns
      window.showToast(`✓ Published to ${successCount} relay${successCount > 1 ? 's' : ''}, ${unknownCount} unknown`, 'success', 6000);
      // Show details about unknowns
      if (unknownRelays.length > 0) {
        setTimeout(() => {
          window.showToast(`Unknown status: ${unknownRelays.join(', ')}`, 'info', 8000);
        }, 500);
      }
    } else if (successCount > 0 && failureCount > 0) {
      // Mixed success and failure
      const statusParts = [`${successCount} success`];
      if (unknownCount > 0) statusParts.push(`${unknownCount} unknown`);
      statusParts.push(`${failureCount} failed`);

      window.showToast(`Published: ${statusParts.join(', ')}`, 'warning', 6000);
      // Show details about failures
      setTimeout(() => {
        if (failedRelays.length > 0) {
          window.showToast(`Failed: ${failedRelays.join(', ')}`, 'danger', 8000);
        }
        if (unknownRelays.length > 0) {
          setTimeout(() => {
            window.showToast(`Unknown: ${unknownRelays.join(', ')}`, 'info', 8000);
          }, 500);
        }
      }, 500);
    } else if (failureCount > 0 || unknownCount > 0) {
      // No successes
      window.showToast(`✗ Publishing uncertain (${unknownCount} unknown, ${failureCount} failed)`, 'warning', 8000);
    }
  }

  showStatus(message) {
    // Use toast system if available, otherwise fallback to status target
    if (typeof window.showToast === 'function') {
      window.showToast(message, 'info');
    } else if (this.hasStatusTarget) {
      this.statusTarget.innerHTML = `<div class="alert alert-info">${message}</div>`;
    }
  }
  showSuccess(message) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, 'success');
    } else if (this.hasStatusTarget) {
      this.statusTarget.innerHTML = `<div class="alert alert-success">${message}</div>`;
    }
  }
  showError(message) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, 'danger');
    } else if (this.hasStatusTarget) {
      this.statusTarget.innerHTML = `<div class="alert alert-danger">${message}</div>`;
    }
  }
}
