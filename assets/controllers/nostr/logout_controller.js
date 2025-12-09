import { Controller } from '@hotwired/stimulus';
import { clearRemoteSignerSession } from './signer_manager.js';

/**
 * Handles logout and clears remote signer session
 * Usage: Add data-controller="nostr--logout" to logout link
 * and data-action="click->nostr--logout#handleLogout"
 */
export default class extends Controller {
  handleLogout(event) {
    console.log('[logout] Clearing remote signer session');
    clearRemoteSignerSession();
    // Allow the default logout action to continue
  }
}

