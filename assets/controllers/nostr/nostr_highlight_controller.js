import { Controller } from '@hotwired/stimulus';
import { getSigner } from './signer_manager.js';

/**
 * NIP-84 Highlight Publishing Controller
 *
 * When a user selects text in the article body a floating "Highlight" button
 * appears.  Clicking it constructs a kind-9802 event with `a`, `context`, and
 * `p` tags, signs it via the Nostr signer, and POSTs it to the backend which
 * publishes to relays and persists locally.
 */
export default class extends Controller {
    static values = {
        publishUrl: String,
        csrfToken: String,
        coordinate: String,   // e.g. "30023:<pubkey>:<slug>"
        authorPubkey: String,  // hex pubkey of the article author
    };

    connect() {
        console.log('[nostr-highlight] Controller connected');
        this.articleMain = this.element.querySelector('.article-main');
        if (!this.articleMain) {
            console.warn('[nostr-highlight] .article-main not found');
            return;
        }

        this._onPointerUp = this.onPointerUp.bind(this);
        this._onPointerDown = this.onPointerDown.bind(this);

        this.articleMain.addEventListener('mouseup', this._onPointerUp);
        this.articleMain.addEventListener('touchend', this._onPointerUp);
        document.addEventListener('mousedown', this._onPointerDown);

        this.popover = null;
    }

    disconnect() {
        if (this.articleMain) {
            this.articleMain.removeEventListener('mouseup', this._onPointerUp);
            this.articleMain.removeEventListener('touchend', this._onPointerUp);
        }
        document.removeEventListener('mousedown', this._onPointerDown);
        this.removePopover();
    }

    /* ------------------------------------------------------------------ */
    /*  Selection handling                                                 */
    /* ------------------------------------------------------------------ */

    onPointerDown(event) {
        // If clicking outside the popover, remove it
        if (this.popover && !this.popover.contains(event.target)) {
            this.removePopover();
        }
    }

    onPointerUp() {
        // Small delay so the browser finalises the selection
        requestAnimationFrame(() => this.handleSelection());
    }

    handleSelection() {
        const selection = window.getSelection();
        if (!selection || selection.isCollapsed || !selection.rangeCount) {
            return;
        }

        const text = selection.toString().trim();
        if (!text || text.length < 3) {
            return;
        }

        // Ensure the selection is inside the article body
        const range = selection.getRangeAt(0);
        if (!this.articleMain.contains(range.commonAncestorContainer)) {
            return;
        }

        this.showPopover(range, text);
    }

    /* ------------------------------------------------------------------ */
    /*  Popover                                                            */
    /* ------------------------------------------------------------------ */

    showPopover(range, selectedText) {
        this.removePopover();

        const rect = range.getBoundingClientRect();

        this.popover = document.createElement('div');
        this.popover.className = 'highlight-popover';
        this.popover.innerHTML = `
            <button type="button" class="highlight-popover__btn" title="Highlight this text">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 20h9"/>
                    <path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/>
                </svg>
                Highlight
            </button>
        `;

        document.body.appendChild(this.popover);

        // Position above the selection
        const popoverRect = this.popover.getBoundingClientRect();
        let top = rect.top + window.scrollY - popoverRect.height - 8;
        let left = rect.left + window.scrollX + (rect.width / 2) - (popoverRect.width / 2);

        // Keep within viewport
        left = Math.max(8, Math.min(left, window.innerWidth - popoverRect.width - 8));
        if (top < window.scrollY + 8) {
            top = rect.bottom + window.scrollY + 8; // Show below if no room above
        }

        this.popover.style.top = `${top}px`;
        this.popover.style.left = `${left}px`;

        // Extract context before binding the click handler
        const context = this.extractContext(range);

        this.popover.querySelector('button').addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            this.publishHighlight(selectedText, context);
        });
    }

    removePopover() {
        if (this.popover) {
            this.popover.remove();
            this.popover = null;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Context extraction                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Get the text of the paragraph(s) surrounding the selection to use as
     * the NIP-84 `context` tag.
     */
    extractContext(range) {
        // Walk up from the selection to find the nearest block-level element(s)
        let node = range.commonAncestorContainer;
        if (node.nodeType === Node.TEXT_NODE) {
            node = node.parentElement;
        }

        // Find the enclosing paragraph / block
        const blockTags = ['P', 'BLOCKQUOTE', 'LI', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'DIV', 'SECTION'];
        let block = node;
        while (block && !blockTags.includes(block.tagName) && block !== this.articleMain) {
            block = block.parentElement;
        }

        if (!block || block === this.articleMain) {
            return null;
        }

        const text = (block.textContent || '').trim();
        // Only return context if it's different from the highlighted text itself
        return text && text !== range.toString().trim() ? text : null;
    }

    /* ------------------------------------------------------------------ */
    /*  Event construction & publishing                                    */
    /* ------------------------------------------------------------------ */

    async publishHighlight(selectedText, context) {
        this.removePopover();

        let signer;
        try {
            signer = await getSigner();
        } catch (e) {
            this.showToast('No Nostr signer available. Please connect Amber or install a Nostr signer extension.', 'error');
            return;
        }

        this.showToast('Preparing highlight for signing…', 'info');

        try {
            const pubkey = await signer.getPublicKey();
            const created_at = Math.floor(Date.now() / 1000);

            // Build NIP-84 tags
            const tags = [];

            // Article reference
            if (this.coordinateValue) {
                tags.push(['a', this.coordinateValue]);
            }

            // Author attribution
            if (this.authorPubkeyValue) {
                tags.push(['p', this.authorPubkeyValue, '', 'author']);
            }

            // Context
            if (context) {
                tags.push(['context', context]);
            }

            const nostrEvent = {
                kind: 9802,
                created_at,
                tags,
                content: selectedText,
                pubkey,
            };

            this.showToast('Requesting signature…', 'info');
            const signedEvent = await signer.signEvent(nostrEvent);

            this.showToast('Publishing highlight…', 'info');
            const response = await fetch(this.publishUrlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': this.csrfTokenValue,
                },
                body: JSON.stringify({ event: signedEvent }),
            });

            if (!response.ok) {
                const errorData = await response.json().catch(() => ({}));
                throw new Error(errorData.error || `HTTP ${response.status}`);
            }

            const result = await response.json();
            const relayInfo = result.relays
                ? `(${result.relays.success}/${result.relays.total} relays)`
                : '';
            this.showToast(`Highlight published ${relayInfo}`, 'success');

            // Clear selection
            window.getSelection()?.removeAllRanges();

        } catch (error) {
            console.error('Highlight publishing error:', error);
            this.showToast(`Highlight failed: ${error.message}`, 'error');
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Toast helper – dispatches a custom event for the toast controller   */
    /* ------------------------------------------------------------------ */

    showToast(message, type = 'info') {
        if (typeof window.showToast === 'function') {
            window.showToast(message, type === 'error' ? 'danger' : type);
        }
    }
}


