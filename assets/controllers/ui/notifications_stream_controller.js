import { Controller } from '@hotwired/stimulus';

/**
 * Opens an EventSource to the current user's private Mercure notifications topic
 * and surfaces each update as a toast (via the global `window.showToast` helper
 * installed by the utility--toast controller). Also highlights newly-arrived
 * items in the notifications page list, if present.
 *
 * Expected on: authenticated pages (Mercure subscriber cookie is set server-side).
 *
 * Values:
 *   - topicUrlValue: absolute Mercure hub URL with ?topic=... query string
 *
 * Targets:
 *   - list: <ul> to prepend new arrivals into (optional)
 */
export default class extends Controller {
    static values = { topicUrl: String };
    static targets = ['list'];

    connect() {
        if (!this.topicUrlValue || typeof window.EventSource === 'undefined') {
            return;
        }

        try {
            // Cookie-based auth — the browser sends the mercureAuthorization
            // HttpOnly cookie automatically when withCredentials is true.
            this.source = new EventSource(this.topicUrlValue, { withCredentials: true });
            this.source.onmessage = (e) => this.onMessage(e);
            this.source.onerror = () => {
                // Let the browser handle reconnection; do not spam the console.
            };
        } catch (err) {
            console.warn('notifications-stream: failed to open EventSource', err);
        }
    }

    disconnect() {
        if (this.source) {
            this.source.close();
            this.source = null;
        }
    }

    onMessage(event) {
        let payload;
        try {
            payload = JSON.parse(event.data);
        } catch (_err) {
            return;
        }
        if (!payload || payload.type !== 'notification') {
            return;
        }

        const title = payload.title || 'New notification';
        if (typeof window.showToast === 'function') {
            window.showToast(title, 'info');
        }

        this.prependToList(payload);
        this.dispatch('received', { detail: payload });
    }

    prependToList(payload) {
        if (!this.hasListTarget) {
            return;
        }
        const li = document.createElement('li');
        li.className = 'notification-item notification-item--unread notification-item--fresh';
        li.dataset.notificationId = payload.id;

        const header = document.createElement('div');
        header.className = 'notification-item__header';
        const kind = document.createElement('span');
        kind.className = 'notification-item__kind';
        kind.textContent = payload.kind === 30040 ? 'New publication' : 'New article';
        header.appendChild(kind);
        li.appendChild(header);

        const a = document.createElement('a');
        a.className = 'notification-item__title';
        a.href = payload.url || '#';
        a.textContent = title(payload);
        li.appendChild(a);

        if (payload.summary) {
            const p = document.createElement('p');
            p.className = 'notification-item__summary';
            p.textContent = payload.summary;
            li.appendChild(p);
        }

        this.listTarget.prepend(li);
    }
}

function title(p) {
    return p.title && String(p.title).trim() !== '' ? p.title : 'Untitled';
}

