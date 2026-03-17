import { Controller } from '@hotwired/stimulus';

/**
 * Controller for managing home feed tabs with Turbo Frame loading.
 *
 * Shows a loading spinner while fetching, cancels in-flight requests
 * when a new tab is clicked, and reverts the active tab on error/timeout.
 *
 * stimulusFetch: 'lazy'
 */
export default class extends Controller {
    static targets = ['tab', 'content'];

    static values = {
        activeTab: { type: String, default: 'latest' },
        timeout:   { type: Number, default: 20000 }
    };

    /** @type {AbortController|null} */
    currentAbort = null;

    /** @type {string|null} previous tab name for rollback */
    previousTab = null;

    /**
     * Handle tab clicks — fetch content via Turbo Frame
     */
    async switchTab(event) {
        event.preventDefault();
        const clickedTab = event.currentTarget;
        const tabName = clickedTab.dataset.tab;
        const url = clickedTab.href;

        // Ignore clicks on the already-active tab
        if (tabName === this.activeTabValue) return;

        // Cancel any in-flight request
        if (this.currentAbort) {
            this.currentAbort.abort();
            this.currentAbort = null;
        }

        // Remember the previous tab for rollback
        this.previousTab = this.activeTabValue;

        // Update active state immediately for visual feedback
        this.tabTargets.forEach(tab => tab.classList.remove('active'));
        clickedTab.classList.add('active');
        this.activeTabValue = tabName;

        // Show loading indicator
        const targetFrame = this.contentTarget.querySelector('turbo-frame#home-tab-content');
        if (targetFrame) {
            targetFrame.innerHTML = this._loadingHTML();
        }

        // Set up abort controller with timeout
        const abort = new AbortController();
        this.currentAbort = abort;
        const timer = setTimeout(() => abort.abort(), this.timeoutValue);

        // Fetch the tab content
        try {
            const response = await fetch(url, {
                signal: abort.signal,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Turbo-Frame': 'home-tab-content'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const html = await response.text();

            // If this request was superseded by another tab click, discard
            if (abort.signal.aborted) return;

            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const frameContent = doc.querySelector('turbo-frame#home-tab-content');

            if (frameContent && targetFrame) {
                targetFrame.innerHTML = frameContent.innerHTML;
            }
        } catch (error) {
            // Don't act on intentional aborts from a newer tab click
            if (error.name === 'AbortError' && this.activeTabValue !== tabName) return;

            console.error('[home-tabs] Error fetching tab content:', error);

            // Revert the active tab to the previous one
            this._revertTab();

            // Show an error message in the frame
            if (targetFrame) {
                const isTimeout = error.name === 'AbortError';
                targetFrame.innerHTML = this._errorHTML(isTimeout);
            }
        } finally {
            clearTimeout(timer);
            if (this.currentAbort === abort) {
                this.currentAbort = null;
            }
        }
    }

    /**
     * Revert tab highlight to the previous active tab.
     */
    _revertTab() {
        if (!this.previousTab) return;
        this.activeTabValue = this.previousTab;
        this.tabTargets.forEach(tab => {
            tab.classList.toggle('active', tab.dataset.tab === this.previousTab);
        });
        this.previousTab = null;
    }

    /**
     * @returns {string} HTML for the loading spinner
     */
    _loadingHTML() {
        return `<div class="home-feed__loading">
            <div class="spinner"><div class="lds-dual-ring"></div></div>
            <p>Loading…</p>
        </div>`;
    }

    /**
     * @param {boolean} isTimeout
     * @returns {string} HTML for a non-intrusive error message
     */
    _errorHTML(isTimeout) {
        const msg = isTimeout
            ? 'This is taking too long. Please try again.'
            : 'Something went wrong loading this tab. Please try again.';
        return `<div class="home-feed__loading">
            <p>${msg}</p>
        </div>`;
    }
}

