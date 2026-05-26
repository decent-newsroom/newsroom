import { Controller } from '@hotwired/stimulus';

/*
 * Discover Tabs Controller
 * Handles switching between Articles, Highlights, and Editorial tabs
 */
export default class extends Controller {
    static targets = ['articles', 'highlights', 'editorial'];

    switchTab(event) {
        const tabName = event.currentTarget.dataset.tab;

        // Update button states
        this.element.querySelectorAll('.discover-tab-button').forEach(btn => {
            btn.classList.remove('discover-tab-button--active');
        });
        event.currentTarget.classList.add('discover-tab-button--active');

        // Hide all content
        this.element.querySelectorAll('.discover-tab-content').forEach(content => {
            content.classList.remove('discover-tab-content--active');
        });

        // Show selected content
        const targetName = tabName.charAt(0).toUpperCase() + tabName.slice(1);
        if (this[`${tabName}Target`]) {
            this[`${tabName}Target`].classList.add('discover-tab-content--active');
        }

        // Save preference to localStorage
        localStorage.setItem('discover-tab', tabName);
    }

    connect() {
        // Restore saved tab preference
        const savedTab = localStorage.getItem('discover-tab') || 'articles';
        const button = this.element.querySelector(`[data-tab="${savedTab}"]`);
        if (button) {
            button.click();
        }
    }
}

