import { Controller } from '@hotwired/stimulus';

/**
 * Provider Selector Controller
 *
 * Fetches available media providers from the API and populates a dropdown.
 * Dispatches 'provider:changed' events when the selection changes.
 */
export default class extends Controller {
    static targets = ['select'];

    async connect() {
        await this.loadProviders();
    }

    async loadProviders() {
        try {
            const response = await fetch('/api/media/providers');
            const data = await response.json();

            if (data.providers && data.providers.length > 0) {
                this.renderOptions(data.providers);
            } else {
                this.selectTarget.innerHTML = '<option value="">No providers available</option>';
            }
        } catch (error) {
            console.error('Failed to load providers:', error);
            this.selectTarget.innerHTML = '<option value="">Error loading providers</option>';
        }
    }

    renderOptions(providers) {
        let html = '<option value="">All Providers</option>';
        providers.forEach(p => {
            const badge = p.protocol === 'blossom' ? '🌸' : '📡';
            html += `<option value="${p.id}" data-protocol="${p.protocol}">${badge} ${p.label}</option>`;
        });
        this.selectTarget.innerHTML = html;
    }

    change() {
        const providerId = this.selectTarget.value;
        const selectedOption = this.selectTarget.selectedOptions[0];
        const protocol = selectedOption?.dataset?.protocol || '';

        this.dispatch('changed', {
            detail: { providerId, protocol }
        });
    }
}

