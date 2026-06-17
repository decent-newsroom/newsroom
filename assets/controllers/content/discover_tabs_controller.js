import { Controller } from '@hotwired/stimulus';

/*
 * Discover Tabs Controller
 * Handles switching between Featured Writers, Recent, Highlights, and Editorial tabs.
 * Reuses the existing .tab-link / .settings-panel CSS patterns.
 */
export default class extends Controller {
    static targets = ['tab', 'panel'];

    switchTab(event) {
        event.preventDefault();
        const tabName = event.currentTarget.dataset.tab;
        this.activateTab(tabName);
        localStorage.setItem('discover-tab', tabName);
    }

    connect() {
        // Try to restore previously selected tab, fall back to articles
        const savedTab = localStorage.getItem('discover-tab') || 'articles';
        const hasSavedTab = this.tabTargets.some(tab => tab.dataset.tab === savedTab);
        this.activateTab(hasSavedTab ? savedTab : 'articles');
    }

    activateTab(tabName) {
        this.tabTargets.forEach(t => {
            t.classList.toggle('active', t.dataset.tab === tabName);
        });
        this.panelTargets.forEach(p => {
            p.classList.toggle('active', p.dataset.panel === tabName);
        });
    }
}

