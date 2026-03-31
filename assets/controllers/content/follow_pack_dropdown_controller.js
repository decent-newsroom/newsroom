import { Controller } from '@hotwired/stimulus';
import { getSigner } from '../nostr/signer_manager.js';

/**
 * Follow Pack Dropdown Controller
 *
 * Adds a user (by hex pubkey) to one of the logged-in user's follow packs (kind 39089).
 * Mirrors the reading-list-dropdown pattern: dropdown menu → select pack → sign → publish.
 */
export default class extends Controller {
  static targets = ['dropdown', 'menu'];
  static values = {
    pubkey: String,       // hex pubkey of the profile being viewed
    packs: String,        // JSON-encoded array of the user's follow packs
    publishUrl: String,
    csrfToken: String,
  };

  connect() {
    this.boundCloseOnClickOutside = this.closeOnClickOutside.bind(this);
    document.addEventListener('click', this.boundCloseOnClickOutside);
  }

  disconnect() {
    document.removeEventListener('click', this.boundCloseOnClickOutside);
  }

  toggleDropdown(event) {
    event.preventDefault();
    event.stopPropagation();

    if (this.hasMenuTarget) {
      const isOpen = this.menuTarget.classList.contains('show');
      if (isOpen) {
        this.closeDropdown();
      } else {
        this.openDropdown();
      }
    }
  }

  openDropdown() {
    if (this.hasMenuTarget) {
      this.menuTarget.classList.add('show');
      if (this.hasDropdownTarget) {
        this.dropdownTarget.setAttribute('aria-expanded', 'true');
      }
    }
  }

  closeDropdown() {
    if (this.hasMenuTarget) {
      this.menuTarget.classList.remove('show');
      if (this.hasDropdownTarget) {
        this.dropdownTarget.setAttribute('aria-expanded', 'false');
      }
    }
  }

  closeOnClickOutside(event) {
    if (!this.element.contains(event.target)) {
      this.closeDropdown();
    }
  }

  async addToPack(event) {
    event.preventDefault();
    event.stopPropagation();

    const dTag = event.currentTarget.dataset.dtag;
    const title = event.currentTarget.dataset.title;

    let signer;
    try {
      signer = await getSigner();
    } catch (e) {
      this.showError('No Nostr signer available. Please connect Amber or install a Nostr signer extension.');
      return;
    }

    try {
      this.showStatus(`Adding to "${title}"...`);

      const packs = JSON.parse(this.packsValue || '[]');
      const selectedPack = packs.find(p => p.dTag === dTag);
      if (!selectedPack) {
        this.showError('Follow pack not found');
        return;
      }

      // Check if already a member
      if (selectedPack.members && selectedPack.members.includes(this.pubkeyValue)) {
        this.showSuccess(`Already in "${title}"`);
        setTimeout(() => this.closeDropdown(), 2000);
        return;
      }

      const eventSkeleton = await this.buildFollowPackEvent(selectedPack);

      this.showStatus(`Signing update to "${title}"...`);
      const signedEvent = await signer.signEvent(eventSkeleton);

      this.showStatus('Publishing update...');
      await this.publishEvent(signedEvent);

      this.showSuccess(`✓ Added to "${title}"`);

      setTimeout(() => {
        this.closeDropdown();
        window.location.reload();
      }, 1500);

    } catch (error) {
      console.error('Error adding to follow pack:', error);
      this.showError(error.message || 'Failed to add to follow pack');
    }
  }

  async buildFollowPackEvent(packData) {
    const signer = await getSigner();
    const pubkey = await signer.getPublicKey();

    // Rebuild tags from the existing event, adding the new p tag
    const existingTags = packData.eventJson ? JSON.parse(packData.eventJson) : [];

    const tags = [];
    // Preserve d, title, alt, image, description tags
    for (const tag of existingTags) {
      if (['d', 'title', 'alt', 'image', 'description'].includes(tag[0])) {
        tags.push(tag);
      }
    }

    // Ensure d tag exists
    if (!tags.some(t => t[0] === 'd')) {
      tags.push(['d', packData.dTag]);
    }
    // Ensure title tag exists
    if (!tags.some(t => t[0] === 'title')) {
      tags.push(['title', packData.title]);
    }
    // Ensure alt tag exists
    if (!tags.some(t => t[0] === 'alt')) {
      tags.push(['alt', `Follow pack: ${packData.title}`]);
    }

    // Collect existing p tags (members) and add the new one
    const memberSet = new Set();
    for (const tag of existingTags) {
      if (tag[0] === 'p' && tag[1]) {
        memberSet.add(tag[1]);
      }
    }
    memberSet.add(this.pubkeyValue);

    // Add all p tags
    memberSet.forEach(hex => {
      tags.push(['p', hex]);
    });

    return {
      kind: 39089,
      created_at: Math.floor(Date.now() / 1000),
      tags: tags,
      content: '',
      pubkey: pubkey,
    };
  }

  async publishEvent(signedEvent) {
    const response = await fetch(this.publishUrlValue, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': this.csrfTokenValue,
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
    if (window.showToast) {
      window.showToast(message, 'info', 3000);
    }
  }

  showSuccess(message) {
    if (window.showToast) {
      window.showToast(message, 'success', 3000);
    }
  }

  showError(message) {
    if (window.showToast) {
      window.showToast(message, 'danger', 5000);
    }
  }
}

