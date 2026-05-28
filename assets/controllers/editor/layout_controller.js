import {Controller} from '@hotwired/stimulus';
import { deltaToMarkdown, markdownToDelta } from './conversion.js';
import renderMathInElement from 'katex/dist/contrib/auto-render.mjs';
import { hasRealMath, normalizeDollarMathInTextNodes } from '../utility/katex_controller.js';

export default class extends Controller {
    static targets = [
        'modeTab', 'editPane', 'markdownPane', 'jsonPane', 'previewPane',
        'previewBody', 'previewTitle',
        'previewSummary', 'previewImage', 'previewImagePlaceholder', 'previewAuthor', 'previewDate',
        'markdownEditor', 'richTextTitle', 'markdownTitle', 'markdownCode', 'status',
        'saveDraftSubmit', 'publishSubmit', 'jsonCode',
        'articleListSidebar', 'editorSidebar', 'mobileArticleListToggle', 'mobileSidebarToggle', 'mobileLayoutControls'
    ];

    connect() {
        console.log('Editor layout controller connected');
        this.autoSaveTimer = null;
        this.lastConversionWarningAt = 0;
        this.mobilePanelsVisible = {
            articleList: true,
            settings: true,
        };

        // Cache of npub → display name, populated from Quill blots and API lookups.
        // Survives tab switches so names don't need re-fetching.
        this.mentionNameCache = {};

        // Expose method globally so login controllers can save state before reload
        window.saveEditorStateBeforeLogin = () => this.saveCompleteStateBeforeLogin();

        // --- Editor State Object ---
        // See documentation/Editor/Reactivity-and-state-management.md
        this.state = {
            active_source: 'md',   // Markdown is authoritative on load
            content_delta: null,    // Quill Delta (object)
            content_NMD: '',        // Markdown string
            content_event_json: {}  // Derived event JSON
        };
        this.hydrateState();

        // Update both editors with hydrated state
        this.updateMarkdownEditor();
        this.updateQuillEditor();

        // Check for and restore state after login
        this.restoreStateAfterLogin();

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

        // Auto-sync content when Quill editor loses focus
        this.setupQuillBlurSync();

        this.handleViewportChange = () => this.applyMobilePanelVisibility();
        window.addEventListener('resize', this.handleViewportChange);
        this.applyMobilePanelVisibility();

        this.setupAuthModal();
    }

    isMobileViewport() {
        return window.matchMedia('(max-width: 768px)').matches;
    }

    toggleMobileArticleList(event) {
        event.preventDefault();
        this.mobilePanelsVisible.articleList = !this.mobilePanelsVisible.articleList;
        this.applyMobilePanelVisibility();
    }

    toggleMobileSidebar(event) {
        event.preventDefault();
        this.mobilePanelsVisible.settings = !this.mobilePanelsVisible.settings;
        this.applyMobilePanelVisibility();
    }

    applyMobilePanelVisibility() {
        const isMobile = this.isMobileViewport();

        if (this.hasArticleListSidebarTarget) {
            this.articleListSidebarTarget.classList.toggle(
                'is-mobile-collapsed',
                isMobile && !this.mobilePanelsVisible.articleList
            );
        }

        if (this.hasEditorSidebarTarget) {
            this.editorSidebarTarget.classList.toggle(
                'is-mobile-collapsed',
                isMobile && !this.mobilePanelsVisible.settings
            );
        }

        if (this.hasMobileArticleListToggleTarget) {
            const expanded = !isMobile || this.mobilePanelsVisible.articleList;
            this.mobileArticleListToggleTarget.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            this.mobileArticleListToggleTarget.textContent = expanded ? 'Hide Library' : 'Show Library';
        }

        if (this.hasMobileSidebarToggleTarget) {
            const expanded = !isMobile || this.mobilePanelsVisible.settings;
            this.mobileSidebarToggleTarget.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            this.mobileSidebarToggleTarget.textContent = expanded ? 'Hide Settings' : 'Show Settings';
        }
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
        // Find the unified login modal controller on this element
        this._loginModalEl = this.element.closest('[data-controller~="utility--login-modal"]') || this.element;
        this.isAnon = !window.appUser || !window.appUser.isAuthenticated;
    }

    showAuthModal() {
        const el = this._loginModalEl || this.element;
        const ctrl = this.application.getControllerForElementAndIdentifier(el, 'utility--login-modal');
        if (ctrl) {
            ctrl.openDialog();
        }
    }
    hideAuthModal() {
        // No-op — the new modal manages its own close state
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
        this.state.content_delta = this.safeNmdToDelta(nmd, {
            context: 'initial-load',
            fallbackMarkdown: nmd,
            fallbackToPlainTextDelta: true,
        });
        // Set active_source to 'quill' since we want rich text editor to be active first
        this.state.active_source = 'quill';
    }

