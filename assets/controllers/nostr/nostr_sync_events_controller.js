import { Controller } from '@hotwired/stimulus';

/**
 * Sync Events Controller
 *
 * Triggers an async relay sync from the settings page, reusing the same
 * pipeline that runs on login (UpdateRelayListMessage → SyncUserEventsMessage).
 */
export default class extends Controller {
  static targets = ['button', 'status'];
  static values = { syncUrl: String };

  async sync(event) {
    event.preventDefault();

    if (this.hasButtonTarget) {
      this.buttonTarget.disabled = true;
    }
    this.showStatus('syncing');

    try {
      const response = await fetch(this.syncUrlValue, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
      });

      const data = await response.json();

      if (response.ok && data.success) {
        this.showStatus('success');
        // Reload after a short delay so the user sees fresh data
        setTimeout(() => window.location.reload(), 2000);
      } else {
        this.showStatus('error', data.error || 'Sync failed');
      }
    } catch (e) {
      console.error('[sync-events] Error:', e);
      this.showStatus('error', e.message);
    } finally {
      if (this.hasButtonTarget) {
        this.buttonTarget.disabled = false;
      }
    }
  }

  showStatus(state, message) {
    if (!this.hasStatusTarget) return;

    const el = this.statusTarget;
    el.classList.remove('hidden');

    switch (state) {
      case 'syncing':
        el.textContent = 'Syncing events from your relays…';
        el.className = 'settings-sync__status settings-sync__status--syncing';
        break;
      case 'success':
        el.textContent = 'Sync started! Your events will be refreshed shortly.';
        el.className = 'settings-sync__status settings-sync__status--success';
        break;
      case 'error':
        el.textContent = message || 'Sync failed. Please try again.';
        el.className = 'settings-sync__status settings-sync__status--error';
        break;
    }
  }
}

