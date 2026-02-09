import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['publishButton', 'status'];
    static values = {
        publishUrl: String,
        csrfToken: String,
        eventData: Object
    };

    connect() {
        console.log('Tabular data publish controller connected');
    }

    async publish(event) {
        event.preventDefault();

        if (!this.publishUrlValue) {
            this.showError('Publish URL is not configured');
            return;
        }
        if (!this.csrfTokenValue) {
            this.showError('Missing CSRF token');
            return;
        }

        if (!window.nostr) {
            this.showError('Nostr extension not found');
            return;
        }

        // Request pubkey first - required by some extensions (e.g., nos2x-fox) to determine active profile
        this.showStatus('Requesting public key from Nostr extension...');
        let pubkey;
        try {
            pubkey = await window.nostr.getPublicKey();
        } catch (e) {
            this.showError('Failed to get public key: ' + e.message);
            return;
        }

        this.publishButtonTarget.disabled = true;
        this.showStatus('Requesting signature from Nostr extension...');

        try {
            // Prepare the event data
            const eventData = this.eventDataValue;
            delete eventData.sig; // Remove sig if present
            delete eventData.id; // Remove id if present
            eventData.pubkey = pubkey; // Set pubkey from extension

            // Sign the event with Nostr extension
            const signedEvent = await window.nostr.signEvent(eventData);

            this.showStatus('Publishing tabular data...');

            // Send to backend
            await this.sendToBackend(signedEvent);

            this.showSuccess('Tabular data published successfully!');

            // Redirect to the event page
            setTimeout(() => {
                window.location.href = `/e/${signedEvent.id}`;
            }, 2000);

        } catch (error) {
            console.error('Publishing error:', error);
            this.showError(`Publishing failed: ${error.message}`);
        } finally {
            this.publishButtonTarget.disabled = false;
        }
    }

    async sendToBackend(signedEvent) {
        const response = await fetch(this.publishUrlValue, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': this.csrfTokenValue
            },
            body: JSON.stringify({
                event: signedEvent
            })
        });

        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}));
            throw new Error(errorData.message || `HTTP ${response.status}: ${response.statusText}`);
        }

        return await response.json();
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
