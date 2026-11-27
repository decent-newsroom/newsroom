import { Controller } from '@hotwired/stimulus';

const MAX_ACTIVE = 4;
const COMPLETION_EVENTS = ['turbo:frame-load', 'turbo:frame-missing', 'turbo:fetch-request-error'];
const waiting = [];
const active = new Set();

function drain() {
    while (active.size < MAX_ACTIVE) {
        const index = waiting.findIndex(job => !job.controller.disconnected && job.controller.element.isConnected
            && (job.controller.element.tagName !== 'DETAILS' || job.controller.element.open));
        if (index < 0) return;

        const [job] = waiting.splice(index, 1);
        if (job.finished) continue;
        job.running = true;
        active.add(job);
        for (const eventName of COMPLETION_EVENTS) {
            job.frame.addEventListener(eventName, job.onComplete);
        }
        job.resume();
        // Turbo assigns `loaded` after dispatching before-fetch-request. It also
        // settles responses that do not render a frame, such as an empty body.
        queueMicrotask(() => {
            if (!job.finished) {
                job.frame.loaded?.then(job.finish, job.finish);
            }
        });
    }
}

function finish(job) {
    if (job.finished) return;
    job.finished = true;
    const index = waiting.indexOf(job);
    if (index >= 0) waiting.splice(index, 1);
    active.delete(job);
    job.signal.removeEventListener('abort', job.onAbort);
    job.originalSignal.removeEventListener('abort', job.onOriginalAbort);
    for (const eventName of COMPLETION_EVENTS) {
        job.frame.removeEventListener(eventName, job.onComplete);
    }
    job.controller.jobs.delete(job);
    drain();
}

export default class extends Controller {
    connect() {
        this.disconnected = false;
        this.jobs = new Set();
    }

    disconnect() {
        this.disconnected = true;
        for (const job of this.jobs) {
            job.abortController.abort();
        }
    }

    beforeRequest(event) {
        const frame = event.target;
        if (frame.parentElement !== this.element) return;
        const { fetchOptions, resume } = event.detail;
        if (!fetchOptions?.signal || typeof resume !== 'function') return;

        event.preventDefault();
        const abortController = new AbortController();
        const job = {
            controller: this,
            frame,
            abortController,
            originalSignal: fetchOptions.signal,
            signal: abortController.signal,
            resume,
            running: false,
            finished: false,
        };
        fetchOptions.signal = job.signal;
        job.finish = () => finish(job);
        job.onComplete = event => {
            if (event.target === frame) {
                job.finish();
            }
        };
        job.onAbort = () => {
            const wasWaiting = !job.running;
            finish(job);
            if (wasWaiting) job.resume();
        };
        job.onOriginalAbort = () => job.abortController.abort();
        this.jobs.add(job);
        waiting.push(job);
        job.signal.addEventListener('abort', job.onAbort, { once: true });
        job.originalSignal.addEventListener('abort', job.onOriginalAbort, { once: true });
        if (job.originalSignal.aborted) job.onOriginalAbort();
        else drain();
    }

    toggle() {
        drain();
    }
}
