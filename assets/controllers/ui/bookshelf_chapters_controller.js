import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['initial', 'deferred'];

    connect() {
        this.frames = new Set([...this.initialTargets, ...this.deferredTargets]);
        this.initialPending = new Set(this.initialTargets.filter(frame => !frame.dataset.bookshelfChapterState));
        this.visible = new Set();
        this.loadingDeferred = null;
        if (typeof IntersectionObserver === 'function') {
            this.observer = new IntersectionObserver(entries => {
                for (const { target, isIntersecting } of entries) {
                    if (isIntersecting) {
                        this.visible.add(target);
                    } else {
                        this.visible.delete(target);
                    }
                }
                this.loadVisible();
            });
            for (const frame of this.deferredTargets) {
                if (!frame.dataset.bookshelfChapterState) {
                    frame.dataset.bookshelfChapterState = 'waiting';
                }
            }
        }

        // Let the child frame controllers connect before starting Turbo requests.
        queueMicrotask(() => {
            if (!this.element.isConnected) return;
            for (const frame of this.initialPending) {
                this.load(frame);
            }
            this.observeDeferred();
        });
    }

    loadVisible() {
        if (this.loadingDeferred !== null) return;

        for (const frame of this.visible) {
            if (frame.dataset.bookshelfChapterState !== 'waiting') continue;
            // A rendered chapter can push the next placeholder below the viewport.
            const { top, bottom } = frame.getBoundingClientRect();
            if (top >= window.innerHeight || bottom <= 0) continue;

            this.loadingDeferred = frame;
            this.load(frame);
            return;
        }
    }

    disconnect() {
        this.observer?.disconnect();
        this.observer = null;
    }

    observeDeferred() {
        if (!this.observer || this.initialPending.size > 0) return;

        for (const frame of this.deferredTargets) {
            if (frame.dataset.bookshelfChapterState === 'waiting') {
                this.observer.observe(frame);
            }
        }
    }

    loadRequested(event) {
        if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        event.preventDefault();
        this.load(event.currentTarget.closest('turbo-frame'));
    }

    load(frame) {
        const state = frame.dataset.bookshelfChapterState;
        if (state === 'loading' || state === 'loaded') return;

        this.visible.delete(frame);
        this.observer?.unobserve(frame);
        frame.dataset.bookshelfChapterState = 'loading';
        frame.removeAttribute('src');
        frame.removeAttribute('complete');
        frame.setAttribute('src', frame.dataset.bookshelfChapterUrl);
    }

    loaded(event) {
        this.settle(event.target, 'loaded');
    }

    response(event) {
        const { isHTML, statusCode } = event.detail.fetchResponse;
        if (!isHTML || statusCode === 204) this.failed(event);
    }

    failed(event) {
        if (this.settle(event.target, 'failed')) {
            event.preventDefault();
        }
    }

    settle(frame, state) {
        if (!this.frames.has(frame) || frame.dataset.bookshelfChapterState !== 'loading') return false;

        frame.dataset.bookshelfChapterState = state;
        if (this.initialPending.delete(frame)) {
            this.observeDeferred();
        }
        if (this.loadingDeferred === frame) {
            this.loadingDeferred = null;
            this.loadVisible();
        }
        return true;
    }

    beforeCache() {
        this.observer?.disconnect();
        for (const frame of this.frames) {
            if (frame.dataset.bookshelfChapterState === 'loading') {
                frame.removeAttribute('src');
                delete frame.dataset.bookshelfChapterState;
            }
        }
    }
}
