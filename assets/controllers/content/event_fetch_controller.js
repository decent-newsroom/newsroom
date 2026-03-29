import { Controller } from '@hotwired/stimulus';

/**
 * Subscribes to a Mercure topic for async event-fetch results and reloads the
 * page when the event has been persisted to the database.
 *
 * stimulusFetch: 'lazy'
 */
export default class extends Controller {
    static values = {
        topic: String,
        reloadUrl: String,
        timeout: { type: Number, default: 30000 },
    };

    static targets = ['spinner', 'notFound', 'slowNotice', 'statusHeading', 'statusDetail'];

    connect() {
        if (this._started) return;

        // Break the infinite reload loop: if we already reloaded for this
        // lookup key and the server is STILL showing the loading template,
        // the event genuinely wasn't found — show the not-found state now.
        const storageKey = 'event-fetch-reloaded:' + this.topicValue;
        if (sessionStorage.getItem(storageKey)) {
            sessionStorage.removeItem(storageKey);
            this._started = true;
            this._showNotFound();
            return;
        }

        const hubUrl = window.MercureHubUrl
            || document.querySelector('meta[name="mercure-hub"]')?.content;

        if (!hubUrl || !this.hasTopicValue || !this.topicValue) {
            console.warn('[event-fetch] No Mercure hub URL or topic — will timeout');
            this._started = true;
            this._timeoutId = window.setTimeout(() => this._onTimeout(), this.timeoutValue);
            this._slowNoticeId = window.setTimeout(() => this._showSlowNotice(), 6000);
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
                console.warn('[event-fetch] SSE connection closed');
            }
        };

        // Timeout fallback in case the worker is slow or Mercure misses the update
        this._timeoutId = window.setTimeout(() => this._onTimeout(), this.timeoutValue);

        // Show slow-search notice after 6 seconds
        this._slowNoticeId = window.setTimeout(() => this._showSlowNotice(), 6000);
    }

    disconnect() {
        try { this.es?.close(); } catch {}
        this.es = null;
        if (this._timeoutId) {
            window.clearTimeout(this._timeoutId);
            this._timeoutId = null;
        }
        if (this._slowNoticeId) {
            window.clearTimeout(this._slowNoticeId);
            this._slowNoticeId = null;
        }
        this._started = false;
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

        // status === 'found' — event is now in DB, clear retry flag and reload
        const storageKey = 'event-fetch-reloaded:' + this.topicValue;
        sessionStorage.removeItem(storageKey);
        this.disconnect();
        this._reload();
    }

    _onTimeout() {
        // Set a flag in sessionStorage so that on reload, if the loading page
        // is served again, we know the event genuinely wasn't found.
        const storageKey = 'event-fetch-reloaded:' + this.topicValue;
        if (!sessionStorage.getItem(storageKey)) {
            sessionStorage.setItem(storageKey, '1');
            this._reload();
        } else {
            sessionStorage.removeItem(storageKey);
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

    _showSlowNotice() {
        if (this.hasSlowNoticeTarget) {
            this.slowNoticeTarget.style.display = '';
        }
        if (this.hasStatusHeadingTarget) {
            this.statusHeadingTarget.textContent = 'Still searching relays…';
        }
        if (this.hasStatusDetailTarget) {
            this.statusDetailTarget.textContent = 'This is taking longer than usual. Expanding search to additional relays.';
        }
    }
}
