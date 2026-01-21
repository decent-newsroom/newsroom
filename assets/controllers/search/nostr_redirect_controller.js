import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['input'];

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

      // Redirect based on type
      let url;
      switch (nostrType) {
        case 'npub':
          url = `/p/${normalized}`;
          break;
        case 'naddr':
          url = `/article/${normalized}`;
          break;
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
}
