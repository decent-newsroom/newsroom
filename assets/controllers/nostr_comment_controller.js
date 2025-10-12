import { Controller } from '@hotwired/stimulus';
import { getSigner } from './signer_manager.js';

// NIP-22 Comment Publishing Controller
// Usage: Attach to a form with data attributes for root/parent context
export default class extends Controller {
    static targets = ['publishButton', 'status'];
    static values = {
        publishUrl: String,
        csrfToken: String
    };

    connect() {
        console.log('Nostr comment controller connected');
        try {
            console.debug('[nostr-comment] publishUrl:', this.publishUrlValue || '(none)');
            console.debug('[nostr-comment] has csrfToken:', Boolean(this.csrfTokenValue));
        } catch (_) {}
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
        let signer;
        try {
            signer = await getSigner();
        } catch (e) {
            this.showError('No Nostr signer available. Please connect Amber or install a Nostr signer extension.');
            return;
        }

        this.publishButtonTarget.disabled = true;
        this.showStatus('Preparing comment for signing...');

        try {
            // Collect form data and context
            const formData = this.collectFormData();

            // Validate required fields
            if (!formData.content) {
                throw new Error('Comment content is required');
            }
            if (!formData.root || !formData.parent) {
                throw new Error('Missing root or parent context');
            }
            if (!this.isPlaintext(formData.content)) {
                throw new Error('Comment must be plaintext (no formatting)');
            }

            // Create NIP-22 event
            const nostrEvent = await this.createNip22Event(formData, signer);

            this.showStatus('Requesting signature from Nostr signer...');
            const signedEvent = await signer.signEvent(nostrEvent);

            this.showStatus('Publishing comment...');
            await this.sendToBackend(signedEvent, formData);

            this.showSuccess('Comment published successfully!');
            // Optionally reload or clear form
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } catch (error) {
            console.error('Publishing error:', error);
            this.showError(`Publishing failed: ${error.message}`);
        } finally {
            this.publishButtonTarget.disabled = false;
        }
    }

    collectFormData() {
        // Use the form element directly (this.element is the <form>)
        const form = this.element;
        if (!form) {
            throw new Error('Form element not found');
        }
        const formData = new FormData(form);
        // Comment content (plaintext only)
        const content = (formData.get('comment[content]') || '').trim();
        // Root and parent context (JSON in hidden fields or data attributes)
        let root, parent;
        try {
            root = JSON.parse(formData.get('comment[root]') || this.element.dataset.root || '{}');
            parent = JSON.parse(formData.get('comment[parent]') || this.element.dataset.parent || '{}');
        } catch (_) {
            throw new Error('Invalid root/parent context');
        }
        return { content, root, parent };
    }

    isPlaintext(text) {
        // No HTML tags, no Markdown formatting
        return !(/[<>\*\_\`\[\]#]/.test(text));
    }

    async createNip22Event({ content, root, parent }, signer) {
        // Get user's public key
        const pubkey = await signer.getPublicKey();
        const created_at = Math.floor(Date.now() / 1000);
        // Build tags according to NIP-22
        const tags = [];
        // Root tags (uppercase)
        if (root.tag && root.value) {
            const rootTag = [root.tag.toUpperCase(), root.value];
            if (root.relay) rootTag.push(root.relay);
            if (root.pubkey) rootTag.push(root.pubkey);
            tags.push(rootTag);
        }
        if (root.kind) tags.push(['K', String(root.kind)]);
        if (root.pubkey) tags.push(['P', root.pubkey, root.relay || '']);
        // Parent tags (lowercase)
        if (parent.tag && parent.value) {
            const parentTag = [parent.tag.toLowerCase(), parent.value];
            if (parent.relay) parentTag.push(parent.relay);
            if (parent.pubkey) parentTag.push(parent.pubkey);
            tags.push(parentTag);
        }
        if (parent.kind) tags.push(['k', String(parent.kind)]);
        if (parent.pubkey) tags.push(['p', parent.pubkey, parent.relay || '']);
        // NIP-22 event
        return {
            kind: 1111,
            created_at,
            tags,
            content,
            pubkey
        };
    }

    async sendToBackend(signedEvent, formData) {
        const response = await fetch(this.publishUrlValue, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': this.csrfTokenValue
            },
            body: JSON.stringify({
                event: signedEvent,
                formData: formData
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
