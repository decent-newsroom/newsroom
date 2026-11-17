import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { topicUrl: String, itemsUrl: String, message: String, error: String };
    static targets = ['list'];

    connect() {
        this.connected = true;
        if (this.hasItemsUrlValue && this.itemsUrlValue) {
            this.receive = () => {
                clearTimeout(this.refreshTimer);
                this.refreshTimer = setTimeout(() => this.refresh(), 150);
            };
            window.addEventListener('subscriptions:updated', this.receive);
            return;
        }
        if (!this.topicUrlValue || typeof window.EventSource === 'undefined') {
            return;
        }
        this.source = new EventSource(this.topicUrlValue, { withCredentials: true });
        this.source.onmessage = (event) => {
            let payload;
            try {
                payload = JSON.parse(event.data);
            } catch (error) {
                console.warn('subscriptions: invalid update signal', error);
                return;
            }
            if (payload?.type !== 'update') {
                return;
            }
            if (this.messageValue && typeof window.showToast === 'function') {
                window.showToast(this.messageValue, 'info');
            }
            window.dispatchEvent(new CustomEvent('subscriptions:updated'));
        };
    }

    disconnect() {
        this.connected = false;
        this.source?.close();
        this.request?.abort();
        clearTimeout(this.refreshTimer);
        if (this.receive) {
            window.removeEventListener('subscriptions:updated', this.receive);
        }
    }

    async refresh() {
        if (!this.hasListTarget) {
            return;
        }
        this.request?.abort();
        this.request = new AbortController();
        try {
            // Reauthorize in the reader's HTTP session; the push contains no metadata.
            const response = await fetch(this.itemsUrlValue, {
                credentials: 'same-origin',
                cache: 'no-store',
                signal: this.request.signal,
            });
            if (!response.ok || response.redirected) {
                throw new Error(`Subscription feed returned ${response.status}`);
            }
            const html = await response.text();
            if (this.connected) {
                this.listTarget.innerHTML = html;
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.warn('subscriptions: feed refresh failed', error);
                if (this.errorValue && typeof window.showToast === 'function') {
                    window.showToast(this.errorValue, 'error');
                }
            }
        }
    }
}
