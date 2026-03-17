import { Controller } from '@hotwired/stimulus';

/**
 * Subscribes to a Mercure topic for curation media sync completion and refreshes
 * the current page when missing media items have been persisted.
 *
 * stimulusFetch: 'lazy'
 */
export default class extends Controller {
    static values = {
        topic: String,
        reloadUrl: String,
    };

    connect() {
        const hubUrl = window.MercureHubUrl || document.querySelector('meta[name="mercure-hub"]')?.content;
        if (!hubUrl || !this.hasTopicValue || !this.topicValue) {
            return;
        }

        const url = new URL(hubUrl);
        url.searchParams.append('topic', this.topicValue);

        this.eventSource = new EventSource(url.toString());
        this.eventSource.onmessage = (event) => this.handleMessage(event);
        this.eventSource.onerror = () => {
            // Let EventSource auto-reconnect; no-op here.
        };
    }

    disconnect() {
        try {
            this.eventSource?.close();
        } catch {
            // no-op
        }
    }

    handleMessage(event) {
        try {
            const data = JSON.parse(event.data);
            if (!data || (data.count ?? 0) < 1) {
                return;
            }
        } catch {
            // Fall back to reloading on any non-empty message.
        }

        this.disconnect();

        const reloadUrl = this.hasReloadUrlValue && this.reloadUrlValue
            ? this.reloadUrlValue
            : window.location.href;

        if (window.Turbo?.visit) {
            window.Turbo.visit(reloadUrl, { action: 'replace' });
            return;
        }

        window.location.href = reloadUrl;
    }
}

