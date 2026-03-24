import { Controller } from '@hotwired/stimulus';

/**
 * Subdomain availability checker
 * stimulusFetch: 'lazy'
 */
export default class extends Controller {
    static values = { checkUrl: String, baseDomain: String };
    static targets = ['input', 'status', 'preview', 'submit'];

    connect() { this._timer = null; }
    disconnect() { clearTimeout(this._timer); }

    check() {
        clearTimeout(this._timer);
        const name = (this.inputTarget.value || '').trim().toLowerCase();
        if (name.length < 3) {
            this._set('', 'neutral', '', false);
            return;
        }
        this._set('Checking…', 'neutral', '', false);
        this._timer = setTimeout(() => this._doCheck(name), 400);
    }

    async _doCheck(name) {
        try {
            const url = new URL(this.checkUrlValue, window.location.origin);
            url.searchParams.set('name', name);
            const data = await (await fetch(url.toString())).json();
            if (data.available) {
                this._set('✓ Available', 'success', data.preview || `${name}.${this.baseDomainValue}`, true);
            } else {
                this._set(`✗ ${data.reason || 'Not available'}`, 'error', '', false);
            }
        } catch {
            this._set('Unable to check right now', 'error', '', false);
        }
    }

    _set(statusText, type, preview, submitEnabled) {
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = statusText;
            this.statusTarget.className = `subdomain-status subdomain-status--${type}`;
        }
        if (this.hasPreviewTarget) {
            this.previewTarget.textContent = preview;
            this.previewTarget.style.display = preview ? '' : 'none';
        }
        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = !submitEnabled;
        }
    }
}

