import { Controller } from '@hotwired/stimulus';

/**
 * Subscribes to a Mercure topic for async event-fetch results and reloads the
 * page when the event has been persisted to the database.  Falls back to a
 * timeout that shows a "not found" state with a retry button.
 *
 * stimulusFetch: 'lazy'
 */
export default class extends Controller {
    static values = {
        topic: String,
        reloadUrl: String,
        timeout: { type: Number, default: 30000 },
    };

    static targets = ['spinner', 'notFound'];

    connect() {
        if (this._started) return;

        const hubUrl = window.MercureHubUrl
            || document.querySelector('meta[name="mercure-hub"]')?.content;

        if (!hubUrl || !this.hasTopicValue || !this.topicValue) {
            console.debug('[event-fetch] No hub URL or topic — falling back to timeout');
            this._startTimeoutOnly();
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
                console.warn('[event-fetch] SSE connection closed, falling back to timeout');
            }
        };

        // Timeout fallback in case the worker is slow or Mercure misses the update
        this._timeoutId = window.setTimeout(() => this._onTimeout(), this.timeoutValue);
    }

    disconnect() {
        try { this.es?.close(); } catch {}
        this.es = null;
        if (this._timeoutId) {
            window.clearTimeout(this._timeoutId);
            this._timeoutId = null;
        }
        this._started = false;
    }

    _startTimeoutOnly() {
        this._started = true;
        // Without Mercure, poll the page after a delay — the worker may have
        // persisted the event by then.
        this._timeoutId = window.setTimeout(() => {
            // Try reloading once — if the event was persisted the controller
            // will render normally instead of the loading page again.
            this._reload();
        }, 8000);
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