    persistState() {
        // Save state to localStorage and hidden fields
        localStorage.setItem('editorState', JSON.stringify(this.state));
        const deltaField = document.getElementById('contentDelta');
        const nmdField = document.getElementById('contentNMD');
        if (deltaField) deltaField.value = JSON.stringify(this.state.content_delta || {});
        if (nmdField) nmdField.value = this.state.content_NMD || '';
    }

    saveCompleteStateBeforeLogin() {
        // Save all editor content and form fields before login/reload
        console.log('[Editor] Saving complete editor state before login');

        // Sync current content first
        this.syncContentBeforePublish();

        const stateToSave = {
            timestamp: Date.now(),
            editorState: this.state,
            formData: {}
        };

        // Save all form fields
        const form = this.element.querySelector('form');
        if (form) {
            const formData = new FormData(form);
            for (const [key, value] of formData.entries()) {
                stateToSave.formData[key] = value;
            }
        }

        localStorage.setItem('editorStateBeforeLogin', JSON.stringify(stateToSave));
        console.log('[Editor] State saved:', stateToSave);
    }

    restoreStateAfterLogin() {
        // Check if there's a saved state from before login
        const savedStateStr = localStorage.getItem('editorStateBeforeLogin');
        if (!savedStateStr) {
            console.log('[Editor] No saved state found');
            return;
        }

        try {
            const savedState = JSON.parse(savedStateStr);
            const ageMinutes = (Date.now() - savedState.timestamp) / 1000 / 60;

            // Only restore if saved within last 10 minutes
            if (ageMinutes > 10) {
                console.log('[Editor] Saved state too old, ignoring');
                localStorage.removeItem('editorStateBeforeLogin');
                return;
            }

            console.log('[Editor] Restoring state from before login:', savedState);

            // Restore editor state
            if (savedState.editorState) {
                this.state = savedState.editorState;
            }

            // Restore form fields
            if (savedState.formData) {
                const form = this.element.querySelector('form');
                if (form) {
                    for (const [key, value] of Object.entries(savedState.formData)) {
                        const field = form.elements[key];
                        if (field) {
                            if (field.type === 'checkbox') {
                                field.checked = value === 'on' || value === '1' || value === true;
                            } else {
                                field.value = value;
                            }
                        }
                    }
                }
            }

            // Clear the saved state
            localStorage.removeItem('editorStateBeforeLogin');

            // Show notification
            this.updateStatus('✓ Your work has been restored after login');

        } catch (e) {
            console.error('[Editor] Failed to restore state:', e);
            localStorage.removeItem('editorStateBeforeLogin');
        }
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
            // Snapshot mention names from Quill blots before converting
            this.snapshotMentionNames();
            // Convert Delta to NMD
            this.state.content_NMD = this.safeDeltaToNmd(this.state.content_delta, {
                context: 'switch-to-markdown',
                fallbackMarkdown: this.state.content_NMD,
            });
            this.state.active_source = 'md';
            this.updateMarkdownEditor();
            // Sync title from rich text to markdown
            this.syncTitleToMarkdown();
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
            this.state.content_delta = this.safeNmdToDelta(nmd, {
                context: 'switch-to-edit',
                fallbackMarkdown: nmd,
                fallbackToPlainTextDelta: true,
            });
            this.state.active_source = 'quill';
            this.updateQuillEditor();
            // Sync title from markdown to rich text
            this.syncTitleToRichText();
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

        // Resolve the markdown to send: when Quill is the active source, convert
        // the current delta to markdown on the fly so the preview is always fresh.
        let markdownContent = '';
        if (this.state.active_source === 'quill' && this.state.content_delta) {
            markdownContent = this.safeDeltaToNmd(this.state.content_delta, {
                context: 'preview',
                fallbackMarkdown: this.state.content_NMD,
            });
        } else if (markdownInput) {
            // CodeMirror may not sync back to the textarea value in real-time
            if (markdownInput._codemirror) {
                markdownContent = markdownInput._codemirror.state.doc.toString();
            } else {
                markdownContent = markdownInput.value || '';
            }
        }

        if (markdownContent || markdownInput) {
            try {
                const response = await fetch('/editor/markdown/preview', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ markdown: markdownContent })
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

            // Render math expressions (KaTeX). The server pipeline converts math to
            // \(…\) / $$…$$ but the static preview div has no utility--katex controller,
            // so we must trigger rendering manually after injecting HTML.
            if (hasRealMath(this.previewBodyTarget.textContent || '')) {
                normalizeDollarMathInTextNodes(this.previewBodyTarget);
                renderMathInElement(this.previewBodyTarget, {
                    delimiters: [
                        { left: '$$', right: '$$', display: true },
                        { left: '\\(', right: '\\)', display: false },
                        { left: '\\[', right: '\\]', display: true },
                    ],
                    throwOnError: false,
                    ignoredTags: ['script', 'noscript', 'style', 'textarea', 'pre', 'annotation'],
                });
            }
        } else {
            this.previewBodyTarget.innerHTML = '<p><em>No content yet. Start writing your article!</em></p>';
        }
    }

