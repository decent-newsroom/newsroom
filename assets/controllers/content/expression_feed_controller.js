import { Controller } from '@hotwired/stimulus';

/**
 * Subscribes to a Mercure topic for async expression evaluation results.
 * When the evaluation is ready, reloads the page to show cached results instantly.
 *
 * stimulusFetch: 'lazy'
 */
export default class extends Controller {
    static values = {
        topic: String,
        timeout: { type: Number, default: 60000 },
    };

    static targets = ['spinner', 'error', 'slowNotice', 'statusHeading', 'statusDetail'];

    connect() {
        if (this._started) return;
        this._started = true;

        const hubUrl = window.MercureHubUrl
            || document.querySelector('meta[name="mercure-hub"]')?.content;

        if (!hubUrl || !this.hasTopicValue || !this.topicValue) {
            console.warn('[expression-feed] No Mercure hub URL or topic — will timeout');
            this._timeoutId = window.setTimeout(() => this._onTimeout(), this.timeoutValue);
            this._slowNoticeId = window.setTimeout(() => this._showSlowNotice(), 8000);
            return;
        }

        const url = new URL(hubUrl);
        url.searchParams.append('topic', this.topicValue);

        this.es = new EventSource(url.toString());
        this.es.onopen = () => {
            console.debug('[expression-feed] Subscribed to', this.topicValue);
        };
        this.es.onmessage = (event) => this._onMessage(event);
        this.es.onerror = () => {
            if (this.es?.readyState === EventSource.CLOSED) {
                console.warn('[expression-feed] SSE connection closed');
            }
        };

        this._timeoutId = window.setTimeout(() => this._onTimeout(), this.timeoutValue);
        this._slowNoticeId = window.setTimeout(() => this._showSlowNotice(), 8000);
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

        console.debug('[expression-feed] Received', data);

        if (data.status === 'error') {
            this._showError();
            this.disconnect();
            return;
        }

        if (data.status === 'ready') {
            this.disconnect();
            this._reload();
        }
    }

    _onTimeout() {
        // On timeout, reload once — if cache is now warm, results will show.
        // If still cold, the loading page will be shown again (but expression
        // may still be evaluating in the worker).
        this.disconnect();
        this._reload();
    }

    _reload() {
        if (window.Turbo?.visit) {
            window.Turbo.visit(window.location.href, { action: 'replace' });
        } else {
            window.location.reload();
        }
    }

    _showError() {
        if (this.hasSpinnerTarget) {
            this.spinnerTarget.style.display = 'none';
        }
        if (this.hasErrorTarget) {
            this.errorTarget.style.display = '';
        }
    }

    _showSlowNotice() {
        if (this.hasSlowNoticeTarget) {
            this.slowNoticeTarget.style.display = '';
        }
        if (this.hasStatusHeadingTarget) {
            this.statusHeadingTarget.textContent = 'Still evaluating…';
        }
        if (this.hasStatusDetailTarget) {
            this.statusDetailTarget.textContent = 'This expression is taking longer than usual. Hang tight.';
        }
    }
}

