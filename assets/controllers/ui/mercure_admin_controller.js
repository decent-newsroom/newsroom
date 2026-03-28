import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller for the Mercure admin dashboard.
 * Handles publish tests, connectivity checks, persistent SSE listener,
 * and subscription refresh.
 *
 * The SSE listener auto-connects on page load and stays open. Transient
 * reconnects (normal EventSource behaviour) are silent — only a permanent
 * close updates the status indicator.
 *
 * stimulusFetch: 'lazy'
 */
export default class extends Controller {
    static targets = [
        'topicInput',
        'publishBtn',
        'publishResult',
        'connectivityBtn',
        'connectivityStatus',
        'sseStatus',
        'sseMessages',
        'subsRefreshBtn',
        'subscriptionsContainer',
    ];

    static values = {
        csrf: String,
        testPublishUrl: String,
        testConnectivityUrl: String,
        subscriptionsUrl: String,
    };

    connect() {
        this._eventSource = null;
        this._messageCount = 0;
        this._currentTopic = null;
        this._connectSse();
    }

    disconnect() {
        this._closeSse();
    }

    // ── Publish Test ──────────────────────────────────────

    async publishTest() {
        const btn = this.publishBtnTarget;
        const resultEl = this.publishResultTarget;
        const topic = this.topicInputTarget.value.trim() || '/test/admin-ping';

        btn.disabled = true;
        btn.textContent = 'Publishing…';
        resultEl.style.display = 'none';

        // Reconnect only when the admin changed the topic
        if (topic !== this._currentTopic) {
            this._connectSse();
        }

        try {
            const body = new FormData();
            body.append('_token', this.csrfValue);
            body.append('topic', topic);

            const resp = await fetch(this.testPublishUrlValue, { method: 'POST', body });
            const data = await resp.json();

            resultEl.style.display = 'block';

            if (data.success) {
                resultEl.innerHTML = `
                    <div style="padding:0.75rem; background:var(--color-bg-light);">
                        <span class="status-indicator healthy"></span>
                        <strong>Published successfully</strong>
                        <span style="font-size:0.8rem; color:var(--color-text-secondary);">
                            — ID: <code>${data.id}</code> · Topic: <code>${data.topic}</code> · ${data.latency_ms}ms
                        </span>
                    </div>`;
            } else {
                resultEl.innerHTML = `
                    <div style="padding:0.75rem; background:var(--color-bg-light);">
                        <span class="status-indicator error"></span>
                        <strong>Publish failed</strong>
                        <span style="font-size:0.8rem; color:var(--color-text-secondary);">
                            — ${data.error} · ${data.latency_ms}ms
                        </span>
                    </div>`;
            }
        } catch (e) {
            resultEl.style.display = 'block';
            resultEl.innerHTML = `
                <div style="padding:0.75rem; background:var(--color-bg-light);">
                    <span class="status-indicator error"></span>
                    <strong>Request failed</strong>: ${e.message}
                </div>`;
        } finally {
            btn.disabled = false;
            btn.textContent = 'Publish Test Message';
        }
    }

    // ── Connectivity Test ─────────────────────────────────

    async testConnectivity() {
        const btn = this.connectivityBtnTarget;
        const statusEl = this.connectivityStatusTarget;

        btn.disabled = true;
        btn.textContent = 'Testing…';

        try {
            const body = new FormData();
            body.append('_token', this.csrfValue);

            const resp = await fetch(this.testConnectivityUrlValue, { method: 'POST', body });
            const data = await resp.json();

            if (data.reachable) {
                statusEl.innerHTML = `
                    <span class="status-indicator healthy"></span>
                    Yes (HTTP ${data.status_code}, ${data.latency_ms}ms)`;
            } else {
                let detail = '';
                if (data.error) detail = ` — ${data.error}`;
                statusEl.innerHTML = `
                    <span class="status-indicator error"></span>
                    No<span style="font-size:0.8rem; color:var(--color-text-secondary);">${detail}</span>`;
            }
        } catch (e) {
            statusEl.innerHTML = `
                <span class="status-indicator error"></span>
                Request failed: ${e.message}`;
        } finally {
            btn.disabled = false;
            btn.textContent = 'Re-test Connectivity';
        }
    }

    // ── Persistent SSE Listener ───────────────────────────

