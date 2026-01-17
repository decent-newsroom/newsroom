import { Controller } from '@hotwired/stimulus';

// Inline utility functions
function calculateShares(splits) {
    if (splits.length === 0) {
        return [];
    }

    // Check if any weights are specified
    const hasWeights = splits.some(s => s.weight !== undefined && s.weight !== null && s.weight > 0);

    if (!hasWeights) {
        // Equal distribution
        const equalShare = 100 / splits.length;
        return splits.map(() => equalShare);
    }

    // Calculate total weight
    const totalWeight = splits.reduce((sum, s) => sum + (s.weight || 0), 0);

    if (totalWeight === 0) {
        return splits.map(() => 0);
    }

    // Calculate weighted shares
    return splits.map(s => {
        const weight = s.weight || 0;
        return (weight / totalWeight) * 100;
    });
}

function isValidPubkey(pubkey) {
    if (!pubkey) return false;

    // Check if hex (64 chars)
    if (/^[0-9a-f]{64}$/i.test(pubkey)) {
        return true;
    }

    // Check if npub (basic check)
    if (pubkey.startsWith('npub1') && pubkey.length > 60) {
        return true;
    }

    return false;
}

function isValidRelay(relay) {
    if (!relay) return true; // Empty is valid (optional)

    try {
        const url = new URL(relay);
        return url.protocol === 'wss:';
    } catch {
        return false;
    }
}

export default class extends Controller {
    static targets = [
        'zapSplitsContainer',
        'addZapButton',
        'distributeEquallyButton',
        'licenseSelect',
        'customLicenseInput',
        'protectedCheckbox',
        'protectedWarning',
        'expirationInput'
    ];

    connect() {
        console.log('Advanced metadata controller connected');
        this.updateLicenseVisibility();
        this.updateProtectedWarning();
        this.updateZapShares();
    }

    /**
     * Add a new zap split row
     */
    addZapSplit(event) {
        event.preventDefault();

        const container = this.zapSplitsContainerTarget;
        const prototype = container.dataset.prototype;

        if (!prototype) {
            console.error('No prototype found for zap splits');
            return;
        }

        // Get the current index
        const index = parseInt(container.dataset.index || '0', 10);

        // Replace __name__ with the index
        const newForm = prototype.replace(/__name__/g, index.toString());

        // Create wrapper div
        const wrapper = document.createElement('div');
        wrapper.classList.add('zap-split-item', 'mb-3');
        wrapper.dataset.index = index.toString();
        wrapper.innerHTML = newForm;

        // Add delete button
        const deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.classList.add('btn', 'btn-sm', 'btn-danger', 'mt-2');
        deleteBtn.textContent = 'Remove';
        deleteBtn.setAttribute('data-action', 'click->content--advanced-metadata#removeZapSplit');
        wrapper.appendChild(deleteBtn);

        // Add share percentage display
        const shareDisplay = document.createElement('div');
        shareDisplay.classList.add('zap-share-display', 'mt-2', 'text-muted');
        shareDisplay.innerHTML = '<small>Share: <span class="share-percent">0</span>%</small>';
        wrapper.appendChild(shareDisplay);

        container.appendChild(wrapper);

        // Update index
        container.dataset.index = (index + 1).toString();

        // Add event listeners for live validation and share calculation
        this.attachZapSplitListeners(wrapper);

        this.updateZapShares();
    }

    /**
     * Remove a zap split row
     */
    removeZapSplit(event) {
        event.preventDefault();

        // Find the zap-split-item wrapper
        const wrapper = event.target.closest('.zap-split-item');
        if (wrapper) {
            wrapper.remove();
            this.updateZapShares();
        }
    }

    /**
     * Distribute weights equally among all splits
     */
    distributeEqually(event) {
        event.preventDefault();

        const splits = this.zapSplitsContainerTarget.querySelectorAll('.zap-split-item');

        splits.forEach((split) => {
            const weightInput = split.querySelector('.zap-weight');
            if (weightInput) {
                weightInput.value = '1';
            }
        });

        this.updateZapShares();
    }

