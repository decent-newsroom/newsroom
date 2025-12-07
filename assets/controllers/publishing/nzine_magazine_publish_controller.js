import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['status', 'publishButton'];
  static values = {
    categoryEvents: String,
    magazineEvent: String,
    publishUrl: String,
    nzineSlug: String,
    csrfToken: String
  };

  async publish(event) {
    event.preventDefault();

    if (!this.publishUrlValue || !this.csrfTokenValue || !this.nzineSlugValue) {
      this.showError('Missing configuration');
      return;
    }

    this.publishButtonTarget.disabled = true;

    try {
      const categoryEvents = JSON.parse(this.categoryEventsValue || '[]');
      const magazineEvent = JSON.parse(this.magazineEventValue || '{}');

      this.showStatus('Publishing magazine and categories...');

      const response = await fetch(this.publishUrlValue, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': this.csrfTokenValue,
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
          nzineSlug: this.nzineSlugValue,
          categoryEvents: categoryEvents,
          magazineEvent: magazineEvent
        })
      });

      if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        throw new Error(data.error || `HTTP ${response.status}`);
      }

      const result = await response.json();
      this.showSuccess(result.message || 'Magazine published successfully!');

      // Redirect to home or magazine page after a short delay
      setTimeout(() => {
        window.location.href = '/';
      }, 2000);

    } catch (e) {
      console.error(e);
      this.showError(e.message || 'Publish failed');
    } finally {
      this.publishButtonTarget.disabled = false;
    }
  }

  showStatus(message) {
    if (this.hasStatusTarget) {
      this.statusTarget.innerHTML = `<div class="alert alert-info">${message}</div>`;
    }
  }

  showSuccess(message) {
    if (this.hasStatusTarget) {
      this.statusTarget.innerHTML = `<div class="alert alert-success">${message}</div>`;
    }
  }

  showError(message) {
    if (this.hasStatusTarget) {
      this.statusTarget.innerHTML = `<div class="alert alert-danger">${message}</div>`;
    }
  }
}

