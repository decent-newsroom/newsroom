import { Controller } from '@hotwired/stimulus';
import { getSigner } from './signer_manager.js';

/**
 * Follow Pack Setup Controller (kind 39089)
 *
 * Manages a follow pack setup UI that lets the user:
 * - Search for users (via /api/users/search)
 * - See their kind 3 follows list as suggestions
 * - Build a list of recommended writers
 * - Sign and publish a kind 39089 follow pack event
 * - Store the coordinate as their profile recommendation list
 */
export default class extends Controller {
  static targets = [
    'searchInput', 'searchResults', 'selectedList', 'selectedCount',
    'publishButton', 'status', 'form', 'infoBubble',
    'packTitle', 'packImage', 'packDescription', 'existingPacks', 'formHeading',
  ];

  static values = {
    publishUrl: String,
    setCoordinateUrl: String,
    searchUrl: { type: String, default: '/api/users/search' },
    followsPubkeys: Array,     // Hex pubkeys from kind 3
    followsProfiles: Array,    // Resolved profile objects [{npub, displayName, picture, ...}]
    existingMembers: Array,    // Existing pack member npubs (for editing)
    existingDtag: String,      // d-tag of existing pack (for updates)
    existingImage: String,     // image URL of existing pack
    existingDescription: String, // description of existing pack
    selectedCoordinate: String, // Currently selected follow pack coordinate
    createHeading: { type: String, default: 'Create a new follow pack' },
    editHeading: { type: String, default: 'Edit your follow pack' },
    allPacks: Array,  // All existing packs for edit switching
  };

  connect() {
    this.selectedUsers = new Map(); // npub => user object
    this._searchTimer = null;

    // Pre-populate from existing pack members
    if (this.existingMembersValue && this.existingMembersValue.length > 0) {
      this.existingMembersValue.forEach(user => {
        if (user.npub) {
          this.selectedUsers.set(user.npub, user);
        }
      });
    }

    // On the dedicated page there is no infoBubble — the form is always visible
    if (this.hasFormTarget && !this.hasInfoBubbleTarget) {
      this.formTarget.classList.add('follow-pack-form--open');
    }

    this.renderSelectedList();
    this.renderFollowsSuggestions();
  }

  disconnect() {
    if (this._searchTimer) clearTimeout(this._searchTimer);
  }

  /**
   * Toggle the setup form visibility
   */
  toggleForm() {
    if (this.hasFormTarget) {
      this.formTarget.classList.toggle('follow-pack-form--open');
    }
  }

  /**
   * Debounced search input handler
   */
  onSearchInput() {
    if (this._searchTimer) clearTimeout(this._searchTimer);
    this._searchTimer = setTimeout(() => this.search(), 350);
  }

  /**
   * Handle paste events — detect and auto-add npubs from pasted text
   */
  onPaste(event) {
    // Let the paste complete, then process
    setTimeout(() => this.search(), 50);
  }

  /**
   * Extract all npub1... strings from text (handles comma, space, newline separators)
   */
  extractNpubs(text) {
    const matches = text.match(/npub1[a-z0-9]{58}/g);
    return matches ? [...new Set(matches)] : [];
  }

  /**
   * Search for users via API, or resolve pasted npubs directly
   */
  async search() {
    const query = this.searchInputTarget.value.trim();
    if (query.length < 2) {
      this.searchResultsTarget.innerHTML = '';
      // Show follows suggestions again when search is cleared
      if (query.length === 0) {
        this.renderFollowsSuggestions();
      }
      return;
    }

    // Check if the input contains npub(s)
    const npubs = this.extractNpubs(query);
    if (npubs.length > 0) {
      await this.resolveNpubs(npubs);
      return;
    }

    try {
      const url = `${this.searchUrlValue}?q=${encodeURIComponent(query)}&limit=10`;
      const response = await fetch(url);
      if (!response.ok) return;
      const data = await response.json();
      this.renderSearchResults(data.users || []);
    } catch (e) {
      console.error('[follow-pack] Search error:', e);
    }
  }

