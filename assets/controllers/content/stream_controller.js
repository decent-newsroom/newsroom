import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['frame', 'notice', 'error'];
    static values = { url: String, pubkey: String, hubUrl: String, owner: Boolean };

    connect() {
        if (!this.hasPubkeyValue || !this.hubUrlValue) return;
        const url = new URL(this.hubUrlValue, window.location.origin);
        const types = ['overview', 'articles', 'media', 'highlights'];
        if (this.ownerValue) types.push('drafts');
        types.forEach(type => url.searchParams.append('topic', `/author/${this.pubkeyValue}/${type}`));
        this.events = new EventSource(url);
        this.events.onmessage = () => {
            if (this.hasNoticeTarget) this.noticeTarget.hidden = false;
        };
    }

    disconnect() {
        this.events?.close();
    }

    refresh() {
        this.errorTarget.hidden = true;
        if (this.hasNoticeTarget) this.noticeTarget.hidden = true;
        const url = new URL(this.urlValue, window.location.origin);
        url.searchParams.set('refresh', '1');
        if (this.frameTarget.src === url.href) {
            this.frameTarget.reload();
        } else {
            this.frameTarget.src = url.href;
        }
    }

    failed(event) {
        if (event.target !== this.frameTarget) return;
        event.preventDefault();
        this.errorTarget.hidden = false;
    }
}
