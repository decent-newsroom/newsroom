import { Controller } from '@hotwired/stimulus';

/**
 * Chat groups controller — handles group creation with client-side signing for self-sovereign users.
 *
 * data-controller="chat--groups"
 */
export default class extends Controller {
    static targets = ['form', 'status'];
    static values = {
        communityId: String,
        createUrl: String,
        csrfToken: String,
    };

    connect() {
        // Subscribe to live unread badges (future)
    }

    disconnect() {
        // Clean up any EventSource
    }

    async submitForm(event) {
        event.preventDefault();

        if (!this.createUrlValue) {
            this.showError('Create URL is not configured');
            return;
        }

        const formData = new FormData(this.formTarget);
        const name = formData.get('name');
        const slug = formData.get('slug');

        if (!name || !slug) {
            this.showError('Name and slug are required');
            return;
        }

        this.showStatus('Creating group...');

        try {
            // Convert FormData to URLSearchParams, including CSRF token
            const params = new URLSearchParams();
            params.append('name', name);
            params.append('slug', slug);
            params.append('_token', this.csrfTokenValue);

            const response = await fetch(this.createUrlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: params.toString()
            });

            if (!response.ok) {
                const errorData = await response.text();
                throw new Error(`HTTP ${response.status}`);
            }

            // Check if response is JSON (self-sovereign with unsigned event) or HTML redirect (custodial)
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                // Self-sovereign user: sign the unsigned event
                const data = await response.json();
                await this.signAndPublishChannelEvent(data);
            } else {
                // Custodial user, server handled it
                this.showSuccess('Group created successfully!');
                setTimeout(() => window.location.reload(), 1500);
            }
        } catch (error) {
            console.error('Group creation error:', error);
            this.showError(`Group creation failed: ${error.message}`);
        }
    }

    async signAndPublishChannelEvent(data) {
        if (!window.nostr) {
            this.showError('Nostr extension not found. Install a Nostr extension to sign this event.');
            return;
        }

        try {
            this.showStatus('Requesting public key from Nostr extension...');
            const pubkey = await window.nostr.getPublicKey();

            const unsignedEvent = data.unsignedEvent;
            unsignedEvent.pubkey = pubkey;

            this.showStatus('Requesting signature from Nostr extension...');
            const signedEvent = await window.nostr.signEvent(unsignedEvent);

            this.showStatus('Publishing group to relay...');

            // Build the publish URL
            const publishUrl = this.createUrlValue.replace('/create', `/${data.groupId}/publish-channel-event`);

            const publishResponse = await fetch(publishUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': this.csrfTokenValue
                },
                body: JSON.stringify({ signedEvent })
            });

            if (!publishResponse.ok) {
                const errorData = await publishResponse.json().catch(() => ({}));
                throw new Error(errorData.error || `HTTP ${publishResponse.status}`);
            }

            this.showSuccess('Group created and published successfully!');
            setTimeout(() => window.location.reload(), 1500);
        } catch (error) {
            console.error('Signing/publishing error:', error);
            this.showError(`Failed to sign and publish: ${error.message}`);
        }
    }

    showStatus(message) {
        if (this.hasStatusTarget) {
            this.statusTarget.innerHTML = `<div class="alert alert-info">${this.escapeHtml(message)}</div>`;
        }
    }

    showSuccess(message) {
        if (this.hasStatusTarget) {
            this.statusTarget.innerHTML = `<div class="alert alert-success">${this.escapeHtml(message)}</div>`;
        }
    }

    showError(message) {
        if (this.hasStatusTarget) {
            this.statusTarget.innerHTML = `<div class="alert alert-danger">${this.escapeHtml(message)}</div>`;
        }
    }

    escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }
}

