import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'subdomainInput',
        'magazineSelect',
        'coordinateDisplay',
        'coordinateText',
        'themeSelect',
        'preview',
        'signer',
        'publishButton',
    ];

    connect() {
        this.updatePreview();
    }

    updatePreview() {
        const subdomain = this.sanitizeSubdomain(this.subdomainInputTarget.value);
        if (this.subdomainInputTarget.value !== subdomain) {
            this.subdomainInputTarget.value = subdomain;
        }

        const coordinate = this.hasMagazineSelectTarget ? this.magazineSelectTarget.value : '';
        if (this.hasCoordinateDisplayTarget) {
            this.coordinateDisplayTarget.style.display = coordinate ? 'block' : 'none';
        }
        if (this.hasCoordinateTextTarget) {
            this.coordinateTextTarget.textContent = coordinate;
        }

        this.previewTarget.textContent = JSON.stringify(this.buildEvent(
            subdomain || 'my-site',
            coordinate || '<kind:pubkey:identifier>',
            this.themeSelectTarget.value,
            Math.floor(Date.now() / 1000),
        ), null, 2);
    }

    async signAndPublish(event) {
        event.preventDefault();

        const subdomain = this.sanitizeSubdomain(this.subdomainInputTarget.value);
        const coordinate = this.hasMagazineSelectTarget ? this.magazineSelectTarget.value : '';
        const theme = this.themeSelectTarget.value.trim();

        if (!subdomain) {
            this.showValidationError('Please enter a subdomain.', this.subdomainInputTarget);
            return;
        }

        if (!coordinate) {
            this.showValidationError(
                'Please select a magazine.',
                this.hasMagazineSelectTarget ? this.magazineSelectTarget : undefined,
            );
            return;
        }

        if (!this.isMagazineCoordinate(coordinate)) {
            this.showValidationError('Invalid coordinate format. Expected: 30040:pubkey:identifier');
            return;
        }

        if (!theme) {
            this.showValidationError('Please select a theme.', this.themeSelectTarget);
            return;
        }

        if (!this.element.reportValidity()) {
            return;
        }

        const signer = this.application.getControllerForElementAndIdentifier(
            this.signerTarget,
            'nostr--nostr-single-sign',
        );

        if (!signer) {
            this.showValidationError('Signing is not available. Please try again.');
            return;
        }

        this.publishButtonTarget.disabled = true;
        try {
            await signer.signAndPublishEvent(
                this.buildEvent(subdomain, coordinate, theme),
                { subdomain },
            );
        } finally {
            this.publishButtonTarget.disabled = false;
        }
    }

    buildEvent(subdomain, coordinate, theme, createdAt = undefined) {
        const event = {
            kind: 30078,
            tags: [
                ['d', subdomain],
                ['a', coordinate],
                ['theme', theme],
                ['alt', 'Unfold App Config'],
            ],
            content: `Unfold App Config for '${subdomain}'`,
        };

        if (createdAt !== undefined) {
            event.created_at = createdAt;
        }

        return event;
    }

    sanitizeSubdomain(value) {
        return value
            .trim()
            .toLowerCase()
            .replace(/[^a-z0-9-]/g, '')
            .replace(/^-+|-+$/g, '');
    }

    isMagazineCoordinate(coordinate) {
        return /^30040:[a-f0-9]{64}:.+$/i.test(coordinate);
    }

    showValidationError(message, target = undefined) {
        window.alert(message);
        target?.focus();
    }
}
