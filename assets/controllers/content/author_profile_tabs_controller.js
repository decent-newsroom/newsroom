import { Controller } from '@hotwired/stimulus';

/**
 * Controller for managing author profile tabs with real-time updates via Mercure
 * Handles tab switching, Turbo Frame loading, and subscribing to multiple Mercure topics
 *
 * stimulusFetch: 'lazy'
 */
export default class extends Controller {
    static targets = ['tab', 'content'];

    static values = {
        pubkey: String,
        hubUrl: String,
        activeTab: { type: String, default: 'articles' },
        recentWindowSeconds: { type: Number, default: 21600 }
    };

    // Map of content types to their Mercure topics
    contentTypes = ['overview', 'articles', 'media', 'highlights', 'drafts'];

    connect() {
        console.log('[profile-tabs] Connected', {
            pubkey: this.pubkeyValue,
            activeTab: this.activeTabValue
        });

        this.eventSources = {};
        this._pendingUpdateByType = {}; // tracks tabs with unviewed Mercure updates
        this._refreshDebounceTimers = {};
        this.subscribeToMercure();
    }

    disconnect() {
        // Close all EventSource connections
        Object.values(this.eventSources).forEach(es => {
            if (es) es.close();
        });
        this.eventSources = {};
    }

    /**
     * Subscribe to Mercure topics for all content types
     */
    subscribeToMercure() {
        const hubUrl = this.hubUrlValue || window.MercureHubUrl ||
            document.querySelector('meta[name="mercure-hub"]')?.content;

        if (!hubUrl) {
            console.warn('[profile-tabs] No Mercure hub URL found');
            return;
        }

        // Subscribe to each content type topic
        this.contentTypes.forEach(contentType => {
            const topic = `/author/${this.pubkeyValue}/${contentType}`;
            const url = new URL(hubUrl);
            url.searchParams.append('topic', topic);

            const es = new EventSource(url.toString());
            es.onopen = () => {
                console.log(`[profile-tabs] Subscribed to ${topic}`);
            };
            es.onmessage = (event) => this.handleMercureMessage(contentType, event);
            es.onerror = (error) => {
                console.warn(`[profile-tabs] EventSource error for ${topic}`, error);
            };

            this.eventSources[contentType] = es;
        });
    }

    /**
     * Handle incoming Mercure messages
     */
    handleMercureMessage(contentType, event) {
        try {
            const data = JSON.parse(event.data);

            if (this.shouldIgnoreStaleUpdate(contentType, data)) {
                return;
            }

            const badgeCount = Number.isFinite(Number(data.recentCount))
                ? Number(data.recentCount)
                : Number(data.count || 0);

            console.log(`[profile-tabs] Received ${contentType} update`, data);

            // Only update if this is the active tab
            if (contentType === this.activeTabValue) {
                this.updateTabContent(contentType, data);
            } else {
                // Show notification badge on tab and remember there's a pending update
                this._pendingUpdateByType[contentType] = true;
                this.showTabNotification(contentType, badgeCount);
            }
        } catch (error) {
            console.error('[profile-tabs] Error parsing Mercure message', error);
        }
    }

    shouldIgnoreStaleUpdate(contentType, data) {
        const newestCreatedAt = Number(data.newestCreatedAt || 0);
        const recentWindowSeconds = Number(this.recentWindowSecondsValue || 0);

        if (newestCreatedAt <= 0 || recentWindowSeconds <= 0) {
            return false;
        }

        const now = Math.floor(Date.now() / 1000);
        const cutoff = now - recentWindowSeconds;
        const isStale = newestCreatedAt < cutoff;

        if (isStale) {
            console.debug(`[profile-tabs] Ignoring stale ${contentType} update`, {
                newestCreatedAt,
                cutoff,
                windowSeconds: recentWindowSeconds
            });
        }

        return isStale;
    }

