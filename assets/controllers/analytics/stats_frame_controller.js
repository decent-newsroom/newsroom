import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['frame', 'loading', 'error'];

    retry() {
        this.frameTarget.replaceChildren(this.loadingTarget.content.cloneNode(true));
        this.frameTarget.reload();
    }

    requestFailed() {
        // Let Turbo settle its failed request so lazy frames can be retried.
        this.showError();
    }

    checkResponse(event) {
        if (!event.detail.fetchResponse.response.ok) {
            event.preventDefault();
            this.showError();
        }
    }

    frameMissing(event) {
        event.preventDefault();
        const response = event.detail.response;
        // An expired session may redirect to a full login page without a frame.
        // Only follow same-origin redirects, never reload a failed fragment.
        const destination = new URL(response.url, window.location.href);
        if (response.redirected && response.ok && destination.origin === window.location.origin) {
            window.location.assign(destination.href);
            return;
        }
        this.showError();
    }

    showError() {
        this.frameTarget.replaceChildren(this.errorTarget.content.cloneNode(true));
    }
}