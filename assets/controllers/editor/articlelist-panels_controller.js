import { Controller } from '@hotwired/stimulus';

// Handles tab switching for the left article list sidebar, matching the right sidebar logic
export default class extends Controller {
    static targets = ['tab', 'panel'];

    switch(event) {
        const panel = event.currentTarget.dataset.panel;
        this.tabTargets.forEach(tab => {
            tab.classList.toggle('is-active', tab.dataset.panel === panel);
        });
        this.panelTargets.forEach(panelEl => {
            panelEl.classList.toggle('is-hidden', panelEl.dataset.panel !== panel);
        });
    }
}

