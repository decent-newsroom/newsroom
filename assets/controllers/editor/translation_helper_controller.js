import { Controller } from '@hotwired/stimulus';
import { getSigner } from '../nostr/signer_manager.js';

/**
 * Translation Helper Controller
 *
 * Handles the full client-side flow for translating a Nostr long-form article:
 *   1. Import an article by naddr or coordinate
 *   2. Display original markdown read-only, provide editable pane for translation
 *   3. Build a new kind 30023 event with a-tag, zap tags, language labels
 *   4. Sign via Nostr signer and publish through the existing article publish API
 */
export default class extends Controller {
    static targets = [
        'importInput', 'importButton', 'status',
        'metaSection', 'originalTitle', 'originalAuthor', 'originalCoordinate',
        'originalSummary',
        'fieldsSection', 'translationTitle', 'translationSummary',
        'translationLanguage', 'translationSlug',
        'editorsSection', 'originalEditor', 'translationEditor',
        'publishSection', 'publishButton',
        'previewSection', 'eventPreview',
    ];

    static values = {
        fetchUrl: String,
    };

    /** Stored raw event from the original article */
    originalEvent = null;
    /** Parsed coordinate string */
    originalCoordinate = null;

    connect() {
        // Allow pressing Enter in the import input
        this.importInputTarget.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.importArticle();
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Import
    // ─────────────────────────────────────────────────────────────────────────

    async importArticle() {
        const input = this.importInputTarget.value.trim();
        if (!input) {
            this.showError('Please enter an naddr or coordinate.');
            return;
        }

        this.importButtonTarget.disabled = true;
        this.showStatus('Fetching article…');

        try {
            const res = await fetch(this.fetchUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ input }),
            });

            const data = await res.json();

            if (!res.ok || data.error) {
                this.showError(data.error || `HTTP ${res.status}`);
                return;
            }

            this.originalEvent = data.event;
            this.originalCoordinate = data.coordinate;

