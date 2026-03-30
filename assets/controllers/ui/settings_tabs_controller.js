import { Controller } from '@hotwired/stimulus';

/**
 * Settings Tabs Controller
 *
 * Simple tab switching for the settings page.
 * Reads/writes the active tab to the URL hash for deep-linking.
 */
export default class extends Controller {
  static targets = ['tab', 'panel'];

  connect() {
    // Restore tab from URL hash
    const hash = window.location.hash.replace('#', '');
    if (hash && this.panelTargets.find(p => p.dataset.panel === hash)) {
      this.activateTab(hash);
    }
  }

  switchTab(event) {
    event.preventDefault();
    const tab = event.currentTarget.dataset.tab;
    this.activateTab(tab);
    window.location.hash = tab;
  }

  switchToProfile(event) {
    event.preventDefault();
    this.activateTab('profile');
    window.location.hash = 'profile';
  }

  switchToRelays(event) {
    event.preventDefault();
    this.activateTab('relays');
    window.location.hash = 'relays';
  }

  activateTab(tabName) {
    this.tabTargets.forEach(t => {
      t.classList.toggle('active', t.dataset.tab === tabName);
    });
    this.panelTargets.forEach(p => {
      p.classList.toggle('active', p.dataset.panel === tabName);
    });
  }
}

