import { Controller } from '@hotwired/stimulus';

/**
 * Media View Toggle Controller
 *
 * Switches between thumbnail and list views in the media manager grid.
 */
export default class extends Controller {
    static targets = ['viewBtn'];

    setView(event) {
        const view = event.currentTarget.dataset.view;

        // Update active button
        this.viewBtnTargets.forEach(btn => {
            btn.classList.toggle('active', btn.dataset.view === view);
        });

        // Dispatch to the library controller to update the grid class
        this.dispatch('changed', { detail: { view } });

        // Also update the grid directly if we can find it
        const grid = document.querySelector('.media-manager__grid');
        if (grid) {
            grid.className = `media-manager__grid media-manager__grid--${view}`;
        }
    }
}

