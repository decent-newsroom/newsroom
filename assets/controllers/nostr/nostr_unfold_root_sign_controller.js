import { Controller } from '@hotwired/stimulus';
import { getSigner } from './signer_manager.js';

export default class extends Controller {
    static targets = ['publishButton', 'status'];

    static values = {
        prepareUrl: String,
        commitUrl: String,
        dtag: String,
        csrfToken: String,
    };

    async publish() {
        if (this.publishing) {
            return;
        }

        this.publishing = true;
        this.publishButtonTarget.disabled = true;
        try {
            this.show('Preparing publication...');
            const prepared = await this.post(this.prepareUrlValue, this.payload());
            if (!prepared.event) {
                throw new Error('Could not prepare the publication.');
            }

            this.show('Connecting to signer...');
            const signer = await getSigner();
            const pubkey = await signer.getPublicKey();
            if (pubkey.toLowerCase() !== prepared.event.pubkey.toLowerCase()) {
                throw new Error('The signer does not match this publication owner.');
            }

            this.show('Sign the publication in your signer...');
            const event = await signer.signEvent(prepared.event);
            if (!event?.id || !event?.sig) {
                throw new Error('The signer returned an invalid event.');
            }

            this.show('Publishing publication...');
            const committed = await this.post(this.commitUrlValue, { ...this.payload(), event });
            if (committed.ok !== true) {
                throw new Error('Could not publish the publication.');
            }

            this.show(committed.published ? 'Publication published.' : 'Publication saved locally; relay publication can be retried later.');
            window.location.assign(committed.admin_url);
        } catch (error) {
            this.show(error.message || 'Publication signing failed.', true);
        } finally {
            this.publishing = false;
            this.publishButtonTarget.disabled = false;
        }
    }

    payload() {
        return { dtag: this.dtagValue, _token: this.csrfTokenValue };
    }

    async post(url, payload) {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(payload),
        });
        const body = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(body.error || 'Request failed.');
        }

        return body;
    }

    show(message, error = false) {
        this.statusTarget.textContent = message;
        this.statusTarget.hidden = false;
        this.statusTarget.setAttribute('role', error ? 'alert' : 'status');
    }
}