  /**
   * Resolve npubs to profiles via the by-npubs API, then render results.
   * Unresolved npubs are shown as bare entries so they can still be added.
   */
  async resolveNpubs(npubs) {
    // Filter out already-selected npubs
    const toResolve = npubs.filter(n => !this.selectedUsers.has(n));

    if (toResolve.length === 0) {
      this.searchResultsTarget.innerHTML = '<p class="fp-empty">All pasted npubs already added.</p>';
      return;
    }

    this.searchResultsTarget.innerHTML = '<p class="fp-empty">Resolving npubs…</p>';

    let resolvedMap = {};
    try {
      const response = await fetch('/api/users/by-npubs', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ npubs: toResolve }),
      });
      if (response.ok) {
        const data = await response.json();
        for (const user of (data.users || [])) {
          resolvedMap[user.npub] = user;
        }
      }
    } catch (e) {
      console.warn('[follow-pack] npub resolution error:', e);
    }

    // Build user objects for all npubs (resolved or not)
    const users = toResolve.map(npub => {
      if (resolvedMap[npub]) {
        return resolvedMap[npub];
      }
      // Unresolved — create a minimal entry so it can still be added
      return {
        npub: npub,
        displayName: npub.slice(0, 12) + '…' + npub.slice(-6),
        name: '',
        picture: '',
        nip05: '',
      };
    });

    this.renderSearchResults(users);

    // If only one npub was pasted and it's resolved, auto-add it and clear
    if (npubs.length === 1 && resolvedMap[npubs[0]]) {
      const user = resolvedMap[npubs[0]];
      if (!this.selectedUsers.has(user.npub)) {
        this.selectedUsers.set(user.npub, {
          npub: user.npub,
          displayName: user.displayName || user.name || npubs[0].slice(0, 12) + '…',
          picture: user.picture || '',
          nip05: user.nip05 || '',
        });
        this.renderSelectedList();
        this.searchInputTarget.value = '';
        this.renderFollowsSuggestions();
      }
    }
  }

  /**
   * Render search results
   */
  renderSearchResults(users) {
    if (users.length === 0) {
      this.searchResultsTarget.innerHTML = '<p class="fp-empty">No users found.</p>';
      return;
    }

    // Sort: follows first
    const followSet = new Set(this.followsPubkeysValue || []);
    const sorted = [...users].sort((a, b) => {
      const aFollow = followSet.has(this.npubToHex(a.npub)) ? 0 : 1;
      const bFollow = followSet.has(this.npubToHex(b.npub)) ? 0 : 1;
      return aFollow - bFollow;
    });

    this.searchResultsTarget.innerHTML = sorted.map(user => this.renderUserRow(user)).join('');
  }

  /**
   * Render follow suggestions from kind 3 follows
   */
  renderFollowsSuggestions() {
    if (!this.hasSearchResultsTarget) return;
    const profiles = this.followsProfilesValue || [];
    if (profiles.length === 0) return;

    // Only show follows not already in the selected list
    const notSelected = profiles.filter(u => !this.selectedUsers.has(u.npub));
    if (notSelected.length === 0) {
      this.searchResultsTarget.innerHTML = '<p class="fp-empty">All follows already added.</p>';
      return;
    }

    const label = '<p class="fp-suggestion-label">From your follows:</p>';
    this.searchResultsTarget.innerHTML = label + notSelected.slice(0, 10).map(user => this.renderUserRow(user)).join('');
  }

  /**
   * Render a single user row
   */
  renderUserRow(user) {
    const name = this.esc(user.displayName || user.name || 'Anonymous');
    const nip05 = user.nip05 ? `<span class="fp-user-nip05">${this.esc(user.nip05)}</span>` : '';
    const pic = user.picture
      ? `<img src="${this.esc(user.picture)}" alt="" class="fp-user-avatar" loading="lazy" />`
      : '<span class="fp-user-avatar fp-user-avatar--placeholder">?</span>';

    const isSelected = this.selectedUsers.has(user.npub);
    const btnLabel = isSelected ? '✓' : '+';
    const btnClass = isSelected ? 'fp-btn--added' : 'fp-btn--add';
    const actionName = isSelected ? 'removeUser' : 'addUser';

    return `
      <div class="fp-user-row">
        ${pic}
        <div class="fp-user-info">
          <span class="fp-user-name">${name}</span>
          ${nip05}
        </div>
        <button type="button"
                class="btn btn--small ${btnClass}"
                data-action="click->nostr--nostr-follow-pack#${actionName}"
                data-npub="${this.esc(user.npub)}"
                data-name="${this.esc(user.displayName || user.name || 'Anonymous')}"
                data-picture="${this.esc(user.picture || '')}"
                data-nip05="${this.esc(user.nip05 || '')}">
          ${btnLabel}
        </button>
      </div>`;
  }

  /**
   * Add a user to the selected list
   */
  addUser(event) {
    const btn = event.currentTarget;
    const npub = btn.dataset.npub;
    if (!npub || this.selectedUsers.has(npub)) return;

    this.selectedUsers.set(npub, {
      npub: npub,
      displayName: btn.dataset.name,
      picture: btn.dataset.picture,
      nip05: btn.dataset.nip05,
    });

    this.renderSelectedList();
    // Re-render search results to update button states
    if (this.searchInputTarget.value.trim().length >= 2) {
      this.search();
    } else {
      this.renderFollowsSuggestions();
    }
  }

  /**
   * Remove a user from the selected list
   */
  removeUser(event) {
    const btn = event.currentTarget;
    const npub = btn.dataset.npub;
    this.selectedUsers.delete(npub);
    this.renderSelectedList();
    // Re-render suggestions
    if (this.searchInputTarget.value.trim().length >= 2) {
      this.search();
    } else {
      this.renderFollowsSuggestions();
    }
  }

  /**
   * Render the selected users list
   */
  renderSelectedList() {
    const container = this.selectedListTarget;

    if (this.selectedUsers.size === 0) {
      container.innerHTML = '<p class="fp-empty">No writers selected yet.</p>';
    } else {
      container.innerHTML = '';
      this.selectedUsers.forEach((user, npub) => {
        const name = this.esc(user.displayName || 'Anonymous');
        const pic = user.picture
          ? `<img src="${this.esc(user.picture)}" alt="" class="fp-chip-avatar" loading="lazy" />`
          : '';
        const el = document.createElement('span');
        el.className = 'fp-selected-chip';
        el.innerHTML = `${pic}<span>${name}</span>
          <button type="button"
                  data-action="click->nostr--nostr-follow-pack#removeUser"
                  data-npub="${this.esc(npub)}"
                  aria-label="Remove ${name}">&times;</button>`;
        container.appendChild(el);
      });
    }

    // Update count
    if (this.hasSelectedCountTarget) {
      this.selectedCountTarget.textContent = this.selectedUsers.size;
    }

    // Enable/disable publish button
    if (this.hasPublishButtonTarget) {
      this.publishButtonTarget.disabled = this.selectedUsers.size === 0;
    }
  }

  /**
   * Sign and publish the follow pack event, then store the coordinate
   */
  async publish(event) {
    event.preventDefault();

    if (this.selectedUsers.size === 0) {
      this.showError('Please select at least one writer.');
      return;
    }

    let signer;
    try {
      this.showStatus('Connecting to signer...');
      signer = await getSigner();
    } catch (e) {
      this.showError('No Nostr signer available. Please connect your Nostr signer.');
      return;
    }

    this.publishButtonTarget.disabled = true;

    try {
      this.showStatus('Preparing follow pack event...');
      const pubkey = await signer.getPublicKey();

      // Build d-tag: reuse existing or generate new
      const dTag = this.existingDtagValue || `follow-pack-${Date.now()}`;
      const title = this.hasPackTitleTarget
        ? this.packTitleTarget.value.trim() || 'My Recommended Writers'
        : 'My Recommended Writers';

      // Build tags
      const tags = [
        ['d', dTag],
        ['title', title],
        ['alt', `Follow pack: ${title}`],
      ];

      // Optional image
      const image = this.hasPackImageTarget
        ? this.packImageTarget.value.trim()
        : '';
      if (image) {
        tags.push(['image', image]);
      }

      // Optional description
      const description = this.hasPackDescriptionTarget
        ? this.packDescriptionTarget.value.trim()
        : '';
      if (description) {
        tags.push(['description', description]);
      }

      // Add p tags for each selected user
      this.selectedUsers.forEach((user, npub) => {
        const hex = this.npubToHex(npub);
        if (hex) {
          tags.push(['p', hex]);
        }
      });

      const skeleton = {
        kind: 39089,
        created_at: Math.floor(Date.now() / 1000),
        tags: tags,
        content: '',
        pubkey: pubkey,
      };

      this.showStatus('Requesting signature...');
      const signedEvent = await signer.signEvent(skeleton);

      // Publish to relays via backend
      this.showStatus('Publishing follow pack...');
      await this.sendToBackend(signedEvent, this.publishUrlValue);

      // Store the coordinate on the user profile
      const coordinate = `39089:${pubkey}:${dTag}`;
      this.showStatus('Saving as your recommendation list...');
      await this.storeCoordinate(coordinate);

      this.showSuccess('Follow pack published and set as your recommendation list!');
      setTimeout(() => window.location.reload(), 2000);

    } catch (error) {
      console.error('[follow-pack] Publishing error:', error);
      this.showError(`Publishing failed: ${error.message}`);
    } finally {
      this.publishButtonTarget.disabled = false;
    }
  }

  /**
   * Select an existing follow pack as the recommendation list (without re-publishing)
   */
  async selectPack(event) {
    const coordinate = event.currentTarget.dataset.coordinate;
    if (!coordinate) return;

    try {
      this.showStatus('Setting as recommendation list...');
      await this.storeCoordinate(coordinate);
      this.showSuccess('Recommendation list updated!');
      setTimeout(() => window.location.reload(), 1500);
    } catch (error) {
      this.showError(`Failed: ${error.message}`);
    }
  }

  /**
   * Reset the form to create a brand-new follow pack (clear d-tag, title, members, etc.)
   */
  createNew(event) {
    if (event) event.preventDefault();

    // Clear the existing d-tag so publish() generates a fresh one
    this.existingDtagValue = '';

    // Clear form fields
    if (this.hasPackTitleTarget) this.packTitleTarget.value = '';
    if (this.hasPackImageTarget) this.packImageTarget.value = '';
    if (this.hasPackDescriptionTarget) this.packDescriptionTarget.value = '';

    // Clear selected users
    this.selectedUsers.clear();
    this.renderSelectedList();
    this.renderFollowsSuggestions();

    // Update heading
    if (this.hasFormHeadingTarget) {
      this.formHeadingTarget.textContent = this.createHeadingValue;
    }

    // Scroll the form into view
    if (this.hasPackTitleTarget) {
      this.packTitleTarget.scrollIntoView({ behavior: 'smooth', block: 'center' });
      this.packTitleTarget.focus();
    }
  }

  /**
   * Load an existing pack into the form for editing
   */
  async editPack(event) {
    if (event) event.preventDefault();
    const dTag = event.currentTarget.dataset.dtag;
    if (!dTag) return;

    const packs = this.allPacksValue || [];
    const pack = packs.find(p => p.dTag === dTag);
    if (!pack) {
      this.showError('Pack not found.');
      return;
    }

    // Set d-tag so publish() updates this pack instead of creating a new one
    this.existingDtagValue = dTag;

    // Populate form fields
    if (this.hasPackTitleTarget) this.packTitleTarget.value = pack.title || '';
    if (this.hasPackImageTarget) this.packImageTarget.value = pack.image || '';
    if (this.hasPackDescriptionTarget) this.packDescriptionTarget.value = pack.description || '';

    // Update heading
    if (this.hasFormHeadingTarget) {
      this.formHeadingTarget.textContent = this.editHeadingValue;
    }

    // Resolve member hex pubkeys to profile objects
    this.selectedUsers.clear();
    const hexPubkeys = pack.memberPubkeys || [];

    if (hexPubkeys.length > 0) {
      // Convert hex to npub for display, then try resolving profiles
      const npubs = hexPubkeys.map(hex => this.hexToNpub(hex)).filter(Boolean);

      if (npubs.length > 0) {
        this.showStatus('Loading pack members…');
        try {
          const response = await fetch('/api/users/by-npubs', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ npubs }),
          });
          if (response.ok) {
            const data = await response.json();
            for (const user of (data.users || [])) {
              this.selectedUsers.set(user.npub, {
                npub: user.npub,
                displayName: user.displayName || user.name || '',
                picture: user.picture || '',
                nip05: user.nip05 || '',
              });
            }
          }
        } catch (e) {
          console.warn('[follow-pack] Failed to resolve pack members:', e);
        }

        // Add any unresolved npubs as bare entries
        for (const npub of npubs) {
          if (!this.selectedUsers.has(npub)) {
            this.selectedUsers.set(npub, {
              npub,
              displayName: npub.slice(0, 12) + '…' + npub.slice(-6),
              picture: '',
              nip05: '',
            });
          }
        }
      }
    }

    this.renderSelectedList();
    this.renderFollowsSuggestions();

    // Scroll the form into view
    if (this.hasFormHeadingTarget) {
      this.formHeadingTarget.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // Clear any status message
    if (this.hasStatusTarget) {
      this.statusTarget.innerHTML = '';
    }
  }

  /**
   * Convert hex pubkey to npub (bech32 encode)
   */
  hexToNpub(hex) {
    if (!hex || hex.length !== 64) return null;
    try {
      if (window.NostrTools && window.NostrTools.nip19) {
        return window.NostrTools.nip19.npubEncode(hex);
      }
      return this.hexToBech32('npub', hex);
    } catch (e) {
      console.warn('[follow-pack] Could not encode hex to npub:', hex, e);
      return null;
    }
  }

  /**
   * Basic hex to bech32 conversion
   */
  hexToBech32(hrp, hex) {
    // Convert hex to bytes
    const bytes = [];
    for (let i = 0; i < hex.length; i += 2) {
      bytes.push(parseInt(hex.substr(i, 2), 16));
    }
    // Convert 8-bit to 5-bit groups
    const data = [];
    let acc = 0, bits = 0;
    for (const b of bytes) {
      acc = (acc << 8) | b;
      bits += 8;
      while (bits >= 5) {
        bits -= 5;
        data.push((acc >> bits) & 0x1f);
      }
    }
    if (bits > 0) {
      data.push((acc << (5 - bits)) & 0x1f);
    }
    // Bech32 encode
    const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
    const checksum = this.bech32Checksum(hrp, data);
    let result = hrp + '1';
    for (const v of [...data, ...checksum]) {
      result += CHARSET[v];
    }
    return result;
  }

  /**
   * Compute bech32 checksum
   */
  bech32Checksum(hrp, data) {
    const values = this.bech32HrpExpand(hrp).concat(data).concat([0, 0, 0, 0, 0, 0]);
    const polymod = this.bech32Polymod(values) ^ 1;
    const checksum = [];
    for (let i = 0; i < 6; i++) {
      checksum.push((polymod >> (5 * (5 - i))) & 31);
    }
    return checksum;
  }

  bech32HrpExpand(hrp) {
    const ret = [];
    for (let i = 0; i < hrp.length; i++) ret.push(hrp.charCodeAt(i) >> 5);
    ret.push(0);
    for (let i = 0; i < hrp.length; i++) ret.push(hrp.charCodeAt(i) & 31);
    return ret;
  }

  bech32Polymod(values) {
    const GEN = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
    let chk = 1;
    for (const v of values) {
      const b = chk >> 25;
      chk = ((chk & 0x1ffffff) << 5) ^ v;
      for (let i = 0; i < 5; i++) {
        if ((b >> i) & 1) chk ^= GEN[i];
      }
    }
    return chk;
  }

  /**
   * Send signed event to the backend publish endpoint
   */
  async sendToBackend(signedEvent, url) {
    const response = await fetch(url, {
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

  /**
   * Store the follow pack coordinate on the user's profile
   */
  async storeCoordinate(coordinate) {
    const response = await fetch(this.setCoordinateUrlValue, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({ coordinate }),
    });

    if (!response.ok) {
      const data = await response.json().catch(() => ({}));
      throw new Error(data.error || `HTTP ${response.status}`);
    }

    return response.json();
  }

  /**
   * Convert npub to hex pubkey (simple bech32 decode)
   * Uses a basic lookup since we may not have nostr-tools loaded
   */
  npubToHex(npub) {
    if (!npub || !npub.startsWith('npub1')) return null;
    try {
      // Try using nostr-tools if available
      if (window.NostrTools && window.NostrTools.nip19) {
        const { data } = window.NostrTools.nip19.decode(npub);
        return data;
      }
      // Fallback: basic bech32 decode
      return this.bech32ToHex(npub);
    } catch (e) {
      console.warn('[follow-pack] Could not decode npub:', npub, e);
      return null;
    }
  }

  /**
   * Basic bech32 to hex conversion for npub
   */
  bech32ToHex(bech32Str) {
    const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
    let hasLower = false, hasUpper = false;
    for (let i = 0; i < bech32Str.length; i++) {
      const c = bech32Str.charCodeAt(i);
      if (c >= 97 && c <= 122) hasLower = true;
      if (c >= 65 && c <= 90) hasUpper = true;
    }
    if (hasLower && hasUpper) throw new Error('Mixed case');
    const str = bech32Str.toLowerCase();
    const sepPos = str.lastIndexOf('1');
    if (sepPos < 1) throw new Error('No separator');
    const data = [];
    for (let i = sepPos + 1; i < str.length; i++) {
      const v = CHARSET.indexOf(str[i]);
      if (v === -1) throw new Error('Invalid char');
      data.push(v);
    }
    // Remove checksum (last 6 chars)
    const values = data.slice(0, -6);
    // Convert 5-bit to 8-bit
    let acc = 0, bits = 0;
    const result = [];
    for (const v of values) {
      acc = (acc << 5) | v;
      bits += 5;
      while (bits >= 8) {
        bits -= 8;
        result.push((acc >> bits) & 0xff);
      }
    }
    return result.map(b => b.toString(16).padStart(2, '0')).join('');
  }

  // ---------- UI Helpers ----------

  esc(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
  }

  showStatus(message) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, 'info', 3000);
    } else if (this.hasStatusTarget) {
      this.statusTarget.innerHTML = `<div class="alert alert-info">${message}</div>`;
    }
  }

  showSuccess(message) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, 'success', 4000);
    } else if (this.hasStatusTarget) {
      this.statusTarget.innerHTML = `<div class="alert alert-success">${message}</div>`;
    }
  }

  showError(message) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, 'danger', 8000);
    } else if (this.hasStatusTarget) {
      this.statusTarget.innerHTML = `<div class="alert alert-danger">${message}</div>`;
    }
  }
}

