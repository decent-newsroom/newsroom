import { Controller } from '@hotwired/stimulus';

/**
 * Subscribes to a Mercure topic for async event-fetch results and reloads the
 * page when the event has been persisted to the database.  Falls back to
 * polling the status API endpoint when Mercure SSE is unavailable.
 *
 * stimulusFetch: 'lazy'
 */
export default class extends Controller {
    static values = {
        topic: String,
        reloadUrl: String,
        statusUrl: String,
        timeout: { type: Number, default: 30000 },
        pollInterval: { type: Number, default: 3000 },
    };

    static targets = ['spinner', 'notFound'];

    connect() {
        if (this._started) return;

        const hubUrl = window.MercureHubUrl
            || document.querySelector('meta[name="mercure-hub"]')?.content;

        if (!hubUrl || !this.hasTopicValue || !this.topicValue) {
            console.debug('[event-fetch] No hub URL or topic — falling back to polling');
            this._startPolling();
            return;
        }

        this._started = true;

        const url = new URL(hubUrl);
        url.searchParams.append('topic', this.topicValue);

        this.es = new EventSource(url.toString());
        this.es.onopen = () => {
            console.debug('[event-fetch] Subscribed to', this.topicValue);
        };
        this.es.onmessage = (event) => this._onMessage(event);
        this.es.onerror = () => {
            if (this.es?.readyState === EventSource.CLOSED) {
                console.warn('[event-fetch] SSE connection closed, falling back to polling');
                this._startPolling();
            }
        };

        // Timeout fallback in case the worker is slow or Mercure misses the update
        this._timeoutId = window.setTimeout(() => this._onTimeout(), this.timeoutValue);

        // Also start polling alongside Mercure — belt and suspenders
        this._startPolling();
    }

    disconnect() {
        try { this.es?.close(); } catch {}
        this.es = null;
        if (this._timeoutId) {
            window.clearTimeout(this._timeoutId);
            this._timeoutId = null;
        }
        if (this._pollId) {
            window.clearInterval(this._pollId);
            this._pollId = null;
        }
        this._started = false;
    }

    _startPolling() {
        if (this._pollId) return; // already polling

        this._started = true;

        // If no status URL is available, fall back to timed reload
        if (!this.hasStatusUrlValue || !this.statusUrlValue) {
            console.debug('[event-fetch] No status URL — falling back to timed reload');
            this._timeoutId = window.setTimeout(() => {
                this._reload();
            }, 8000);
            return;
        }

        console.debug('[event-fetch] Starting status polling every', this.pollIntervalValue, 'ms');
        this._pollCount = 0;
        this._pollId = window.setInterval(() => this._poll(), this.pollIntervalValue);
    }

    async _poll() {
        this._pollCount++;

        // Give up after ~30s of polling
        if (this._pollCount > Math.ceil(this.timeoutValue / this.pollIntervalValue)) {
            console.debug('[event-fetch] Poll limit reached');
            this._onTimeout();
            return;
        }

        try {
            const resp = await fetch(this.statusUrlValue);
            if (!resp.ok) return;

            const data = await resp.json();
            console.debug('[event-fetch] Poll result:', data);

            if (data.status === 'found') {
                this.disconnect();
                this._reload();
            } else if (data.status === 'not_found' || data.status === 'error') {
                this.disconnect();
                this._showNotFound();
            }
            // 'pending' → keep polling
        } catch (e) {
            console.debug('[event-fetch] Poll error:', e);
        }
    }

    _onMessage(event) {
        let data;
        try { data = JSON.parse(event.data); } catch { data = {}; }

        console.debug('[event-fetch] Received', data);

        if (data.status === 'not_found' || data.status === 'error') {
            this._showNotFound();
            this.disconnect();
            return;
        }

        // status === 'found' — event is now in DB, reload the page
        this.disconnect();
        this._reload();
    }

    _onTimeout() {
        // On timeout, try one reload — if the event was persisted in the
        // background the page will render normally.  If still not found the
        // loading template will be served again; in that case we show the
        // not-found state immediately via a URL flag.
        if (!this._reloaded) {
            this._reloaded = true;
            this._reload();
        } else {
            this._showNotFound();
        }
        this.disconnect();
    }

    _reload() {
        const reloadUrl = this.hasReloadUrlValue && this.reloadUrlValue
            ? this.reloadUrlValue
            : window.location.href;

        if (window.Turbo?.visit) {
            window.Turbo.visit(reloadUrl, { action: 'replace' });
        } else {
            window.location.href = reloadUrl;
        }
    }

    _showNotFound() {
        if (this.hasSpinnerTarget) {
            this.spinnerTarget.style.display = 'none';
        }
        if (this.hasNotFoundTarget) {
            this.notFoundTarget.style.display = '';
        }
    }
}
