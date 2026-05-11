import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller for vanity name registration and settings.
 * Handles real-time availability checking, plan selection highlighting,
 * and clipboard copy.
 */
export default class extends Controller {
    static targets = ['input', 'status', 'submit', 'paymentRadio', 'copySource', 'copyBtn'];
    static values = { checkUrl: String };

    #debounceTimer = null;
    #nameAvailable = false;

    connect() {
        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = true;
        }
        // Highlight initially selected radio
        this.#updateCardHighlights();
    }

    check() {
        clearTimeout(this.#debounceTimer);
        const name = this.inputTarget.value.trim();

        if (name.length < 2) {
            this.#nameAvailable = false;
            this.#setStatus('', false);
            return;
        }

        this.#setStatus('<span class="text-muted">Checking availability…</span>', false);

        this.#debounceTimer = setTimeout(async () => {
            try {
                const url = `${this.checkUrlValue}?name=${encodeURIComponent(name)}`;
                const response = await fetch(url);
                const data = await response.json();

                if (data.available) {
                    this.#nameAvailable = true;
                    this.#setStatus(`<span class="text-success">✓ "${data.name}" is available!</span>`, true);
                } else {
                    this.#nameAvailable = false;
                    this.#setStatus(`<span class="text-danger">✗ ${data.error}</span>`, false);
                }
            } catch {
                this.#nameAvailable = false;
                this.#setStatus('<span class="text-warning">Unable to check availability</span>', false);
            }
        }, 300);
    }

    selectPlan() {
        this.#updateCardHighlights();
    }

    #updateCardHighlights() {
        if (!this.hasPaymentRadioTarget) return;
        this.paymentRadioTargets.forEach(radio => {
            const card = radio.closest('.pricing-card');
            if (card) {
                card.style.outline = radio.checked
                    ? '2px solid var(--color-primary, #0070f3)'
                    : '';
            }
        });
    }

    #setStatus(html, enableSubmit) {
        if (this.hasStatusTarget) {
            this.statusTarget.innerHTML = html;
        }
        this.#nameAvailable = enableSubmit;
        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = !enableSubmit;
        }
    }

    copy() {
        if (!this.hasCopySourceTarget) return;
        navigator.clipboard.writeText(this.copySourceTarget.value).then(() => {
            if (this.hasCopyBtnTarget) {
                const btn = this.copyBtnTarget;
                const original = btn.textContent;
                btn.textContent = 'Copied!';
                setTimeout(() => { btn.textContent = original; }, 2000);
            }
        });
    }
}