    /**
     * Update share percentages for all zap splits
     */
    updateZapShares() {
        const splits = this.zapSplitsContainerTarget.querySelectorAll('.zap-split-item');
        const zapSplits = [];

        splits.forEach((split) => {
            const weightInput = split.querySelector('.zap-weight');
            const weight = weightInput?.value ? parseInt(weightInput.value, 10) : undefined;

            zapSplits.push({
                recipient: '',
                weight: weight
            });
        });

        const shares = calculateShares(zapSplits);

        splits.forEach((split, index) => {
            const shareDisplay = split.querySelector('.share-percent');
            if (shareDisplay) {
                shareDisplay.textContent = shares[index].toFixed(1);
            }
        });
    }

    /**
     * Attach event listeners to a zap split row
     */
    attachZapSplitListeners(wrapper) {
        const recipientInput = wrapper.querySelector('.zap-recipient');
        const relayInput = wrapper.querySelector('.zap-relay');
        const weightInput = wrapper.querySelector('.zap-weight');

        if (recipientInput) {
            recipientInput.addEventListener('blur', (e) => this.validateRecipient(e.target));
        }

        if (relayInput) {
            relayInput.addEventListener('blur', (e) => this.validateRelay(e.target));
        }

        if (weightInput) {
            weightInput.addEventListener('input', () => this.updateZapShares());
        }
    }

    /**
     * Validate recipient pubkey (npub or hex)
     */
    validateRecipient(input) {
        const value = input.value.trim();

        if (!value) {
            this.setInputValid(input, true);
            return;
        }

        const isValid = isValidPubkey(value);
        this.setInputValid(input, isValid, isValid ? '' : 'Invalid pubkey. Must be npub or 64-character hex.');
    }

    /**
     * Validate relay URL
     */
    validateRelay(input) {
        const value = input.value.trim();

        if (!value) {
            this.setInputValid(input, true);
            return;
        }

        const isValid = isValidRelay(value);
        this.setInputValid(input, isValid, isValid ? '' : 'Invalid relay URL. Must start with wss://');
    }

    /**
     * Set input validation state
     */
    setInputValid(input, isValid, message = '') {
        if (isValid) {
            input.classList.remove('is-invalid');
            input.classList.add('is-valid');

            // Remove error message
            const feedback = input.parentElement?.querySelector('.invalid-feedback');
            if (feedback) {
                feedback.remove();
            }
        } else {
            input.classList.remove('is-valid');
            input.classList.add('is-invalid');

            // Add/update error message
            let feedback = input.parentElement?.querySelector('.invalid-feedback');
            if (!feedback) {
                feedback = document.createElement('div');
                feedback.classList.add('invalid-feedback');
                input.parentElement?.appendChild(feedback);
            }
            feedback.textContent = message;
        }
    }

    /**
     * Update license field visibility based on selection
     */
    updateLicenseVisibility() {
        if (!this.hasLicenseSelectTarget || !this.hasCustomLicenseInputTarget) {
            return;
        }

        const isCustom = this.licenseSelectTarget.value === 'custom';
        const customWrapper = this.customLicenseInputTarget.closest('.mb-3');

        if (customWrapper) {
            if (isCustom) {
                customWrapper.style.display = 'block';
                this.customLicenseInputTarget.required = true;
            } else {
                customWrapper.style.display = 'none';
                this.customLicenseInputTarget.required = false;
                this.customLicenseInputTarget.value = '';
            }
        }
    }

    /**
     * Show/hide protected event warning
     */
    updateProtectedWarning() {
        if (!this.hasProtectedCheckboxTarget || !this.hasProtectedWarningTarget) {
            return;
        }

        if (this.protectedCheckboxTarget.checked) {
            this.protectedWarningTarget.style.display = 'block';
        } else {
            this.protectedWarningTarget.style.display = 'none';
        }
    }

    /**
     * Validate expiration is in the future
     */
    validateExpiration() {
        if (!this.hasExpirationInputTarget) {
            return;
        }

        const value = this.expirationInputTarget.value;
        if (!value) {
            this.setInputValid(this.expirationInputTarget, true);
            return;
        }

        const expirationDate = new Date(value);
        const now = new Date();

        const isValid = expirationDate > now;
        this.setInputValid(
            this.expirationInputTarget,
            isValid,
            isValid ? '' : 'Expiration date must be in the future'
        );
    }

    /**
     * Event handler for license select change
     */
    licenseChanged() {
        this.updateLicenseVisibility();
    }

    /**
     * Event handler for protected checkbox change
     */
    protectedChanged() {
        this.updateProtectedWarning();
    }

    /**
     * Event handler for expiration input change
     */
    expirationChanged() {
        this.validateExpiration();
    }
}

