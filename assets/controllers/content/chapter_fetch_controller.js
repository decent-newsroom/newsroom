import { Controller } from '@hotwired/stimulus';

/**
 * Queues missing publication chapters for async relay fetch and listens for
 * Mercure updates from FetchEventFromRelaysHandler.
 *
 * stimulusFetch: 'lazy'
 */
export default class extends Controller {
    static values = {
        coordinate: String,
        mag: String,
        relayHints: { type: Array, default: [] },
        topic: String,
        reloadUrl: String,
        frameId: String,
        timeout: { type: Number, default: 30000 },
    };

    static targets = ['button', 'spinner', 'notFound', 'error', 'slowNotice', 'statusHeading', 'statusDetail'];

    connect() {
        if (this.hasTopicValue && this.topicValue) {
            this._subscribe(this.topicValue);
        }
    }

    disconnect() {
        this._closeEventSource();
        this._clearTimers();
    }

    async fetch(event) {
        event?.preventDefault();
        if (this._fetchStarted) return;

        this._fetchStarted = true;
        this._setLoading();

        try {
            const payload = {
                coordinate: this.coordinateValue,
                mag: this.hasMagValue ? this.magValue : null,
            };

            if (this.relayHintsValue.length > 0) {
                payload.relayHints = this.relayHintsValue;
            }

            const response = await window.fetch('/api/fetch-chapter', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload),
            });

            const data = await response.json();
            if (!response.ok || !data.queued) {
                this._showError(data.error || 'Chapter fetch could not be queued.');
                return;
            }

            const topic = data.lookupTopic || (data.lookupKey ? `/event-fetch/${data.lookupKey}` : null);
            if (!topic) {
                this._showError('Chapter fetch queued, but no update topic was returned.');
                return;
            }

            this._subscribe(topic);
        } catch (error) {
            console.error('[chapter-fetch] Queueing failed', error);
            this._showError('An error occurred while queueing the chapter fetch.');
        }
    }

    _subscribe(topic) {
        if (this.es) return;

        const hubUrl = window.MercureHubUrl
            || document.querySelector('meta[name="mercure-hub"]')?.content;

        if (!hubUrl) {
            console.warn('[chapter-fetch] No Mercure hub URL — will use timeout fallback');
            this._startTimers();
            return;
        }

        const url = new URL(hubUrl);
        url.searchParams.append('topic', topic);

        this.es = new EventSource(url.toString());
        this.es.onmessage = (message) => this._onMessage(message);
        this.es.onerror = () => {
            if (this.es?.readyState === EventSource.CLOSED) {
                console.warn('[chapter-fetch] SSE connection closed');
            }
        };

        this._startTimers();
    }

    _onMessage(message) {
        let data;
        try { data = JSON.parse(message.data); } catch { data = {}; }

        if (data.status === 'found') {
            this._closeEventSource();
            this._clearTimers();
            this._reloadTarget();
            return;
        }

        if (data.status === 'not_found') {
            this._showNotFound();
            return;
        }

        if (data.status === 'error') {
            this._showError('The chapter was found, but could not be stored for rendering.');
        }
    }

    _reloadTarget() {
        const reloadUrl = this.hasReloadUrlValue && this.reloadUrlValue
            ? this.reloadUrlValue
            : null;

        if (this.hasFrameIdValue && this.frameIdValue) {
            const frame = document.getElementById(this.frameIdValue);
            if (frame) {
                if (reloadUrl) {
                    frame.setAttribute('src', this._cacheBust(reloadUrl));
                }
                if (typeof frame.reload === 'function') {
                    frame.reload();
                }
                return;
            }
        }

        if (reloadUrl && window.Turbo?.visit) {
            window.Turbo.visit(reloadUrl, { action: 'replace' });
        } else if (reloadUrl) {
            window.location.href = reloadUrl;
        }
    }

    _cacheBust(url) {
        const resolved = new URL(url, window.location.origin);
        resolved.searchParams.set('_', Date.now().toString());
        return resolved.pathname + resolved.search + resolved.hash;
    }

    _setLoading() {
        if (this.hasButtonTarget) {
            this._originalButtonHtml ??= this.buttonTarget.innerHTML;
            this.buttonTarget.disabled = true;
            this.buttonTarget.textContent = 'Fetching…';
        }
        if (this.hasSpinnerTarget) this.spinnerTarget.hidden = false;
        if (this.hasNotFoundTarget) this.notFoundTarget.hidden = true;
        if (this.hasErrorTarget) this.errorTarget.hidden = true;
    }

    _showNotFound() {
        this._closeEventSource();
        this._clearTimers();
        if (this.hasSpinnerTarget) this.spinnerTarget.hidden = true;
        if (this.hasButtonTarget) {
            this.buttonTarget.disabled = true;
            this.buttonTarget.textContent = 'Not found on relays';
        }
        if (this.hasErrorTarget) this.errorTarget.hidden = true;
        if (this.hasNotFoundTarget) this.notFoundTarget.hidden = false;
    }

    _showError(message) {
        this._closeEventSource();
        this._clearTimers();
        if (this.hasSpinnerTarget) this.spinnerTarget.hidden = true;
        if (this.hasButtonTarget) {
            this.buttonTarget.disabled = false;
            this.buttonTarget.innerHTML = this._originalButtonHtml || 'Fetch Chapter';
        }
        if (this.hasNotFoundTarget) this.notFoundTarget.hidden = true;
        if (this.hasErrorTarget) {
            this.errorTarget.textContent = message || 'Chapter fetch failed.';
            this.errorTarget.hidden = false;
        }
    }

    _showSlowNotice() {
        if (this.hasSlowNoticeTarget) this.slowNoticeTarget.hidden = false;
        if (this.hasStatusHeadingTarget) this.statusHeadingTarget.textContent = 'Still searching relays…';
        if (this.hasStatusDetailTarget) {
            this.statusDetailTarget.textContent = 'This is taking longer than usual. The chapter may live on a slow or uncommon relay.';
        }
    }

    _onTimeout() {
        this._reloadTarget();
        this._showNotFound();
    }

    _startTimers() {
        this._clearTimers();
        this._timeoutId = window.setTimeout(() => this._onTimeout(), this.timeoutValue);
        this._slowNoticeId = window.setTimeout(() => this._showSlowNotice(), 6000);
    }

    _clearTimers() {
        if (this._timeoutId) {
            window.clearTimeout(this._timeoutId);
            this._timeoutId = null;
        }
        if (this._slowNoticeId) {
            window.clearTimeout(this._slowNoticeId);
            this._slowNoticeId = null;
        }
    }

    _closeEventSource() {
        try { this.es?.close(); } catch {}
        this.es = null;
    }
}
