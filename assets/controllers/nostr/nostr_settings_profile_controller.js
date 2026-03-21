import { Controller } from '@hotwired/stimulus';
import { getSigner } from './signer_manager.js';

/**
 * Settings Profile Publish Controller
 *
 * Handles kind 0 profile metadata editing and publishing.
 * Collects form data, builds a kind 0 event with both tags and JSON content
 * (for maximum client compatibility), signs via the user's Nostr signer,
 * and publishes to relays via the backend.
 */
export default class extends Controller {
  static targets = [
    'displayName', 'name', 'about', 'picture', 'banner',
    'nip05', 'lud16', 'website', 'status', 'publishButton'
  ];

  static values = {
    publishUrl: String,
    profile: Object,
  };

  connect() {
    console.log('[settings-profile] Controller connected');
  }

  async publish(event) {
    event.preventDefault();

    let signer;
    try {
      this.showStatus('Connecting to signer...');
      signer = await getSigner();
    } catch (e) {
      this.showError('No Nostr signer available. Please connect a signer extension.');
      return;
    }

    if (this.hasPublishButtonTarget) {
      this.publishButtonTarget.disabled = true;
    }

    try {
      this.showStatus('Preparing profile event...');
      const pubkey = await signer.getPublicKey();

      // Collect form values
      const fields = {
        display_name: this.hasDisplayNameTarget ? this.displayNameTarget.value.trim() : '',
        name: this.hasNameTarget ? this.nameTarget.value.trim() : '',
        about: this.hasAboutTarget ? this.aboutTarget.value.trim() : '',
        picture: this.hasPictureTarget ? this.pictureTarget.value.trim() : '',
        banner: this.hasBannerTarget ? this.bannerTarget.value.trim() : '',
        nip05: this.hasNip05Target ? this.nip05Target.value.trim() : '',
        lud16: this.hasLud16Target ? this.lud16Target.value.trim() : '',
        website: this.hasWebsiteTarget ? this.websiteTarget.value.trim() : '',
      };

      // Build tags (new format) — only include non-empty fields
      const tags = [];
      for (const [key, value] of Object.entries(fields)) {
        if (value) {
          tags.push([key, value]);
        }
      }

      // Build content JSON (legacy format for compatibility with other clients)
      const contentObj = {};
      for (const [key, value] of Object.entries(fields)) {
        if (value) {
          contentObj[key] = value;
        }
      }

      const skeleton = {
        kind: 0,
        created_at: Math.floor(Date.now() / 1000),
        tags: tags,
        content: JSON.stringify(contentObj),
        pubkey: pubkey,
      };

      this.showStatus('Requesting signature from Nostr signer...');
      console.log('[settings-profile] Signing event:', skeleton);
      const signedEvent = await signer.signEvent(skeleton);
      console.log('[settings-profile] Event signed:', signedEvent);

      this.showStatus('Publishing profile...');
      const result = await this.sendToBackend(signedEvent);

      if (result.success) {
        this.showSuccess(`Profile published! (${result.relays_success} relay${result.relays_success !== 1 ? 's' : ''})`);
        setTimeout(() => window.location.reload(), 2000);
      } else {
        this.showError('Publishing failed — no relays accepted the event.');
      }
    } catch (error) {
      console.error('[settings-profile] Publishing error:', error);
      this.showError(`Publishing failed: ${error.message}`);
    } finally {
      if (this.hasPublishButtonTarget) {
        this.publishButtonTarget.disabled = false;
      }
    }
  }

  async sendToBackend(signedEvent) {
    const response = await fetch(this.publishUrlValue, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({ event: signedEvent }),
    });

    if (!response.ok) {
      const data = await response.json().catch(() => ({}));
      throw new Error(data.error || `HTTP ${response.status}`);
    }

    return response.json();
  }

  showStatus(message) {
    if (this.hasStatusTarget) {
      this.statusTarget.textContent = message;
      this.statusTarget.className = 'settings-status settings-status--info';
    }
  }

  showSuccess(message) {
    if (this.hasStatusTarget) {
      this.statusTarget.textContent = message;
      this.statusTarget.className = 'settings-status settings-status--success';
    }
  }

  showError(message) {
    if (this.hasStatusTarget) {
      this.statusTarget.textContent = message;
      this.statusTarget.className = 'settings-status settings-status--error';
    }
  }
}

