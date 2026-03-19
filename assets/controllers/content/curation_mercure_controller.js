import { Controller } from '@hotwired/stimulus';

/**
 * Subscribes to a Mercure topic for curation media-sync updates and reloads
 * the page as soon as the handler publishes that new events have been persisted.
 * Falls back to a timeout that marks placeholders as "not found".
 *
 * stimulusFetch: 'lazy'
 */
export default class extends Controller {
    static values = {
        topic: String,
        reloadUrl: String,
        initialMissingCount: Number,
        timeout: { type: Number, default: 30000 },
    };

    connect() {
        if (this._started) return;

        const hubUrl = window.MercureHubUrl
            || document.querySelector('meta[name="mercure-hub"]')?.content;

        if (!hubUrl || !this.hasTopicValue || !this.topicValue) {
            console.debug('[curation-mercure] No hub URL or topic — skipping');
            return;
        }

        this._started = true;

        const url = new URL(hubUrl);
        url.searchParams.append('topic', this.topicValue);

        this._connected = false;
        this.es = new EventSource(url.toString());
        this.es.onopen = () => {
            this._connected = true;
            console.debug('[curation-mercure] Subscribed to', this.topicValue);
        };
        this.es.onmessage = (event) => this._onMessage(event);
        this.es.onerror = () => {
            if (this.es?.readyState === EventSource.CONNECTING) {
                // Transient error, browser is auto-reconnecting — not a problem
                console.debug('[curation-mercure] Reconnecting…');
            } else {
                console.warn('[curation-mercure] Connection lost');
            }
        };

        this.timeoutId = window.setTimeout(() => this._onTimeout(), this.timeoutValue);
    }

    disconnect() {
        try { this.es?.close(); } catch {}
        this.es = null;
        if (this.timeoutId) {
            window.clearTimeout(this.timeoutId);
            this.timeoutId = null;
        }
        this._started = false;
    }

    _onMessage(event) {
        let data;
        try { data = JSON.parse(event.data); } catch { data = {}; }

        console.debug('[curation-mercure] Received', data);

        // Any message means at least some events were persisted — reload
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

    /**
     * On timeout, update placeholders to show final "not found" state.
     */
    _onTimeout() {
        const placeholders = this.element.querySelectorAll(
            '.curation-picture-grid__placeholder--missing, .video-playlist__placeholder--missing'
        );
        placeholders.forEach((el) => {
            const status = el.querySelector('p') || el.querySelector('.video-playlist__item-title');
            if (status && status.textContent.includes('⏳')) {
                status.textContent = '⚠ Not found on relays';
            }
        });
        this.disconnect();
    }
}
