import { Controller } from '@hotwired/stimulus';

/**
 * Polls curation sync status for a short period and reloads the page once
 * missing media items start arriving. Also shows which relays were tried
 * in the placeholder cards.
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
        this._relayInfoShown = false;
        this._poll();
        this.intervalId = window.setInterval(() => this._poll(), this.pollIntervalValue);
        this.timeoutId = window.setTimeout(() => this._onTimeout(), this.timeoutValue);
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

            // Show relay attempt info on placeholders (once)
            if (!this._relayInfoShown && data.fetchAttempt) {
                this._showRelayInfo(data.fetchAttempt, data.missingIds ?? []);
                this._relayInfoShown = true;
            }

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

    /**
     * Show which relays were tried in the placeholder cards for missing events.
     */
    _showRelayInfo(fetchAttempt, missingIds) {
        const relays = fetchAttempt.relays_tried ?? [];
        const attemptedAt = fetchAttempt.attempted_at;

        if (relays.length === 0) {
            return;
        }

        const placeholders = this.element.querySelectorAll('.curation-picture-grid__placeholder--missing');
        placeholders.forEach((el) => {
            const eventId = el.dataset.missingEventId;
            if (!eventId || (missingIds.length > 0 && !missingIds.includes(eventId))) {
                return;
            }

            // Add relay info below the existing content
            let relayInfo = el.querySelector('.curation-sync-relay-info');
            if (!relayInfo) {
                relayInfo = document.createElement('div');
                relayInfo.className = 'curation-sync-relay-info';

                const label = document.createElement('p');
                label.className = 'small mb-0';
                label.textContent = `Searched ${relays.length} relay${relays.length !== 1 ? 's' : ''}`;
                if (attemptedAt) {
                    const time = new Date(attemptedAt);
                    label.textContent += ` at ${time.toLocaleTimeString()}`;
                }
                relayInfo.appendChild(label);

                const list = document.createElement('ul');
                list.className = 'curation-sync-relay-list';
                relays.forEach((relay) => {
                    const li = document.createElement('li');
                    // Show just the hostname for compactness
                    try {
                        const url = new URL(relay);
                        li.textContent = url.hostname;
                        li.title = relay;
                    } catch {
                        li.textContent = relay;
                    }
                    list.appendChild(li);
                });
                relayInfo.appendChild(list);
                el.querySelector('div')?.appendChild(relayInfo);
            }
        });
    }

    /**
     * On timeout, update placeholders to show final "not found" state.
     */
    _onTimeout() {
        const placeholders = this.element.querySelectorAll('.curation-picture-grid__placeholder--missing');
        placeholders.forEach((el) => {
            const status = el.querySelector('p');
            if (status && status.textContent.includes('⏳')) {
                status.textContent = '⚠ Not found on relays';
            }
        });
        this.disconnect();
    }
}
