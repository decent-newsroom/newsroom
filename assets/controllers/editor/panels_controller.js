import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['tab', 'panel'];

    switch(event) {
        const panelName = event.currentTarget.dataset.panel;

        // Update tab states
        this.tabTargets.forEach(tab => {
            tab.classList.toggle(
                'is-active',
                tab.dataset.panel === panelName
            );
        });

        // Update panel visibility
        this.panelTargets.forEach(panel => {
            const isActive = panel.dataset.panel === panelName;
            panel.classList.toggle('is-hidden', !isActive);
        });
    }
}
