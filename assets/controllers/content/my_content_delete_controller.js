import { Controller } from '@hotwired/stimulus';
import { getSigner } from '../nostr/signer_manager.js';

export default class extends Controller {
    static targets = ['dialog', 'description', 'confirm'];

    static values = {
        publishUrl: String,
        missingEventMessage: String,
        confirmTemplate: String,
        contentTemplate: String,
        connectingMessage: String,
        signingMessage: String,
        publishingMessage: String,
        rejectedMessage: String,
        successMessage: String,
        failedTemplate: String,
        sentLabel: String,
    };

    connect() {
        this.trigger = null;
        this.eventId = '';
        this.coordinate = '';
        this.articleTitle = '';
    }

    open(event) {
        event.preventDefault();

        const trigger = event.currentTarget;
        if (!trigger.dataset.eventId) {
            this._toast(this.missingEventMessageValue, 'danger');
            return;
        }

        this.trigger = trigger;
        this.eventId = trigger.dataset.eventId;
        this.coordinate = trigger.dataset.coordinate || '';
        this.articleTitle = trigger.dataset.articleTitle || '';
        this.descriptionTarget.textContent = this._interpolate(
            this.confirmTemplateValue,
            '__TITLE__',
            this.articleTitle,
        );

        trigger.closest('details')?.removeAttribute('open');
        this.dialogTarget.showModal();
    }

    cancel(event) {
        event?.preventDefault();
        this.dialogTarget.close();
    }

    dismissOnBackdrop(event) {
        if (event.target === this.dialogTarget) {
            this.cancel(event);
        }
    }

    async confirm(event) {
        event.preventDefault();

        if (!this.trigger || !this.eventId) {
            this._toast(this.missingEventMessageValue, 'danger');
            this.dialogTarget.close();
            return;
        }

        const trigger = this.trigger;
        trigger.disabled = true;
        trigger.setAttribute('aria-busy', 'true');
        this.confirmTarget.disabled = true;
        this.dialogTarget.close();

        try {
            this._toast(this.connectingMessageValue, 'info');
            const signer = await getSigner();
            const pubkey = await signer.getPublicKey();

            const tags = [['e', this.eventId]];
            if (this.coordinate) {
                tags.push(['a', this.coordinate]);
            }

            const skeleton = {
                kind: 5,
                created_at: Math.floor(Date.now() / 1000),
                tags,
                content: this._interpolate(
                    this.contentTemplateValue,
                    '__TITLE__',
                    this.articleTitle,
                ),
                pubkey,
            };

            this._toast(this.signingMessageValue, 'info');
            const signedEvent = await signer.signEvent(skeleton);

            this._toast(this.publishingMessageValue, 'info');
            const result = await this._publish(signedEvent);

            if (!(result.success || result.status === 'ok')) {
                throw new Error(this.rejectedMessageValue);
            }

            this._toast(this.successMessageValue, 'success');
            const label = trigger.querySelector('[data-delete-label]');
            if (label) {
                label.textContent = this.sentLabelValue;
            }
            trigger.title = this.sentLabelValue;
        } catch (error) {
            console.error('[my-content-delete] Failed to publish delete request:', error);
            this._toast(
                this._interpolate(this.failedTemplateValue, '__ERROR__', error.message),
                'danger',
            );
            trigger.disabled = false;
        } finally {
            trigger.removeAttribute('aria-busy');
            this.confirmTarget.disabled = false;
        }
    }

    async _publish(signedEvent) {
        const response = await fetch(this.publishUrlValue, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ event: signedEvent }),
        });

        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            throw new Error(data.error || `HTTP ${response.status}`);
        }

        return response.json();
    }

    _interpolate(template, token, value) {
        return template.replace(token, value);
    }

    _toast(message, type = 'info', duration = 4000) {
        if (typeof window.showToast === 'function') {
            window.showToast(message, type, duration);
        }
    }
}