    _connectSse() {
        const hubUrl = window.MercureHubUrl
            || document.querySelector('meta[name="mercure-hub"]')?.content;

        if (!hubUrl) {
            this.sseStatusTarget.innerHTML =
                '<span class="status-indicator error"></span> No Mercure hub URL found (missing meta tag)';
            return;
        }

        const topic = this.hasTopicInputTarget
            ? (this.topicInputTarget.value.trim() || '/test/admin-ping')
            : '/test/admin-ping';

        // Already holding an open connection to this topic — nothing to do
        if (this._eventSource && this._currentTopic === topic
            && this._eventSource.readyState !== EventSource.CLOSED) {
            return;
        }

        this._closeSse();
        this._currentTopic = topic;

        const url = new URL(hubUrl);
        url.searchParams.append('topic', topic);

        this.sseStatusTarget.innerHTML =
            '<span class="status-indicator warning"></span> Connecting…';

        this._eventSource = new EventSource(url.toString());

        this._eventSource.onopen = () => {
            this.sseStatusTarget.innerHTML =
                `<span class="status-indicator healthy"></span> Connected — listening on <code>${this._escapeHtml(topic)}</code>`;
        };

        this._eventSource.onmessage = (event) => {
            this._messageCount++;
            const time = new Date().toLocaleTimeString();
            let content;
            try {
                const parsed = JSON.parse(event.data);
                content = JSON.stringify(parsed, null, 2);
            } catch {
                content = event.data;
            }

            const el = this.sseMessagesTarget;
            if (this._messageCount === 1) {
                el.innerHTML = '';
            }

            const msgDiv = document.createElement('div');
            msgDiv.style.cssText = 'margin-bottom:0.5rem; padding-bottom:0.5rem; border-bottom:1px solid var(--color-border-subtle, #eee);';
            msgDiv.innerHTML = `<span style="color:var(--color-text-secondary);">[${time}]</span> <pre style="margin:0; white-space:pre-wrap;">${this._escapeHtml(content)}</pre>`;
            el.prepend(msgDiv);
        };

        this._eventSource.onerror = () => {
            // readyState CLOSED  → server permanently closed the connection
            // readyState CONNECTING → browser is auto-reconnecting (normal SSE behaviour, stay quiet)
            if (this._eventSource?.readyState === EventSource.CLOSED) {
                this.sseStatusTarget.innerHTML =
                    '<span class="status-indicator error"></span> Connection closed by server';
            }
        };
    }

    _closeSse() {
        if (this._eventSource) {
            this._eventSource.close();
            this._eventSource = null;
        }
    }

    // ── Refresh Subscriptions ─────────────────────────────

    async refreshSubscriptions() {
        const btn = this.subsRefreshBtnTarget;
        const container = this.subscriptionsContainerTarget;

        btn.disabled = true;
        btn.textContent = 'Loading…';

        try {
            const resp = await fetch(this.subscriptionsUrlValue);
            const data = await resp.json();

            if (!data.available) {
                container.innerHTML = `
                    <div style="padding:0.75rem; background:var(--color-bg-light); font-size:0.875rem;">
                        <span class="status-indicator error"></span>
                        Subscriptions API unavailable${data.error ? ': ' + this._escapeHtml(data.error) : ''}
                    </div>`;
            } else if (data.total === 0) {
                container.innerHTML =
                    '<p style="color:var(--color-text-secondary); font-size:0.875rem;">No active subscriptions.</p>';
            } else {
                let rows = '';
                for (const [topic, count] of Object.entries(data.by_topic || {})) {
                    rows += `<tr style="border-bottom:1px solid var(--color-border-subtle, #f0f0f0);">
                        <td style="padding:0.4rem 0.75rem; font-family:monospace; font-size:0.8rem; word-break:break-all;">${this._escapeHtml(topic)}</td>
                        <td style="padding:0.4rem 0.75rem; text-align:right;">${count}</td>
                    </tr>`;
                }

                container.innerHTML = `
                    <div class="stat-row" style="margin-bottom:1rem;">
                        <span class="stat-label">Total Active</span>
                        <span class="stat-value" style="font-weight:600;">${data.total}</span>
                    </div>
                    <table style="width:100%; border-collapse:collapse; font-size:0.875rem;">
                        <thead>
                            <tr>
                                <th style="text-align:left; padding:0.5rem 0.75rem; border-bottom:1px solid var(--color-border);">Topic</th>
                                <th style="text-align:right; padding:0.5rem 0.75rem; border-bottom:1px solid var(--color-border);">Subscribers</th>
                            </tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>`;
            }
        } catch (e) {
            container.innerHTML = `
                <div style="padding:0.75rem; background:var(--color-bg-light); font-size:0.875rem;">
                    <span class="status-indicator error"></span>
                    Failed to fetch subscriptions: ${this._escapeHtml(e.message)}
                </div>`;
        } finally {
            btn.disabled = false;
            btn.textContent = 'Refresh';
        }
    }

    // ── Helpers ───────────────────────────────────────────

    _escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
}

