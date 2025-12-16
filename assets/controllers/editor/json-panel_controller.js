import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['jsonTextarea', 'status', 'dirtyHint'];

    connect() {
        console.log('JSON panel controller connected');
        this.isDirty = false;

        // Load initial JSON from the Nostr publish controller
        this.loadInitialJson();
    }

    loadInitialJson() {
        // Wait a bit for the Nostr publish controller to initialize
        setTimeout(() => {
            const nostrController = this.getNostrPublishController();
            if (nostrController && nostrController.hasJsonTextareaTarget) {
                const json = nostrController.jsonTextareaTarget.value;
                if (json && this.hasJsonTextareaTarget) {
                    this.jsonTextareaTarget.value = json;
                    this.formatJson();
                }
            }
        }, 500);
    }

    regenerateJson() {
        const nostrController = this.getNostrPublishController();
        if (nostrController && typeof nostrController.regenerateJsonPreview === 'function') {
            nostrController.regenerateJsonPreview();

            // Copy the regenerated JSON to our textarea
            setTimeout(() => {
                if (nostrController.hasJsonTextareaTarget && this.hasJsonTextareaTarget) {
                    this.jsonTextareaTarget.value = nostrController.jsonTextareaTarget.value;
                    this.formatJson();
                    this.isDirty = false;
                    this.updateDirtyHint();
                    this.showStatus('JSON rebuilt from form', 'success');
                }
            }, 100);
        }
    }

    onJsonInput(event) {
        this.isDirty = true;
        this.updateDirtyHint();
        this.validateJson();

        // Sync to the hidden Nostr publish textarea
        const nostrController = this.getNostrPublishController();
        if (nostrController && nostrController.hasJsonTextareaTarget) {
            nostrController.jsonTextareaTarget.value = event.target.value;
        }
    }

    validateJson() {
        if (!this.hasJsonTextareaTarget) return;

        try {
            const json = JSON.parse(this.jsonTextareaTarget.value);
            const required = ['kind', 'created_at', 'tags', 'content', 'pubkey'];
            const missing = required.filter(field => !(field in json));

            if (missing.length > 0) {
                this.showStatus(`Missing: ${missing.join(', ')}`, 'warning');
            } else {
                this.showStatus('Valid JSON', 'success');
            }
        } catch (e) {
            this.showStatus('Invalid JSON', 'error');
        }
    }

    formatJson() {
        if (!this.hasJsonTextareaTarget) return;

        try {
            const json = JSON.parse(this.jsonTextareaTarget.value);
            this.jsonTextareaTarget.value = JSON.stringify(json, null, 2);
            this.showStatus('Formatted', 'success');
        } catch (e) {
            // Silently fail if JSON is invalid
        }
    }

    showStatus(message, type = 'info') {
        if (!this.hasStatusTarget) return;

        this.statusTarget.textContent = message;
        this.statusTarget.className = `json-status json-status--${type}`;

        setTimeout(() => {
            if (this.hasStatusTarget) {
                this.statusTarget.textContent = '';
                this.statusTarget.className = 'json-status';
            }
        }, 3000);
    }

    updateDirtyHint() {
        if (this.hasDirtyHintTarget) {
            this.dirtyHintTarget.style.display = this.isDirty ? 'inline' : 'none';
        }
    }

    getNostrPublishController() {
        const element = document.querySelector('[data-controller*="nostr--nostr-publish"]');
        if (!element) return null;

        return this.application.getControllerForElementAndIdentifier(
            element,
            'nostr--nostr-publish'
        );
    }
}

