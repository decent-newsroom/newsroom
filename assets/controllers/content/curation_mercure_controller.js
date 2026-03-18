import { Controller } from '@hotwired/stimulus';

/**
 * Polls curation sync status for a short period and reloads the page once
 * missing media items start arriving.
 *
 * stimulusFetch: 'lazy'
 */
export default class extends Controller {
    static values = {
        statusUrl: String,
        reloadUrl: String,
        initialMissingCount: Number,
        pollInterval: { type: Number, default: 2000 },
        timeout: { type: Number, default: 30000 },
    };

    connect() {
        if (this._started) {
            return;
        }

        if (!this.hasStatusUrlValue || !this.statusUrlValue) {
            return;
        }

        this._started = true;
        this._poll();
        this.intervalId = window.setInterval(() => this._poll(), this.pollIntervalValue);
        this.timeoutId = window.setTimeout(() => this.disconnect(), this.timeoutValue);
    }

    disconnect() {
        if (this.intervalId) {
            window.clearInterval(this.intervalId);
            this.intervalId = null;
        }
        if (this.timeoutId) {
            window.clearTimeout(this.timeoutId);
            this.timeoutId = null;
        }
        this._started = false;
    }

    async _poll() {
        if (this._pollInFlight) {
            return;
        }

        this._pollInFlight = true;
        try {
            const response = await fetch(this.statusUrlValue, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                return;
            }

            const data = await response.json();
            const missingCount = Number(data.missingCount ?? this.initialMissingCountValue);

            if (missingCount < this.initialMissingCountValue || missingCount === 0) {
                this.disconnect();
                const reloadUrl = this.hasReloadUrlValue && this.reloadUrlValue
                    ? this.reloadUrlValue
                    : window.location.href;

                if (window.Turbo?.visit) {
                    window.Turbo.visit(reloadUrl, { action: 'replace' });
                } else {
                    window.location.href = reloadUrl;
                }
            }
        } catch (e) {
            console.debug('[curation-sync] Poll failed', e);
        } finally {
            this._pollInFlight = false;
        }
    }
}
