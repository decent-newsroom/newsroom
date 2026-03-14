import { Controller } from '@hotwired/stimulus';

/**
 * Controller for managing home feed tabs with Turbo Frame loading.
 *
 * stimulusFetch: 'lazy'
 */
export default class extends Controller {
    static targets = ['tab', 'content'];

    static values = {
        activeTab: { type: String, default: 'latest' }
    };

    /**
     * Handle tab clicks — fetch content via Turbo Frame
     */
    async switchTab(event) {
        event.preventDefault();
        const clickedTab = event.currentTarget;
        const tabName = clickedTab.dataset.tab;
        const url = clickedTab.href;

        // Update active state immediately for visual feedback
        this.tabTargets.forEach(tab => tab.classList.remove('active'));
        clickedTab.classList.add('active');
        this.activeTabValue = tabName;

        // Fetch the tab content
        try {
            const response = await fetch(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Turbo-Frame': 'home-tab-content'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const html = await response.text();
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const frameContent = doc.querySelector('turbo-frame#home-tab-content');

            if (frameContent) {
                const targetFrame = document.querySelector('turbo-frame#home-tab-content');
                if (targetFrame) {
                    targetFrame.innerHTML = frameContent.innerHTML;
                }
            }
        } catch (error) {
            console.error('[home-tabs] Error fetching tab content:', error);
        }
    }
}

