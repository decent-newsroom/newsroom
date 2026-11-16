import { Controller } from '@hotwired/stimulus';

const MAX_ACTIVE = 4;
const waiting = [];
const active = new Set();

function drain() {
    while (active.size < MAX_ACTIVE) {
        const index = waiting.findIndex(job => !job.controller.disconnected && job.controller.element.isConnected && job.controller.element.open);
        if (index < 0) return;

        const [job] = waiting.splice(index, 1);
        if (job.finished) continue;
        job.running = true;
        active.add(job);
        job.frame.addEventListener('turbo:before-fetch-response', job.onResponse);
        job.frame.addEventListener('turbo:fetch-request-error', job.onResponse);
        job.resume();
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
    job.frame.removeEventListener('turbo:before-fetch-response', job.onResponse);
    job.frame.removeEventListener('turbo:fetch-request-error', job.onResponse);
    job.controller.jobs.delete(job);
    drain();
}

async function waitForBody(response) {
    const reader = response.clone().body?.getReader();
    if (!reader) return;
    try {
        while (!(await reader.read()).done) {
            // Discard the cloned stream; Turbo consumes its own response.
        }
    } finally {
        reader.releaseLock();
    }
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
        job.onResponse = response => {
            if (response.target !== frame) return;
            const body = response.detail?.fetchResponse?.response;
            if (response.type === 'turbo:fetch-request-error' || !body) {
                finish(job);
            } else {
                waitForBody(body).then(() => finish(job), () => finish(job));
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
