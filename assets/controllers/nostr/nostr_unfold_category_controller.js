import { Controller } from '@hotwired/stimulus';
import { getSigner } from './signer_manager.js';

export default class extends Controller {
    static targets = ['reference', 'retry', 'status'];
    static values = {
        publication: String, category: String, owner: String,
        prepareUrl: String, commitUrl: String, csrfToken: String, messages: Object,
    };

    connect() {
        this.pending = null;
        try {
            for (let i = 0; i < sessionStorage.length; i++) {
                const key = sessionStorage.key(i);
                if (!key?.startsWith(this.storagePrefix())) continue;
                const payload = JSON.parse(sessionStorage.getItem(key));
                if (payload.category !== this.categoryValue || key !== this.storagePrefix() + payload.event?.id) continue;
                this.pending = { ...payload, _token: this.csrfTokenValue };
                this.pendingKey = key;
                break;
            }
        } catch (error) {
            console.warn('Could not restore pending category publication', error);
        }
        if (this.pending) this.show('unfold_category.pending');
        this.retryTarget.hidden = !this.pending;
    }

    async submit(event) {
        event.preventDefault();
        if (this.busy) return;
        if (this.pending) {
            this.show('unfold_category.pending');
            return;
        }
        this.setBusy(true);
        try {
            const form = event.target;
            const payload = {
                category: this.categoryValue,
                reference: form.dataset.reference || this.referenceTarget.value,
                action: form.dataset.mutation,
                _token: this.csrfTokenValue,
            };
            const prepared = await this.post(this.prepareUrlValue, payload);
            if (prepared.unchanged === true) {
                this.show('unfold_category.unchanged');
                return;
            }
            if (!prepared.event || !prepared.base_event_id) throw new Error('unfold_category.failed');
            this.show('unfold_category.signing');
            const signer = await getSigner();
            if ((await signer.getPublicKey()).toLowerCase() !== this.ownerValue) {
                throw new Error('unfold_category.wrong_signer');
            }
            const signed = await signer.signEvent(prepared.event);
            if (!signed?.id || !signed?.sig) throw new Error('unfold_category.invalid_signature');
            this.pending = { ...payload, base_event_id: prepared.base_event_id, event: signed };
            this.pendingKey = this.storagePrefix() + signed.id;
            // Store before sending: a lost HTTP response must not require a new signature.
            const { _token, ...stored } = this.pending;
            try {
                sessionStorage.setItem(this.pendingKey, JSON.stringify(stored));
            } catch (error) {
                console.warn('Could not persist pending category publication for recovery', error);
            }
            this.retryTarget.hidden = false;
            await this.sendPending();
        } catch (error) {
            this.show(error.message in this.messagesValue ? error.message : 'unfold_category.failed');
        } finally {
            this.setBusy(false);
        }
    }

    async retry(event) {
        event.preventDefault();
        if (!this.pending || this.busy) return;
        this.setBusy(true);
        try {
            await this.sendPending();
        } catch (error) {
            this.show(error.message in this.messagesValue ? error.message : 'unfold_category.pending');
        } finally {
            this.setBusy(false);
        }
    }

    async sendPending() {
        this.show('unfold_category.publishing');
        const result = await this.post(this.commitUrlValue, this.pending);
        if (!result.ok || !result.local_commit || !result.relay_complete) {
            this.show('unfold_category.pending');
            return;
        }
        try {
            sessionStorage.removeItem(this.pendingKey);
        } catch (error) {
            console.warn('Could not clear pending category publication', error);
        }
        this.pending = null;
        window.location.reload();
    }

    async post(url, payload) {
        const response = await fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(payload),
        });
        const result = await response.json();
        if (!response.ok) {
            if ((response.status === 409 && result.error === 'unfold_category.stale') || response.status === 403 || response.status === 422) {
                try {
                    if (this.pendingKey) sessionStorage.removeItem(this.pendingKey);
                } catch (error) {
                    console.warn('Could not clear rejected category publication', error);
                }
                this.pending = null;
                this.pendingKey = null;
                this.retryTarget.hidden = true;
            }
            throw new Error(result.error || 'unfold_category.failed');
        }
        return result;
    }

    storagePrefix() {
        return 'unfold-category-pending:' + this.publicationValue + ':' + this.categoryValue + ':';
    }

    setBusy(busy) {
        this.busy = busy;
        this.element.querySelectorAll('button').forEach(button => { button.disabled = busy; });
    }

    show(key) {
        const message = this.messagesValue[key] || this.messagesValue['unfold_category.failed'];
        this.statusTarget.textContent = message;
        if (typeof window.showToast === 'function') window.showToast(message, 'info');
    }
}