            this.populateOriginal(data);
            this.showSuccess(`Article imported (source: ${data.source}).`);
        } catch (e) {
            this.showError(`Import failed: ${e.message}`);
        } finally {
            this.importButtonTarget.disabled = false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Populate UI after import
    // ─────────────────────────────────────────────────────────────────────────

    populateOriginal(data) {
        const event = data.event;
        const tags = event.tags || [];

        // Extract metadata from tags
        const title = this.getTagValue(tags, 'title') || '(untitled)';
        const summary = this.getTagValue(tags, 'summary') || '';
        const slug = this.getTagValue(tags, 'd') || '';

        // Show article info bar
        this.originalAuthorTarget.textContent = data.author || event.pubkey?.substring(0, 12) + '…';
        this.originalCoordinateTarget.textContent = data.coordinate;
        this.metaSectionTarget.classList.remove('is-hidden');

        // Fill readonly original title & summary fields
        this.originalTitleTarget.value = title;
        this.originalSummaryTarget.value = summary;

        // Leave translation title & summary empty for the translator
        this.translationTitleTarget.value = '';
        this.translationSummaryTarget.value = '';
        this.translationSlugTarget.value = slug ? `${slug}-translation` : '';
        this.fieldsSectionTarget.classList.remove('is-hidden');

        // Populate editors
        this.originalEditorTarget.value = event.content || '';
        this.translationEditorTarget.value = '';
        this.editorsSectionTarget.classList.remove('is-hidden');

        // Show publish area
        this.publishSectionTarget.classList.remove('is-hidden');
        this.previewSectionTarget.classList.remove('is-hidden');

        // Initial preview
        this.updatePreview();

        // Update preview on changes
        this.translationEditorTarget.addEventListener('input', () => this.updatePreview());
        this.translationTitleTarget.addEventListener('input', () => this.updatePreview());
        this.translationSummaryTarget.addEventListener('input', () => this.updatePreview());
        this.translationLanguageTarget.addEventListener('change', () => this.updatePreview());
        this.translationSlugTarget.addEventListener('input', () => this.updatePreview());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Build the translation event
    // ─────────────────────────────────────────────────────────────────────────

    buildTranslationEvent() {
        const original = this.originalEvent;
        const originalTags = original.tags || [];

        const translationContent = this.translationEditorTarget.value;
        const translationTitle = this.translationTitleTarget.value.trim();
        const translationSummary = this.translationSummaryTarget.value.trim();
        const language = this.translationLanguageTarget.value;
        const slug = this.translationSlugTarget.value.trim();

        if (!translationContent) {
            throw new Error('Translation content is empty.');
        }
        if (!language) {
            throw new Error('Please select a language for the translation.');
        }
        if (!slug) {
            throw new Error('Slug (d-tag) is required.');
        }

        // Start building tags — carry over selected tags from original
        const newTags = [];

        // d-tag (new slug for the translation)
        newTags.push(['d', slug]);

        // title
        if (translationTitle) {
            newTags.push(['title', translationTitle]);
        }

        // summary
        if (translationSummary) {
            newTags.push(['summary', translationSummary]);
        }

        // Carry over image tag from original if present
        const originalImage = this.getTagValue(originalTags, 'image');
        if (originalImage) {
            newTags.push(['image', originalImage]);
        }

        // Carry over t-tags (topics/hashtags) from original
        for (const tag of originalTags) {
            if (tag[0] === 't' && tag[1]) {
                newTags.push(['t', tag[1]]);
            }
        }

        // a-tag pointing to the original article
        const originalPubkey = original.pubkey;
        const originalSlug = this.getTagValue(originalTags, 'd');
        const originalKind = original.kind || 30023;
        newTags.push(['a', `${originalKind}:${originalPubkey}:${originalSlug}`]);

        // Zap tags: if original has no zap tags, add one for the original author
        const originalZapTags = originalTags.filter(t => t[0] === 'zap');
        if (originalZapTags.length === 0) {
            // Credit the original author with full weight
            newTags.push(['zap', originalPubkey, '', '1']);
        } else {
            // Keep existing zap tags as they were
            for (const zapTag of originalZapTags) {
                newTags.push([...zapTag]);
            }
        }

        // NIP-32 language labels
        newTags.push(['L', 'ISO-639-1']);
        newTags.push(['l', language, 'ISO-639-1']);

        // published_at timestamp
        newTags.push(['published_at', String(Math.floor(Date.now() / 1000))]);

        // client tag
        newTags.push(['client', 'Decent Newsroom']);

        // Build the event skeleton
        return {
            kind: 30023,
            created_at: Math.floor(Date.now() / 1000),
            tags: newTags,
            content: translationContent,
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Sign & Publish
    // ─────────────────────────────────────────────────────────────────────────

    async publish() {
        if (!this.originalEvent) {
            this.showError('No article imported. Please import first.');
            return;
        }

        let skeleton;
        try {
            skeleton = this.buildTranslationEvent();
        } catch (e) {
            this.showError(e.message);
            return;
        }

        let signer;
        try {
            this.showStatus('Connecting to signer…');
            signer = await getSigner();
        } catch (e) {
            this.showError(`No Nostr signer available: ${e.message}`);
            return;
        }

        this.publishButtonTarget.disabled = true;

        try {
            this.showStatus('Getting public key…');
            skeleton.pubkey = await signer.getPublicKey();

            // Update the slug if it still ends with -translation (append lang)
            const lang = this.translationLanguageTarget.value;
            const currentSlug = this.translationSlugTarget.value.trim();
            if (currentSlug.endsWith('-translation')) {
                const betterSlug = currentSlug.replace('-translation', `-${lang}`);
                this.translationSlugTarget.value = betterSlug;
                // Update the d-tag in the skeleton
                const dTagIndex = skeleton.tags.findIndex(t => t[0] === 'd');
                if (dTagIndex !== -1) {
                    skeleton.tags[dTagIndex][1] = betterSlug;
                }
            }

            this.showStatus('Requesting signature…');
            console.log('[translation-helper] Signing event:', skeleton);
            const signedEvent = await signer.signEvent(skeleton);
            console.log('[translation-helper] Event signed:', signedEvent);

            this.showStatus('Publishing translation…');
            const result = await this.publishSigned(signedEvent);
            console.log('[translation-helper] Publish result:', result);

            if (result.relayResults) {
                this.displayRelayResults(result.relayResults);
            }

            this.showSuccess('Translation published successfully!');

            if (result.redirectUrl) {
                setTimeout(() => {
                    window.location.href = result.redirectUrl;
                }, 2000);
            }
        } catch (e) {
            console.error('[translation-helper] Publish error:', e);
            this.showError(`Publishing failed: ${e.message}`);
        } finally {
            this.publishButtonTarget.disabled = false;
        }
    }

    async publishSigned(signedEvent) {
        // Use the nostr--nostr-single-sign publish URL
        const publishUrl = this.element.dataset['nostrNostrSingleSignPublishUrlValue'];
        const res = await fetch(publishUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ event: signedEvent }),
        });

        if (!res.ok) {
            const data = await res.json().catch(() => ({}));
            throw new Error(data.error || `HTTP ${res.status}`);
        }

        return res.json();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Preview
    // ─────────────────────────────────────────────────────────────────────────

    updatePreview() {
        try {
            const skeleton = this.buildTranslationEvent();
            skeleton.pubkey = '<your-pubkey>';
            this.eventPreviewTarget.textContent = JSON.stringify(skeleton, null, 2);
        } catch {
            this.eventPreviewTarget.textContent = '(fill in required fields to see preview)';
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // UI helpers
    // ─────────────────────────────────────────────────────────────────────────

    getTagValue(tags, name) {
        const tag = tags.find(t => t[0] === name);
        return tag ? tag[1] : null;
    }

    showStatus(message) {
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = message;
            this.statusTarget.className = 'translation-status translation-status--info';
        }
    }

    showSuccess(message) {
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = message;
            this.statusTarget.className = 'translation-status translation-status--success';
        }
    }

    showError(message) {
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = message;
            this.statusTarget.className = 'translation-status translation-status--error';
        }
    }

    displayRelayResults(relayResults) {
        if (!Array.isArray(relayResults) || relayResults.length === 0) return;

        let successCount = 0;
        let failureCount = 0;
        relayResults.forEach(r => {
            if (r.success) successCount++;
            else failureCount++;
        });

        if (window.showToast) {
            if (failureCount === 0) {
                window.showToast(`✓ Published to ${successCount} relay${successCount > 1 ? 's' : ''}`, 'success', 5000);
            } else {
                window.showToast(`Published: ${successCount} success, ${failureCount} failed`, 'warning', 6000);
            }
        }
    }
}


