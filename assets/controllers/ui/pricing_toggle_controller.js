import { Controller } from '@hotwired/stimulus';

/**
 * Pricing Toggle Controller
 *
 * Handles toggling between different pricing options (monthly/yearly, subscription/lifetime, etc.)
 *
 * Usage:
 * <div data-controller="ui--pricing-toggle">
 *   <button class="toggle-btn active" data-period="monthly" data-action="click->ui--pricing-toggle#toggle">Monthly</button>
 *   <button class="toggle-btn" data-period="yearly" data-action="click->ui--pricing-toggle#toggle">Yearly</button>
 *
 *   <div data-ui--pricing-toggle-target="panel" data-period="monthly">Monthly content</div>
 *   <div data-ui--pricing-toggle-target="panel" data-period="yearly" style="display: none;">Yearly content</div>
 * </div>
 */
export default class extends Controller {
    static targets = ['panel'];

    connect() {
        // Find the initially active button and ensure correct panel is shown
        const activeBtn = this.element.querySelector('.toggle-btn.active');
        if (activeBtn) {
            this.showPanel(activeBtn.dataset.period);
        }
    }

    toggle(event) {
        const button = event.currentTarget;
        const period = button.dataset.period;

        // Update active button
        this.element.querySelectorAll('.toggle-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        button.classList.add('active');

        // Show/hide panels
        this.showPanel(period);
    }

    showPanel(period) {
        this.panelTargets.forEach(panel => {
            if (panel.dataset.period === period) {
                panel.style.display = 'block';
            } else {
                panel.style.display = 'none';
            }
        });
    }
}