    saveDraft() {
        // Mark as draft - set checkbox to true
        const draftCheckbox = this.element.querySelector('input[name*="[isDraft]"]');
        if (draftCheckbox) {
            draftCheckbox.checked = true;
        } else {
            console.warn('[Editor] Draft checkbox not found');
        }

        // Trigger click on the hidden Nostr publish button (same pattern as header controller)
        const publishButton = document.querySelector('[data-nostr--nostr-publish-target="publishButton"]');
        if (publishButton) {
            console.log('[Editor] Triggering publish button click for draft save');
            publishButton.click();
        } else {
            console.error('[Editor] Hidden publish button not found for saveDraft');
        }
    }

    publish() {
        // Mark as NOT draft - set checkbox to false
        const draftCheckbox = this.element.querySelector('input[name*="[isDraft]"]');
        if (draftCheckbox) {
            draftCheckbox.checked = false;
        } else {
            console.warn('[Editor] Draft checkbox not found');
        }

        // Trigger click on the hidden Nostr publish button (same pattern as header controller)
        const publishButton = document.querySelector('[data-nostr--nostr-publish-target="publishButton"]');
        if (publishButton) {
            console.log('[Editor] Triggering publish button click');
            publishButton.click();
        } else {
            console.error('[Editor] Could not publish');
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
        if (this.handleViewportChange) {
            window.removeEventListener('resize', this.handleViewportChange);
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
        // Keep mention name cache warm from Quill blots
        this.snapshotMentionNames();
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
            this.state.content_NMD = this.safeDeltaToNmd(this.state.content_delta, {
                context: 'before-publish',
                fallbackMarkdown: this.state.content_NMD,
            });
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
        const setQuillContent = () => {
            if (window.appQuill && this.state.content_delta) {
                console.log('[Editor] Updating Quill with delta:', this.state.content_delta);
                window.appQuill.setContents(this.state.content_delta);
                // Hydrate mention blots with display names
                this.hydrateMentionNames();
            } else if (!window.appQuill) {
                // Retry after a short delay if Quill isn't ready yet
                console.log('[Editor] Quill not ready yet, retrying...');
                setTimeout(setQuillContent, 100);
            }
        };
        setQuillContent();
    }

    /**
     * Snapshot display names from all nostrMention blots in Quill into the cache.
     * Called before Quill→MD conversion so names survive the round-trip.
     */
    snapshotMentionNames() {
        const quill = window.appQuill;
        if (!quill) return;

        for (const span of quill.root.querySelectorAll('.ql-mention')) {
            const npub = span.getAttribute('data-npub') || '';
            const name = span.getAttribute('data-name') || '';
            // Only cache real names, not truncated npubs
            if (npub && name && !name.includes('…') && !name.startsWith('npub1')) {
                this.mentionNameCache[npub] = name;
            }
        }
    }

    /**
     * Batch-resolve display names for nostrMention blots in the Quill editor.
     * First applies names from the local cache, then fetches only truly unknown
     * npubs from the API. Populates the cache with API results.
     */
    hydrateMentionNames() {
        const quill = window.appQuill;
        if (!quill) return;

        const mentionSpans = quill.root.querySelectorAll('.ql-mention');
        if (!mentionSpans.length) return;

        const npubsToFetch = [];
        for (const span of mentionSpans) {
            const npub = span.getAttribute('data-npub') || '';
            const name = span.getAttribute('data-name') || '';
            const needsResolving = !name || name.includes('…') || name.startsWith('npub1');

            if (!needsResolving) continue;
            if (!npub || !npub.startsWith('npub1')) continue;

            // Check cache first
            if (this.mentionNameCache[npub]) {
                span.setAttribute('data-name', this.mentionNameCache[npub]);
                span.textContent = `@${this.mentionNameCache[npub]}`;
            } else {
                npubsToFetch.push(npub);
            }
        }

        if (!npubsToFetch.length) return;

        const uniqueNpubs = [...new Set(npubsToFetch)];
        console.log('[Editor] Fetching mention names for', uniqueNpubs.length, 'npubs');

        fetch('/api/users/by-npubs', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ npubs: uniqueNpubs }),
        })
        .then(res => res.ok ? res.json() : { users: [] })
        .then(data => {
            const nameMap = {};
            for (const user of (data.users || [])) {
                if (user.npub) {
                    const resolved = user.displayName || user.name || '';
                    if (resolved) {
                        nameMap[user.npub] = resolved;
                        // Populate the cache
                        this.mentionNameCache[user.npub] = resolved;
                    }
                }
            }

            // Update blots in place
            for (const span of quill.root.querySelectorAll('.ql-mention')) {
                const npub = span.getAttribute('data-npub') || '';
                const resolved = nameMap[npub];
                if (resolved) {
                    span.setAttribute('data-name', resolved);
                    span.textContent = `@${resolved}`;
                }
            }
        })
        .catch(err => console.warn('[Editor] Failed to hydrate mention names:', err));
    }

    // --- Conversion Stubs (implement via DNIR pipeline) ---
    deltaToNMD(delta) {
        // Use conversion pipeline
        return deltaToMarkdown(delta);
    }
    nmdToDelta(nmd) {
        // Pass mentionNameCache so npub→name lookup doesn't require API
        return markdownToDelta(nmd, { mentionNames: this.mentionNameCache });
    }

    safeDeltaToNmd(delta, { context = 'unknown', fallbackMarkdown = '' } = {}) {
        try {
            return this.deltaToNMD(delta);
        } catch (error) {
            this.notifyConversionFailure(
                `Could not convert rich text to Markdown (${context}). Kept existing Markdown content.`,
                error
            );

            return typeof fallbackMarkdown === 'string' ? fallbackMarkdown : '';
        }
    }

    safeNmdToDelta(nmd, {
        context = 'unknown',
        fallbackMarkdown = '',
        fallbackToPlainTextDelta = true,
    } = {}) {
        try {
            return this.nmdToDelta(nmd);
        } catch (error) {
            this.notifyConversionFailure(
                `Could not convert Markdown to rich text (${context}). Loaded Markdown and kept the editor responsive.`,
                error
            );

            if (fallbackToPlainTextDelta) {
                const fallbackText = typeof fallbackMarkdown === 'string' ? fallbackMarkdown : '';
                return this.createPlainTextDelta(fallbackText);
            }

            return null;
        }
    }

    createPlainTextDelta(markdown) {
        const value = typeof markdown === 'string' ? markdown : '';
        if (!value) {
            return { ops: [{ insert: '\n' }] };
        }

        const textWithTrailingNewline = value.endsWith('\n') ? value : `${value}\n`;
        return { ops: [{ insert: textWithTrailingNewline }] };
    }

    notifyConversionFailure(message, error = null) {
        if (error) {
            console.error('[Editor] Conversion failure:', error);
        }

        const now = Date.now();
        if (now - this.lastConversionWarningAt < 2000) {
            return;
        }
        this.lastConversionWarningAt = now;

        this.updateStatus(`Conversion failed: ${message}`);
        if (typeof window.showToast === 'function') {
            window.showToast(`Editor notice: ${message}`, 'warning', 7000);
        }
    }

    emitContentChanged() {
        // Emit a custom event with the new state
        this.element.dispatchEvent(new CustomEvent('content:changed', {
            detail: { ...this.state },
            bubbles: true
        }));
    }

    // --- Title Synchronization ---
    syncTitle(event) {
        // Called when either title input changes
        const source = event.target;
        if (source === this.richTextTitleTarget && this.hasMarkdownTitleTarget) {
            // Sync from rich text to markdown
            this.markdownTitleTarget.value = source.value;
        } else if (source === this.markdownTitleTarget && this.hasRichTextTitleTarget) {
            // Sync from markdown to rich text
            this.richTextTitleTarget.value = source.value;
        }
    }

    syncTitleToMarkdown() {
        // Called when switching to markdown mode
        if (this.hasRichTextTitleTarget && this.hasMarkdownTitleTarget) {
            this.markdownTitleTarget.value = this.richTextTitleTarget.value;
        }
    }

    syncTitleToRichText() {
        // Called when switching to rich text mode
        if (this.hasMarkdownTitleTarget && this.hasRichTextTitleTarget) {
            this.richTextTitleTarget.value = this.markdownTitleTarget.value;
        }
    }
}
