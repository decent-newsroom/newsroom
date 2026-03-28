import { Controller } from '@hotwired/stimulus';
import { decodeNip19 } from '../../typescript/nostr-utils.ts';

export default class extends Controller {
  static targets = ['input', 'status'];

  connect() {
    this.validPrefixes = ['npub1', 'naddr1', 'nevent1', 'note1', 'nprofile1'];
  }

  submit(event) {
    const value = this.inputTarget.value.trim();

    // Remove nostr: prefix if present
    const normalized = value.startsWith('nostr:') ? value.substring(6) : value;

    // Check if it's a Nostr identifier
    const nostrType = this.getNostrIdentifierType(normalized);

    if (nostrType) {
      event.preventDefault();

      // For naddr/nevent, decode and validate before redirect
      if (nostrType === 'naddr' || nostrType === 'nevent') {
        const decoded = decodeNip19(normalized);
        if (!decoded) {
          this._showStatus('Invalid Nostr address — could not decode.', 'error');
          return;
        }

        if (nostrType === 'naddr') {
          const { pubkey, kind, identifier } = decoded.data;
          if (!pubkey || kind === undefined || identifier === undefined) {
            this._showStatus('Incomplete Nostr address — missing required fields.', 'error');
            return;
          }
        }

        // Show relay lookup feedback
        const relayCount = decoded.data.relays?.length || 0;
        const relayHint = relayCount > 0
          ? `Found ${relayCount} relay${relayCount > 1 ? 's' : ''} in the address — looking up event…`
          : 'Looking up event…';
        this._showStatus(relayHint, 'info');
      }

      // Redirect based on type
      let url;
      switch (nostrType) {
        case 'npub':
          url = `/p/${normalized}`;
          break;
        case 'naddr':
        case 'nevent':
        case 'note':
        case 'nprofile':
          url = `/e/${normalized}`;
          break;
        default:
          return; // Let form submit normally
      }

      window.location.href = url;
    }
    // If not a Nostr identifier, let the form submit normally
  }

  getNostrIdentifierType(identifier) {
    for (const prefix of this.validPrefixes) {
      if (identifier.startsWith(prefix)) {
        return prefix.replace('1', ''); // Return type without the '1'
      }
    }
    return null;
  }

  /**
   * Show an inline status message below the search input.
   * Creates the element dynamically if no status target exists.
   */
  _showStatus(message, type = 'info') {
    let statusEl;

    if (this.hasStatusTarget) {
      statusEl = this.statusTarget;
    } else {
      // Create status element dynamically next to the input
      statusEl = document.createElement('div');
      statusEl.classList.add('search-status');
      statusEl.setAttribute(`data-${this.identifier}-target`, 'status');
      // Insert after the form or input's parent label
      const insertAfter = this.inputTarget.closest('label') || this.inputTarget;
      insertAfter.parentNode.insertBefore(statusEl, insertAfter.nextSibling);
    }

    statusEl.textContent = message;
    statusEl.className = `search-status search-status--${type}`;
    statusEl.style.display = '';
  }
}
