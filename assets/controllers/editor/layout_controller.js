import {Controller} from '@hotwired/stimulus';
import { deltaToMarkdown, markdownToDelta } from './conversion.js';

export default class extends Controller {
    static targets = [
        'modeTab', 'editPane', 'markdownPane', 'jsonPane', 'previewPane',
        'previewBody', 'previewTitle',
        'previewSummary', 'previewImage', 'previewImagePlaceholder', 'previewAuthor', 'previewDate',
        'markdownEditor', 'markdownTitle', 'markdownCode', 'status',
        'saveDraftSubmit', 'publishSubmit', 'jsonCode'
    ];

    connect() {
        console.log('Editor layout controller connected');
        this.autoSaveTimer = null;

        // --- Editor State Object ---
        // See documentation/Editor/Reactivity-and-state-management.md
        this.state = {
            active_source: 'md',   // Markdown is authoritative on load
            content_delta: null,    // Quill Delta (object)
            content_NMD: '',        // Markdown string
            content_event_json: {}  // Derived event JSON
        };
        this.hydrateState();
        this.updateMarkdownEditor();
        this.updateQuillEditor();

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

        // Listen for content changes from Quill or Markdown
        this.element.addEventListener('content:changed', () => {
            this.updatePreview().then(r => console.log('Preview updated after content change', r));
        });

        // Auto-sync content when Quill editor loses focus
        this.setupQuillBlurSync();

        this.setupAuthModal();
    }

    setupQuillBlurSync() {
        // Wait for Quill to be available and set up blur event
        const setupBlurListener = () => {
            if (window.appQuill && window.appQuill.root) {
                this.quillBlurHandler = () => {
                    console.log('[Editor] Quill lost focus, auto-syncing content');
                    this.syncContentBeforePublish();
                };
                window.appQuill.root.addEventListener('blur', this.quillBlurHandler);
                console.log('[Editor] Quill blur sync listener set up');
            } else {
                // Retry after a short delay if Quill isn't ready yet
                setTimeout(setupBlurListener, 100);
            }
        };
        setupBlurListener();
    }

    setupAuthModal() {
        this.authModal = document.getElementById('auth-choice-modal');
        this.signerBtn = document.getElementById('proceed-with-signer');
        this.extensionBtn = document.getElementById('proceed-with-extension');
        this.isAnon = !window.appUser || !window.appUser.isAuthenticated; // You may need to set window.appUser in base.html.twig
        if (this.signerBtn) {
            this.signerBtn.addEventListener('click', () => {
                this.hideAuthModal();
                this.startPublishWith('signer');
            });
        }
        if (this.extensionBtn) {
            this.extensionBtn.addEventListener('click', () => {
                this.hideAuthModal();
                this.startPublishWith('extension');
            });
        }
    }

    showAuthModal() {
        if (this.authModal) this.authModal.style.display = 'block';
    }
    hideAuthModal() {
        if (this.authModal) this.authModal.style.display = 'none';
    }

    hydrateState() {
        // Always hydrate from Markdown (content_NMD) on load
        // (Assume hidden field with ID: contentNMD or textarea[name="editor[content]"])
        let nmd = '';
        const nmdField = document.getElementById('contentNMD');
        if (nmdField && nmdField.value) {
            nmd = nmdField.value;
        } else {
            // Fallback: try textarea
            const mdTextarea = this.element.querySelector('textarea[name="editor[content]"]');
            if (mdTextarea) nmd = mdTextarea.value;
        }
        this.state.content_NMD = nmd;
        this.state.content_delta = this.nmdToDelta(nmd);
        this.state.active_source = 'md';
    }

    persistState() {
        // Save state to localStorage and hidden fields
        localStorage.setItem('editorState', JSON.stringify(this.state));
        const deltaField = document.getElementById('contentDelta');
        const nmdField = document.getElementById('contentNMD');
        if (deltaField) deltaField.value = JSON.stringify(this.state.content_delta || {});
        if (nmdField) nmdField.value = this.state.content_NMD || '';
    }

