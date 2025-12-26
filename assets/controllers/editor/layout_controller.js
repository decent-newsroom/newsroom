// assets/controllers/editor/layout_controller.js
import {Controller} from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'modeTab', 'editPane', 'markdownPane', 'jsonPane', 'previewPane',
        'previewBody', 'previewTitle',
        'previewSummary', 'previewImage', 'previewImagePlaceholder', 'previewAuthor', 'previewDate',
        'markdownEditor', 'markdownTitle', 'markdownCode', 'status'
    ];

    connect() {
        console.log('Editor layout controller connected');
        this.autoSaveTimer = null;

        // Live preview for summary and image fields
        const summaryInput = this.element.querySelector('textarea[name*="[summary]"], textarea[name="editor[summary]"]');
        const imageInput = this.element.querySelector('input[name*="[image]"], input[name="editor[image]"]');
        if (summaryInput) {
            summaryInput.addEventListener('input', () => this.updatePreview());
            summaryInput.addEventListener('change', () => this.updatePreview());
        }
        if (imageInput) {
            imageInput.addEventListener('input', () => this.updatePreview());
            imageInput.addEventListener('change', () => this.updatePreview());
        }

        // If editing an existing article, load JSON event by default
        if (this.element.dataset.articleId && this.hasJsonPaneTarget) {
            // Find the JSON textarea in the pane and load the event
            const jsonTextarea = this.jsonPaneTarget.querySelector('[data-editor--json-panel-target="jsonTextarea"]');
            if (jsonTextarea && !jsonTextarea.value.trim()) {
                // Try to get the Nostr publish controller's JSON
                const nostrController = this.application.getControllerForElementAndIdentifier(
                    this.element.querySelector('[data-controller*="nostr--nostr-publish"]'),
                    'nostr--nostr-publish'
                );
                if (nostrController && nostrController.hasJsonTextareaTarget) {
                    jsonTextarea.value = nostrController.jsonTextareaTarget.value;
                    // Optionally, trigger formatting/validation if needed
                }
            }
        }

        // Listen for content changes from Quill or Markdown
        this.element.addEventListener('content:changed', () => {
            this.updatePreview();
            // Update Quill pane live
            const markdownInput = this.element.querySelector('textarea[name="editor[content]"]');
            if (markdownInput && window.appQuill) {
                if (window.marked) {
                    window.appQuill.root.innerHTML = window.marked.parse(markdownInput.value || '');
                } else {
                    fetch('/editor/markdown/preview', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ markdown: markdownInput.value || '' })
                    })
                    .then(resp => resp.ok ? resp.json() : { html: '' })
                    .then(data => { window.appQuill.root.innerHTML = data.html || ''; });
                }
            }
            // If JSON pane is present, update it as well
            if (this.hasJsonPaneTarget) {
                const jsonTextarea = this.jsonPaneTarget.querySelector('[data-editor--json-panel-target="jsonTextarea"]');
                if (jsonTextarea && window.nostrPublishController && typeof window.nostrPublishController.regenerateJsonPreview === 'function') {
                    window.nostrPublishController.regenerateJsonPreview();
                }
            }
        });
    }

    switchMode(event) {
        const mode = event.currentTarget.dataset.mode;

        // Update tab states
        this.modeTabTargets.forEach(tab => {
            tab.classList.toggle('is-active', tab.dataset.mode === mode);
        });

        // Toggle panes - hide all, then show the selected one
        this.editPaneTarget.classList.toggle('is-hidden', mode !== 'edit');
        this.markdownPaneTarget.classList.toggle('is-hidden', mode !== 'markdown');
        this.jsonPaneTarget.classList.toggle('is-hidden', mode !== 'json');
        this.previewPaneTarget.classList.toggle('is-hidden', mode !== 'preview');

        // Update content when switching modes
        if (mode === 'markdown') {
            this.updateMarkdown();
        } else if (mode === 'edit') {
            // Sync Markdown to Quill when switching to Quill pane
            const markdownInput = this.element.querySelector('textarea[name="editor[content]"]');
            if (markdownInput && window.appQuill) {
                if (window.marked) {
                    window.appQuill.root.innerHTML = window.marked.parse(markdownInput.value || '');
                } else {
                    // Fallback: use backend endpoint
                    fetch('/editor/markdown/preview', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ markdown: markdownInput.value || '' })
                    })
                    .then(resp => resp.ok ? resp.json() : { html: '' })
                    .then(data => { window.appQuill.root.innerHTML = data.html || ''; });
                }
            }
        } else if (mode === 'preview') {
            this.updatePreview();
        } else if (mode === 'json') {
            // Optionally, trigger JSON formatting/validation
        }
    }

    updateMarkdown() {
        // Get title from form
        const titleInput = this.element.querySelector('input[name*="[title]"]');
        if (titleInput && this.hasMarkdownTitleTarget) {
            this.markdownTitleTarget.value = titleInput.value || '';
        }

        // Get markdown from Quill controller
        const markdownInput = this.element.querySelector('textarea[name="editor[content]"]');
        const markdown = markdownInput ? markdownInput.value || '' : '';

        // Set code block content and highlight
        if (this.hasMarkdownCodeTarget) {
            this.markdownCodeTarget.textContent = markdown;
            if (window.Prism && Prism.highlightElement) {
                Prism.highlightElement(this.markdownCodeTarget);
            }
        }
    }

    async updatePreview() {
        if (!this.hasPreviewBodyTarget) return;

        // Get title from form
        const titleInput = this.element.querySelector('input[name*="[title]"], input[name="editor[title]"]');
        const summaryInput = this.element.querySelector('textarea[name*="[summary]"], textarea[name="editor[summary]"]');
        const imageInput = this.element.querySelector('input[name*="[image]"], input[name="editor[image]"]');
        const markdownInput = this.element.querySelector('textarea[name="editor[content]"]');
        const authorInput = this.element.querySelector('input[name*="[author]"]');
        const dateInput = this.element.querySelector('input[name*="[publishedAt]"]') || this.element.querySelector('input[name*="[createdAt]"]');

        // Title
        const title = titleInput ? titleInput.value.trim() : '';
        if (this.hasPreviewTitleTarget) {
            this.previewTitleTarget.textContent = title || 'Article title';
        }

        // Author (placeholder logic)
        if (this.hasPreviewAuthorTarget) {
            let author = authorInput ? authorInput.value.trim() : '';
            this.previewAuthorTarget.textContent = author || 'Author';
        }

        // Date (placeholder logic)
        if (this.hasPreviewDateTarget) {
          const now = new Date();
          this.previewDateTarget.textContent = now.toLocaleDateString(undefined, {
              year: 'numeric',
              month: 'long',
              day: 'numeric'
          });
        }

        // Summary (always use form value)
        const summary = summaryInput ? summaryInput.value.trim() : '';
        if (this.hasPreviewSummaryTarget) {
            this.previewSummaryTarget.textContent = summary || 'No summary provided. This is where your article summary will appear.';
            this.previewSummaryTarget.classList.toggle('placeholder', !summary);
        }

        // Image (always use form value)
        const imageUrl = imageInput ? imageInput.value.trim() : '';
        if (this.hasPreviewImageTarget && this.hasPreviewImagePlaceholderTarget) {
            if (imageUrl) {
                this.previewImageTarget.src = imageUrl;
                this.previewImageTarget.style.display = '';
                this.previewImagePlaceholderTarget.style.display = 'none';
            } else {
                this.previewImageTarget.src = '';
                this.previewImageTarget.style.display = 'none';
                this.previewImagePlaceholderTarget.style.display = '';
            }
        }

        // Body (markdown to HTML via backend)
        let html = '<p><em>Loading preview...</em></p>';
        this.previewBodyTarget.innerHTML = html;
        if (markdownInput) {
            try {
                const response = await fetch('/editor/markdown/preview', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ markdown: markdownInput.value || '' })
                });
                if (response.ok) {
                    const data = await response.json();
                    html = data.html || '<p><em>No content yet. Start writing your article!</em></p>';
                } else {
                    html = '<p><em>Failed to load preview.</em></p>';
                }
            } catch (e) {
                html = '<p><em>Error loading preview.</em></p>';
            }
            this.previewBodyTarget.innerHTML = html;
        } else {
            this.previewBodyTarget.innerHTML = '<p><em>No content yet. Start writing your article!</em></p>';
        }
    }

    saveDraft() {
        console.log('Saving draft...');

        // Mark as draft - set checkbox to true
        const draftCheckbox = this.element.querySelector('input[name*="[isDraft]"]');
        if (draftCheckbox) {
            draftCheckbox.checked = true;
        }

        // Submit the form
        const form = this.element.querySelector('form');
        if (form) {
            this.updateStatus('Saving draft...');
            form.requestSubmit();
        }
    }

    publish() {
        console.log('Publishing article...');

        // Mark as NOT draft - set checkbox to false
        const draftCheckbox = this.element.querySelector('input[name*="[isDraft]"]');
        if (draftCheckbox) {
            draftCheckbox.checked = false;
        }

        // Find the Nostr publish controller and trigger publish
        const nostrController = this.application.getControllerForElementAndIdentifier(
            this.element.querySelector('[data-controller*="nostr--nostr-publish"]'),
            'nostr--nostr-publish'
        );

        if (nostrController) {
            nostrController.publish();
        } else {
            console.error('Nostr publish controller not found');
            alert('Could not find publishing controller. Please try again.');
        }
    }


    updateStatus(message) {
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = message;

            // Clear status after 3 seconds
            setTimeout(() => {
                if (this.hasStatusTarget) {
                    this.statusTarget.textContent = '';
                }
            }, 3000);
        }
    }

    disconnect() {
        if (this.autoSaveTimer) {
            clearTimeout(this.autoSaveTimer);
        }
    }
}
