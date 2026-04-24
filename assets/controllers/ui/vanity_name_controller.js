import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller for vanity name registration and settings.
 * Handles real-time availability checking and clipboard copy.
 */
export default class extends Controller {
    static targets = ['input', 'status', 'submit', 'copySource', 'copyBtn'];
    static values = { checkUrl: String };

    #debounceTimer = null;

    connect() {
        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = true;
        }
    }

    check() {
        clearTimeout(this.#debounceTimer);
        const name = this.inputTarget.value.trim();

        if (name.length < 2) {
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
                    this.#setStatus(`<span class="text-success">✓ "${data.name}" is available!</span>`, true);
                } else {
                    this.#setStatus(`<span class="text-danger">✗ ${data.error}</span>`, false);
                }
            } catch {
                this.#setStatus('<span class="text-warning">Unable to check availability</span>', false);
            }
        }, 300);
    }

    #setStatus(html, enableSubmit) {
        if (this.hasStatusTarget) {
            this.statusTarget.innerHTML = html;
        }
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