    // --- Tab Switching Logic ---
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
        if (mode === 'markdown' && this.state.active_source === 'quill') {
            // Convert Delta to NMD
            this.state.content_NMD = this.deltaToNMD(this.state.content_delta);
            this.state.active_source = 'md';
            this.updateMarkdownEditor();
        } else if (mode === 'edit') {
            // Always convert latest Markdown to Delta and update Quill
            // (regardless of previous active_source)
            // Get latest Markdown from textarea or CodeMirror
            let nmd = '';
            const markdownInput = this.element.querySelector('textarea[name="editor[content]"]');
            if (markdownInput && markdownInput._codemirror) {
                nmd = markdownInput._codemirror.state.doc.toString();
            } else if (markdownInput) {
                nmd = markdownInput.value;
            } else {
                nmd = this.state.content_NMD;
            }
            this.state.content_NMD = nmd;
            this.state.content_delta = this.nmdToDelta(nmd);
            this.state.active_source = 'quill';
            this.updateQuillEditor();
        } else if (mode === 'preview') {
            this.updatePreview().then(r => console.log('Preview updated', r));
        } else if (mode === 'json') {
            // Not doing anything here for now
        }
        this.persistState();
        this.emitContentChanged();
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
        // Only for mobile actions, not header
        alert('[Editor] saveDraft called');

        // Mark as draft - set checkbox to true
        const draftCheckbox = this.element.querySelector('input[name*="[isDraft]"]');
        if (draftCheckbox) {
            draftCheckbox.checked = true;
        } else {
            console.warn('[Editor] Draft checkbox not found');
        }

        // Submit the form
        const form = this.element.querySelector('form');
        if (form) {
            this.updateStatus('Saving draft...');
            form.requestSubmit();
            console.log('[Editor] Form submitted for draft');
        } else {
            console.error('[Editor] Form not found for saveDraft');
        }
    }

    publish() {
        // Only for mobile actions, not header
        alert('[Editor] publish called');

        // Mark as NOT draft - set checkbox to false
        const draftCheckbox = this.element.querySelector('input[name*="[isDraft]"]');
        if (draftCheckbox) {
            draftCheckbox.checked = false;
        } else {
            console.warn('[Editor] Draft checkbox not found');
        }

        // Find the Nostr publish controller and trigger publish
        const nostrController = this.application.getControllerForElementAndIdentifier(
            this.element.querySelector('[data-controller*="nostr--nostr-publish"]'),
            'nostr--nostr-publish'
        );

        if (nostrController && typeof nostrController.publish === 'function') {
            console.log('[Editor] Nostr publish controller found, calling publish()');
            nostrController.publish();
        } else {
            // Fallback: submit the form
            const form = this.element.querySelector('form');
            if (form) {
                this.updateStatus('Publishing...');
                form.requestSubmit();
                console.log('[Editor] Form submitted for publish');
            } else {
                console.error('[Editor] Form not found for publish');
                alert('Could not find publishing controller or form. Please try again.');
            }
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
        // Clean up Quill blur listener
        if (this.quillBlurHandler && window.appQuill && window.appQuill.root) {
            window.appQuill.root.removeEventListener('blur', this.quillBlurHandler);
        }
    }

    // --- Content Update Handlers ---
    onQuillChange(delta) {
        this.state.content_delta = delta;
        this.state.active_source = 'quill';
        this.persistState();
        this.emitContentChanged();
    }
    onMarkdownChange(nmd) {
        this.state.content_NMD = nmd;
        this.state.active_source = 'md';
        this.persistState();
        this.emitContentChanged();
    }

    // --- Editor Sync Helpers ---
    syncContentBeforePublish() {
        // If the active source is Quill, convert delta to markdown and update the form field
        if (this.state.active_source === 'quill' && this.state.content_delta) {
            this.state.content_NMD = this.deltaToNMD(this.state.content_delta);
            this.updateMarkdownEditor();
            console.log('[Editor] Synced Quill content to markdown');
        }
    }

    updateMarkdownEditor() {
        // Set Markdown editor value from state.content_NMD
        const markdownInput = this.element.querySelector('textarea[name="editor[content]"]');
        if (markdownInput) markdownInput.value = this.state.content_NMD || '';
        // If using CodeMirror, update its doc as well
        if (markdownInput && markdownInput._codemirror) {
            markdownInput._codemirror.dispatch({
                changes: { from: 0, to: markdownInput._codemirror.state.doc.length, insert: this.state.content_NMD || '' }
            });
        }
    }
    updateQuillEditor() {
        // Set Quill editor value from state.content_delta
        if (window.appQuill && this.state.content_delta) {
            window.appQuill.setContents(this.state.content_delta);
        }
    }

    // --- Conversion Stubs (implement via DNIR pipeline) ---
    deltaToNMD(delta) {
        // Use conversion pipeline
        return deltaToMarkdown(delta);
    }
    nmdToDelta(nmd) {
        // Use conversion pipeline
        console.log('Converting NMD to Delta:', nmd);
        console.log('Converted Delta:', markdownToDelta(nmd));
        return markdownToDelta(nmd);
    }

    emitContentChanged() {
        // Emit a custom event with the new state
        this.element.dispatchEvent(new CustomEvent('content:changed', {
            detail: { ...this.state },
            bubbles: true
        }));
    }
}

