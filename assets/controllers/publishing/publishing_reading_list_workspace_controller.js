import { Controller } from '@hotwired/stimulus';
import { decodeNip19 } from '../../typescript/nostr-utils.ts';

export default class extends Controller {
    static targets = ['articleInput', 'articleCount', 'publishButton', 'validation'];

    static values = {
        event: String,
        invalidMessage: String,
    };

    connect() {
        this.sync();
    }

    articleInputTargetConnected() {
        queueMicrotask(() => this.sync());
    }

    articleInputTargetDisconnected() {
        queueMicrotask(() => this.sync());
    }

    sync() {
        const event = JSON.parse(this.eventValue || '{}');
        const coordinates = [];
        let hasInvalidInput = false;

        this.articleInputTargets.forEach((input) => {
            const rawValue = input.value.trim();
            const coordinate = this.normalizeCoordinate(rawValue);
            const isValid = rawValue === '' || this.isCoordinate(coordinate);

            input.setAttribute('aria-invalid', isValid ? 'false' : 'true');

            if (!isValid) {
                hasInvalidInput = true;
                return;
            }

            if (coordinate && !coordinates.includes(coordinate)) {
                coordinates.push(coordinate);
            }
        });

        event.tags = (event.tags || []).filter((tag) => tag[0] !== 'a');
        coordinates.forEach((coordinate) => event.tags.push(['a', coordinate]));

        this.element.setAttribute(
            'data-nostr--nostr-single-sign-event-value',
            JSON.stringify(event),
        );

        if (this.hasArticleCountTarget) {
            this.articleCountTarget.textContent = coordinates.length.toString();
        }

        if (this.hasPublishButtonTarget) {
            this.publishButtonTarget.disabled = hasInvalidInput;
        }

        if (this.hasValidationTarget) {
            this.validationTarget.hidden = !hasInvalidInput;
            this.validationTarget.textContent = hasInvalidInput ? this.invalidMessageValue : '';
        }
    }

    normalizeCoordinate(value) {
        const nip19Value = value.replace(/^nostr:/, '');
        if (!nip19Value.startsWith('naddr1')) {
            return value;
        }

        const decoded = decodeNip19(nip19Value);
        if (
            decoded?.type !== 'naddr'
            || decoded.data.kind === undefined
            || !decoded.data.pubkey
            || decoded.data.identifier === undefined
        ) {
            return value;
        }

        return `${decoded.data.kind}:${decoded.data.pubkey}:${decoded.data.identifier}`;
    }

    isCoordinate(value) {
        return /^\d+:[a-f0-9]{64}:.+$/i.test(value);
    }
}
