import { Controller } from '@hotwired/stimulus';

/**
 * Subscribes to a Mercure topic for async expression evaluation results.
 * - status: 'log'   → append entry to live log panel
 * - status: 'ready' → reload to render cached results
 * - status: 'error' → show error alert
 *
 * stimulusFetch: 'lazy'
 */
export default class extends Controller {
    static values = {
        topic: String,
        timeout: { type: Number, default: 60000 },
        maxLogEntries: { type: Number, default: 500 },
    };

    static targets = ['spinner', 'error', 'slowNotice', 'statusHeading', 'statusDetail', 'logList', 'logPanel'];

    connect() {
        if (this._started) return;
        this._started = true;
        this._logCount = 0;

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

        if (data.status === 'log') {
            this._appendLog(data);
            return;
        }

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

    _appendLog(entry) {
        if (!this.hasLogListTarget) return;

        // Reveal the panel on first entry.
        if (this._logCount === 0 && this.hasLogPanelTarget) {
            this.logPanelTarget.style.display = '';
        }

        const level = (entry.level || 'info').toLowerCase();
        const li = document.createElement('li');
        li.className = `expression-log__line expression-log__line--${level}`;

        const ts = entry.ts ? new Date(entry.ts) : new Date();
        const time = document.createElement('time');
        time.className = 'expression-log__time';
        time.textContent = ts.toLocaleTimeString(undefined, { hour12: false });

        const lvl = document.createElement('span');
        lvl.className = 'expression-log__level';
        lvl.textContent = level.toUpperCase();

        const msg = document.createElement('span');
        msg.className = 'expression-log__message';
        msg.textContent = entry.message || '';

        li.append(time, lvl, msg);

        if (entry.context && Object.keys(entry.context).length) {
            const ctx = document.createElement('span');
            ctx.className = 'expression-log__context';
            try { ctx.textContent = JSON.stringify(entry.context); } catch { ctx.textContent = ''; }
            li.append(ctx);
        }

        this.logListTarget.append(li);
        this._logCount += 1;

        // Cap entries to avoid unbounded DOM growth on slow evaluations.
        const max = this.maxLogEntriesValue;
        while (this.logListTarget.children.length > max) {
            this.logListTarget.removeChild(this.logListTarget.firstElementChild);
        }

        // Auto-scroll to bottom.
        this.logListTarget.scrollTop = this.logListTarget.scrollHeight;
    }

    _onTimeout() {
        // On timeout, reload once — if cache is now warm, results will show.
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

