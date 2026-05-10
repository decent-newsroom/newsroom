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
    'nip05List', 'nip05Input', 'lud16', 'website', 'publishButton'
  ];

  static values = {
    publishUrl: String,
    profile: Object,
    existingContent: Object, // Raw kind 0 content from cache — may include unknown fields
    existingTags: Array,     // Pre-existing tags from current kind 0 — preserves unknown tags
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

      // Collect form values (only DN-known fields)
      const formFields = {
        display_name: this.hasDisplayNameTarget ? this.displayNameTarget.value.trim() : '',
        name: this.hasNameTarget ? this.nameTarget.value.trim() : '',
        about: this.hasAboutTarget ? this.aboutTarget.value.trim() : '',
        picture: this.hasPictureTarget ? this.pictureTarget.value.trim() : '',
        banner: this.hasBannerTarget ? this.bannerTarget.value.trim() : '',
        lud16: this.hasLud16Target ? this.lud16Target.value.trim() : '',
        website: this.hasWebsiteTarget ? this.websiteTarget.value.trim() : '',
      };
      const nip05Values = this.collectNip05Values();

      // Start from existing content (preserves unknown fields like bot, pronouns, etc.)
      const existingContent = this.hasExistingContentValue ? { ...this.existingContentValue } : {};

      // Merge form fields into existing content — form values override, empty = remove
      const contentObj = { ...existingContent };
      for (const [key, value] of Object.entries(formFields)) {
        if (value) {
          contentObj[key] = value;
        } else {
          delete contentObj[key]; // user cleared the field — remove it
        }
      }

      if (nip05Values.length > 0) {
        contentObj.nip05 = nip05Values;
      } else {
        delete contentObj.nip05;
      }

      // Build tags — preserve existing unknown tags, override form-managed ones
      const formFieldKeys = new Set([...Object.keys(formFields), 'nip05']);

      // Start with existing tags, keeping only those NOT managed by the form
      const tags = (this.hasExistingTagsValue ? this.existingTagsValue : [])
        .filter(tag => Array.isArray(tag) && tag.length >= 2 && !formFieldKeys.has(tag[0]));

      // Add non-empty form fields as tags (replaces any previous values)
      for (const [key, value] of Object.entries(formFields)) {
        if (value) {
          tags.push([key, value]);
        }
      }

      for (const nip05Value of nip05Values) {
        tags.push(['nip05', nip05Value]);
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

  addNip05Field(event) {
    event.preventDefault();
    if (!this.hasNip05ListTarget) {
      return;
    }

    const row = this.createNip05Row('');
    this.nip05ListTarget.appendChild(row);
    const input = row.querySelector('[data-nostr--nostr-settings-profile-target="nip05Input"]');
    input?.focus();
  }

  removeNip05Field(event) {
    event.preventDefault();
    const row = event.currentTarget.closest('.settings-multi-field__row');
    if (!row || !this.hasNip05ListTarget) {
      return;
    }

    row.remove();

    // Keep one empty row so users can always enter a value.
    if (this.nip05InputTargets.length === 0) {
      this.nip05ListTarget.appendChild(this.createNip05Row(''));
    }
  }

  collectNip05Values() {
    const values = [];

    for (const input of this.nip05InputTargets) {
      const value = input.value.trim();
      if (value && !values.includes(value)) {
        values.push(value);
      }
    }

    return values;
  }

  createNip05Row(value) {
    const row = document.createElement('div');
    row.className = 'settings-multi-field__row';

    const input = document.createElement('input');
    input.type = 'text';
    input.value = value;
    input.placeholder = 'you@example.com';
    input.setAttribute('data-nostr--nostr-settings-profile-target', 'nip05Input');

    const removeButton = document.createElement('button');
    removeButton.type = 'button';
    removeButton.className = 'btn btn--secondary settings-multi-field__remove';
    removeButton.setAttribute('data-action', 'click->nostr--nostr-settings-profile#removeNip05Field');
    removeButton.textContent = '-';

    row.appendChild(input);
    row.appendChild(removeButton);

    return row;
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
    if (typeof window.showToast === 'function') {
      window.showToast(message, 'info', 3000);
    }
  }

  showSuccess(message) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, 'success', 4000);
    }
  }

  showError(message) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, 'danger', 8000);
    }
  }
}

