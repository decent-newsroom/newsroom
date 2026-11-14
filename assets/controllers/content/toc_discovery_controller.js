import { Controller } from '@hotwired/stimulus';

// Shared bound across occurrences. Clicking is the only trigger; connecting a
// row never schedules discovery, polls, subscribes to SSE, or fetches media.
let active = 0;
const MAX_ACTIVE = 4;
const MAX_POLLS = 20;

export default class extends Controller {
    static targets = ['panel', 'button', 'status'];
    static values = { url: String, token: String, loading: String, failed: String, limited: String, retry: String, available: String, unavailable: String };

    disconnect() {
        this.disconnected = true;
        this.abort?.abort();
        if (this.timer) {
            clearTimeout(this.timer);
            this.resume?.();
        }
    }

    async discover(event) {
        event.preventDefault();
        if (this.busy || active >= MAX_ACTIVE) {
            if (!this.busy) this.statusTarget.textContent = this.limitedValue;
            return;
        }
        this.busy = true;
        this.disconnected = false;
        active++;
        this.abort = new AbortController();
        this.buttonTarget.disabled = true;
        this.statusTarget.textContent = this.loadingValue;
        try {
            let data = await this.request('POST');
            for (let poll = 0; data.status === 'loading' && poll < MAX_POLLS; poll++) {
                await new Promise(resolve => {
                    this.resume = resolve;
                    this.timer = setTimeout(resolve, 3000);
                });
                this.timer = null;
                if (this.abort.signal.aborted) return;
                data = await this.request('GET');
            }
            if (data.status === 'available' && typeof data.html === 'string') {
                const hadFocus = this.element.contains(document.activeElement);
                // Only this occurrence; never reload the source frame or page.
                this.panelTarget.innerHTML = data.html;
                this.statusTarget.textContent = this.availableValue;
                if (hadFocus) {
                    const focus = this.panelTarget.querySelector('summary, button, a');
                    if (focus) focus.focus({ preventScroll: true });
                    else {
                        this.panelTarget.tabIndex = -1;
                        this.panelTarget.focus({ preventScroll: true });
                    }
                }
            } else {
                this.statusTarget.textContent = data.status === 'limited'
                    ? this.limitedValue
                    : data.status === 'unavailable' ? this.unavailableValue : this.failedValue;
                if (data.status !== 'unavailable') this.buttonTarget.textContent = this.retryValue;
            }
        } catch (error) {
            if (!this.disconnected && this.hasStatusTarget) {
                this.statusTarget.textContent = this.failedValue;
                this.buttonTarget.textContent = this.retryValue;
            }
        } finally {
            active--;
            this.busy = false;
            if (this.hasButtonTarget) this.buttonTarget.disabled = false;
        }
    }

    async request(method) {
        const timeout = setTimeout(() => this.abort.abort(), 10000);
        try {
            const response = await fetch(this.urlValue, {
                method, credentials: 'same-origin', signal: this.abort.signal,
                headers: { Accept: 'application/json', 'X-CSRF-Token': this.tokenValue },
                cache: 'no-store',
            });
            if (!response.ok || response.redirected) throw new Error('Discovery unavailable');
            return await response.json();
        } finally {
            clearTimeout(timeout);
        }
    }
}