    /**
     * Reload the active tab's Turbo Frame content from the server.
     * Called both when Mercure fires for the currently-visible tab and when
     * the user switches to a tab that has a pending update badge.
     */
    updateTabContent(contentType, _data) {
        // Debounce rapid successive notifications (e.g. multiple content types at once)
        if (this._refreshDebounceTimers[contentType]) {
            clearTimeout(this._refreshDebounceTimers[contentType]);
        }

        this._refreshDebounceTimers[contentType] = setTimeout(async () => {
            delete this._refreshDebounceTimers[contentType];

            // Find the tab link element to get the URL
            const tab = this.tabTargets.find(t => t.dataset.tab === contentType);
            if (!tab) {
                console.warn(`[profile-tabs] No tab link found for ${contentType}`);
                return;
            }

            const url = tab.href;
            console.log(`[profile-tabs] Refreshing ${contentType} tab from server`, { url });

            try {
                const response = await fetch(url, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Turbo-Frame': 'profile-tab-content'
                    }
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const html = await response.text();
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const frameContent = doc.querySelector('turbo-frame#profile-tab-content');

                if (frameContent) {
                    const targetFrame = document.querySelector('turbo-frame#profile-tab-content');
                    if (targetFrame) {
                        targetFrame.innerHTML = frameContent.innerHTML;
                        console.log(`[profile-tabs] Refreshed ${contentType} tab content`);
                    }
                }
            } catch (error) {
                console.error(`[profile-tabs] Failed to refresh ${contentType} tab content:`, error);
            }
        }, 500);
    }

    /**
     * Show notification badge on inactive tab
     */
    showTabNotification(contentType, count) {
        const tab = this.tabTargets.find(t => t.dataset.tab === contentType);
        if (!tab || count === 0) return;

        let badge = tab.querySelector('.tab-badge');
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'tab-badge';
            tab.appendChild(badge);
        }
        badge.textContent = count > 99 ? '99+' : count;
        badge.classList.add('has-updates');
    }

    /**
     * Clear notification badge when tab is clicked
     */
    clearTabNotification(contentType) {
        const tab = this.tabTargets.find(t => t.dataset.tab === contentType);
        if (!tab) return;

        const badge = tab.querySelector('.tab-badge');
        if (badge) {
            badge.remove();
        }
    }

    /**
     * Handle tab clicks
     */
    async switchTab(event) {
        event.preventDefault();
        const clickedTab = event.currentTarget;
        const tabName = clickedTab.dataset.tab;
        let url = clickedTab.href;

        console.log(`[profile-tabs] Switching to ${tabName} tab, URL: ${url}`);

        // Update active state immediately for visual feedback
        this.tabTargets.forEach(tab => tab.classList.remove('active'));
        clickedTab.classList.add('active');

        // Update active tab value
        this.activeTabValue = tabName;

        // Clear notification for this tab
        this.clearTabNotification(tabName);

        // If there's a pending Mercure update for this tab, ask the server to
        // bypass its stale-while-revalidate cache so we get the freshest data.
        const hasPendingUpdate = this._pendingUpdateByType[tabName] === true;
        if (hasPendingUpdate) {
            delete this._pendingUpdateByType[tabName];
            const separator = url.includes('?') ? '&' : '?';
            url = `${url}${separator}refresh=1`;
        }

        // Fetch the tab content
        try {
            const response = await fetch(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Turbo-Frame': 'profile-tab-content'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const html = await response.text();

            // Parse the HTML and extract the turbo-frame content
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const frameContent = doc.querySelector('turbo-frame#profile-tab-content');

            if (frameContent) {
                // Find the target frame in the current page
                const targetFrame = document.querySelector('turbo-frame#profile-tab-content');
                if (targetFrame) {
                    // Replace the content
                    targetFrame.innerHTML = frameContent.innerHTML;
                    console.log(`[profile-tabs] Content updated for ${tabName}`);

                    // Update URL without page reload
                    window.history.pushState({}, '', url);
                } else {
                    console.error('[profile-tabs] Target frame not found in page');
                }
            } else {
                console.error('[profile-tabs] Frame content not found in response');
            }
        } catch (error) {
            console.error('[profile-tabs] Error fetching tab content:', error);
        }
    }

    /**
     * Handle active tab value change
     */
    activeTabValueChanged(newValue, oldValue) {
        if (oldValue && newValue !== oldValue) {
            console.log(`[profile-tabs] Tab changed from ${oldValue} to ${newValue}`);
        }
    }
}
