import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['jsonTextarea', 'status', 'dirtyHint'];

    connect() {
        console.log('JSON panel controller connected');
        this.isDirty = false;

        // Listen for the custom event from the Nostr publish controller
        document.addEventListener('nostr-json-ready', this.handleNostrJsonReady.bind(this));

        // Listen for changes in the markdown textarea
        const md = this.getMarkdownTextarea();
        if (md) {
            md.addEventListener('input', this.handleMarkdownInput.bind(this));
        }

        // Load initial JSON from the Nostr publish controller
        this.loadInitialJson();
    }

    disconnect() {
        // Clean up event listener
        document.removeEventListener('nostr-json-ready', this.handleNostrJsonReady.bind(this));
        const md = this.getMarkdownTextarea();
        if (md) {
            md.removeEventListener('input', this.handleMarkdownInput.bind(this));
        }
    }

    handleMarkdownInput() {
        // When markdown changes, update the JSON content field and panel
        this.updateJsonContentFromMarkdown();
    }

    updateJsonContentFromMarkdown() {
        if (!this.hasJsonTextareaTarget) return;
        let json;
        try {
            json = JSON.parse(this.jsonTextareaTarget.value);
        } catch (e) {
            return; // Don't update if JSON is invalid
        }
        const md = this.getMarkdownTextarea();
        if (md) {
            json.content = md.value;
            this.jsonTextareaTarget.value = JSON.stringify(json, null, 2);
            this.formatJson();
        }
    }

    getMarkdownTextarea() {
        // Try common selectors for the markdown textarea
        return document.querySelector('#editor_content, textarea[name="editor[content]"]');
    }

    handleNostrJsonReady(event) {
        const nostrController = this.getNostrPublishController();
        if (nostrController && nostrController.hasJsonTextareaTarget && this.hasJsonTextareaTarget) {
            this.jsonTextareaTarget.value = nostrController.jsonTextareaTarget.value;
            this.updateJsonContentFromMarkdown();
            this.formatJson();
            this.isDirty = false;
            this.updateDirtyHint();
            this.showStatus('JSON updated', 'success');
        }
    }

    loadInitialJson() {
        // Wait a bit for the Nostr publish controller to initialize
        setTimeout(() => {
            const nostrController = this.getNostrPublishController();
            if (nostrController && nostrController.hasJsonTextareaTarget) {
                const json = nostrController.jsonTextareaTarget.value;
                if (json && this.hasJsonTextareaTarget) {
                    this.jsonTextareaTarget.value = json;
                    this.updateJsonContentFromMarkdown();
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
