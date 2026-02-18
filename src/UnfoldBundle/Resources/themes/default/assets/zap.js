/**
 * Unfold Zap Handler - NIP-57 Lightning Zaps
 * Vanilla JavaScript for modal-based invoice generation
 */

(function() {
    'use strict';

    const ZapHandler = {
        currentModal: null,
        currentInvoices: [],
        currentInvoiceIndex: 0,
        currentZapData: null,

        /**
         * Get lightning bolt SVG icon
         */
        getLightningIcon() {
            return `<span class="zap-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
            </svg></span>`;
        },

        /**
         * Initialize zap buttons on page load
         */
        init() {
            document.addEventListener('DOMContentLoaded', () => {
                const zapButtons = document.querySelectorAll('[data-zap-button]');
                zapButtons.forEach(button => {
                    button.addEventListener('click', (e) => {
                        e.preventDefault();
                        this.openZapModal(button);
                    });
                });
            });
        },

        /**
         * Open zap modal with data from button
         */
        openZapModal(button) {
            const pubkey = button.dataset.zapPubkey;
            let lud16 = button.dataset.zapLud16;
            let lud06 = button.dataset.zapLud06;
            const splitsJson = button.dataset.zapSplits;
            const recipient = button.dataset.zapRecipient || 'this author';

            // Handle lud16 as array (take first element if it's an array)
            if (lud16) {
                try {
                    const parsed = JSON.parse(lud16);
                    if (Array.isArray(parsed)) {
                        lud16 = parsed[0] || null;
                    } else {
                        lud16 = parsed;
                    }
                } catch (e) {
                    // Not JSON, use as-is (it's already a string)
                }
            }

            // Handle lud06 as array (take first element if it's an array)
            if (lud06) {
                try {
                    const parsed = JSON.parse(lud06);
                    if (Array.isArray(parsed)) {
                        lud06 = parsed[0] || null;
                    } else {
                        lud06 = parsed;
                    }
                } catch (e) {
                    // Not JSON, use as-is
                }
            }

            if (!lud16 && !lud06) {
                alert('No Lightning address configured for this recipient');
                return;
            }

            let zapSplits = [];
            if (splitsJson) {
                try {
                    zapSplits = JSON.parse(splitsJson);
                } catch (e) {
                    console.error('Failed to parse zap splits:', e);
                }
            }

            this.showInputPhase(pubkey, lud16, lud06, zapSplits, recipient);
        },

        /**
         * Show input phase - amount and comment form
         */
        showInputPhase(pubkey, lud16, lud06, zapSplits, recipient) {
            const hasSplits = zapSplits && zapSplits.length > 0;

            let splitsHtml = '';
            if (hasSplits) {
                splitsHtml = `
                    <div class="zap-split-info">
                        <strong>${this.getLightningIcon()} Split Payment</strong>
                        <p style="margin: 0.5rem 0 0.25rem 0; font-size: 0.9rem;">
                            This zap will be split between ${zapSplits.length} recipient(s)
                        </p>
                    </div>
                `;
            }

            // Store data for later use
            this.currentZapData = {
                pubkey: pubkey,
                lud16: lud16 || null,
                lud06: lud06 || null,
                zapSplits: zapSplits || []
            };

            const modalHtml = `
                <div class="zap-modal-overlay" id="zapModal">
                    <div class="zap-modal-content" onclick="event.stopPropagation()">
                        <div class="zap-modal-header">
                            <div>
                                <h3 class="zap-modal-title">Send Zap</h3>
                                <p class="zap-modal-subtitle">to ${this.escapeHtml(recipient)}</p>
                            </div>
                            <button class="zap-close-btn" onclick="ZapHandler.closeModal()">Close</button>
                        </div>

                        ${splitsHtml}

                        <form id="zapForm" onsubmit="return false;">
                            <div class="zap-form-group">
                                <label class="zap-form-label" for="zapAmount">Amount (sats)</label>
                                <input
                                    type="number"
                                    id="zapAmount"
                                    class="zap-form-input"
                                    min="1"
                                    step="1"
                                    value="21"
                                    required
                                    autofocus
                                />
                                <div class="zap-amount-buttons">
                                    <button type="button" class="zap-amount-btn" onclick="ZapHandler.setAmount(21)">21</button>
                                    <button type="button" class="zap-amount-btn" onclick="ZapHandler.setAmount(100)">100</button>
                                    <button type="button" class="zap-amount-btn" onclick="ZapHandler.setAmount(1000)">1k</button>
                                    <button type="button" class="zap-amount-btn" onclick="ZapHandler.setAmount(21000)">21k</button>
                                </div>
                            </div>

                            <div class="zap-form-group">
                                <label class="zap-form-label" for="zapComment">Note (optional)</label>
                                <textarea
                                    id="zapComment"
                                    class="zap-form-input zap-form-textarea"
                                    placeholder="Add a message..."
                                ></textarea>
                            </div>

                            <button type="button" class="zap-button" style="width: 100%;" onclick="ZapHandler.createInvoiceFromForm()">
                                ${this.getLightningIcon()} Create Invoice
                            </button>
                        </form>
                    </div>
                </div>
            `;

            document.body.insertAdjacentHTML('beforeend', modalHtml);
            this.currentModal = document.getElementById('zapModal');

            // Close on overlay click
            this.currentModal.addEventListener('click', () => this.closeModal());
        },

        /**
         * Set amount in the form
         */
        setAmount(amount) {
            const input = document.getElementById('zapAmount');
            if (input) {
                input.value = amount;
            }
        },

        /**
         * Create invoice from form data (uses stored currentZapData)
         */
        async createInvoiceFromForm() {
            const amount = parseInt(document.getElementById('zapAmount').value);
            const comment = document.getElementById('zapComment').value;

            if (!amount || amount <= 0) {
                alert('Please enter a valid amount');
                return;
            }

            const { pubkey, lud16, lud06, zapSplits } = this.currentZapData;

            this.showLoadingPhase();

            try {
                if (zapSplits && zapSplits.length > 0) {
                    await this.createSplitInvoices(zapSplits, amount, comment);
                } else {
                    await this.createSingleInvoice(pubkey, lud16, lud06, amount, comment);
                }
            } catch (error) {
                this.showErrorPhase(error.message);
            }
        },

        /**
         * Create invoice(s) - DEPRECATED, use createInvoiceFromForm instead
         */
        async createInvoice(pubkey, lud16, lud06, zapSplits) {
            const amount = parseInt(document.getElementById('zapAmount').value);
            const comment = document.getElementById('zapComment').value;

            if (!amount || amount <= 0) {
                alert('Please enter a valid amount');
                return;
            }

            this.showLoadingPhase();

            try {
                if (zapSplits && zapSplits.length > 0) {
                    await this.createSplitInvoices(zapSplits, amount, comment);
                } else {
                    await this.createSingleInvoice(pubkey, lud16, lud06, amount, comment);
                }
            } catch (error) {
                this.showErrorPhase(error.message);
            }
        },

        /**
         * Create a single invoice
         */
        async createSingleInvoice(pubkey, lud16, lud06, amount, comment) {
            const response = await fetch('/unfold/api/zap/invoice', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ pubkey, lud16, lud06, amount, comment })
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                console.error('Zap invoice creation failed:', data);
                throw new Error(data.error || 'Failed to create invoice');
            }

            this.showInvoicePhase(data.bolt11, data.qrSvg, amount);
        },

        /**
         * Create multiple invoices for splits
         */
        async createSplitInvoices(zapSplits, amount, comment) {
            const response = await fetch('/unfold/api/zap/invoice-split', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ zapSplits, amount, comment })
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Failed to create invoices');
            }

            this.currentInvoices = data.invoices;
            this.currentInvoiceIndex = 0;
            this.showSplitInvoicePhase();
        },

        /**
         * Show loading phase
         */
        showLoadingPhase() {
            const content = this.currentModal.querySelector('.zap-modal-content');
            content.innerHTML = `
                <div style="text-align: center; padding: 2rem;">
                    <div class="zap-spinner"></div>
                    <p style="margin-top: 1rem; color: #666;">Creating invoice...</p>
                </div>
            `;
        },

        /**
         * Show single invoice phase
         */
        showInvoicePhase(bolt11, qrSvg, amount) {
            const content = this.currentModal.querySelector('.zap-modal-content');
            content.innerHTML = `
                <div class="zap-modal-header">
                    <h3 class="zap-modal-title">⚡ Invoice Ready</h3>
                    <button class="zap-close-btn" onclick="ZapHandler.closeModal()">Close</button>
                </div>

                <div class="zap-status zap-status-success">
                    ✓ Invoice created for ${amount} sats
                </div>

                <div class="zap-qr-container">
                    ${qrSvg}
                </div>

                <div class="zap-form-group">
                    <label class="zap-form-label" style="font-size: 0.85rem; color: #666;">BOLT11 Invoice:</label>
                    <div class="zap-invoice-input-group">
                        <input
                            type="text"
                            class="zap-invoice-input"
                            value="${this.escapeHtml(bolt11)}"
                            readonly
                            onclick="this.select()"
                        />
                        <button class="zap-copy-btn" onclick="ZapHandler.copyInvoice('${this.escapeHtml(bolt11)}', this)">Copy</button>
                    </div>
                </div>

                <a href="lightning:${bolt11.toUpperCase()}" class="zap-wallet-btn">
                    ${this.getLightningIcon()} Open in Wallet
                </a>

                <p style="text-align: center; margin-top: 1rem; font-size: 0.9rem; color: #666;">
                    Scan the QR code or copy the invoice
                </p>
            `;
        },

        /**
         * Show split invoice phase (with navigation)
         */
        showSplitInvoicePhase() {
            const invoice = this.currentInvoices[this.currentInvoiceIndex];
            const totalInvoices = this.currentInvoices.length;
            const isFirst = this.currentInvoiceIndex === 0;
            const isLast = this.currentInvoiceIndex === totalInvoices - 1;

            const content = this.currentModal.querySelector('.zap-modal-content');

            let invoiceContent = '';
            if (invoice.error) {
                invoiceContent = `
                    <div class="zap-status zap-status-error">
                        <strong>Error:</strong> ${this.escapeHtml(invoice.error)}
                    </div>
                `;
            } else {
                invoiceContent = `
                    <div class="zap-status zap-status-success">
                        ✓ Invoice ready for ${invoice.amount} sats
                    </div>

                    <div class="zap-qr-container">
                        ${invoice.qrSvg}
                    </div>

                    <div class="zap-form-group">
                        <label class="zap-form-label" style="font-size: 0.85rem; color: #666;">BOLT11 Invoice:</label>
                        <div class="zap-invoice-input-group">
                            <input
                                type="text"
                                class="zap-invoice-input"
                                value="${this.escapeHtml(invoice.bolt11)}"
                                readonly
                                onclick="this.select()"
                            />
                            <button class="zap-copy-btn" onclick="ZapHandler.copyInvoice('${this.escapeHtml(invoice.bolt11)}', this)">Copy</button>
                        </div>
                    </div>

                    <a href="lightning:${invoice.bolt11.toUpperCase()}" class="zap-wallet-btn">
                        ${this.getLightningIcon()} Open in Wallet
                    </a>
                `;
            }

            const navButtons = `
                <div class="zap-split-nav">
                    ${!isFirst ? `<button class="zap-nav-btn" onclick="ZapHandler.previousInvoice()">← Previous</button>` : ''}
                    ${!isLast ? `<button class="zap-nav-btn zap-nav-btn-primary" onclick="ZapHandler.nextInvoice()">Next Invoice →</button>` : ''}
                </div>
            `;

            content.innerHTML = `
                <div class="zap-modal-header">
                    <h3 class="zap-modal-title">Split Payment</h3>
                    <button class="zap-close-btn" onclick="ZapHandler.closeModal()">Close</button>
                </div>

                <div class="zap-split-info">
                    <strong>${this.getLightningIcon()} Invoice ${this.currentInvoiceIndex + 1} of ${totalInvoices}</strong>
                    <p style="margin: 0.5rem 0 0.25rem 0;">
                        Recipient: <strong>${invoice.recipientName || invoice.recipient.slice(0, 12) + '...'}</strong>
                    </p>
                    <p style="margin: 0;">
                        Amount: <strong>${invoice.amount} sats</strong> (${invoice.sharePercent}%)
                    </p>
                </div>

                ${invoiceContent}
                ${navButtons}
            `;
        },

        /**
         * Navigate to previous invoice
         */
        previousInvoice() {
            if (this.currentInvoiceIndex > 0) {
                this.currentInvoiceIndex--;
                this.showSplitInvoicePhase();
            }
        },

        /**
         * Navigate to next invoice
         */
        nextInvoice() {
            if (this.currentInvoiceIndex < this.currentInvoices.length - 1) {
                this.currentInvoiceIndex++;
                this.showSplitInvoicePhase();
            }
        },

        /**
         * Show error phase
         */
        showErrorPhase(error) {
            const content = this.currentModal.querySelector('.zap-modal-content');
            content.innerHTML = `
                <div class="zap-modal-header">
                    <h3 class="zap-modal-title">⚠️ Error</h3>
                    <button class="zap-close-btn" onclick="ZapHandler.closeModal()">Close</button>
                </div>

                <div class="zap-status zap-status-error">
                    <strong>Failed to create invoice</strong><br>
                    <p style="margin-top: 0.5rem; font-size: 0.9rem;">${this.escapeHtml(error)}</p>
                </div>

                <div style="display: flex; gap: 0.5rem;">
                    <button class="zap-button" style="flex: 1; background: #6b7280;" onclick="ZapHandler.goBackToForm()">
                        ← Back
                    </button>
                    <button class="zap-button" style="flex: 1; background: #6b7280;" onclick="ZapHandler.closeModal()">
                        Cancel
                    </button>
                </div>
            `;
        },

        /**
         * Go back to input form from error screen
         */
        goBackToForm() {
            const { pubkey, lud16, lud06, zapSplits } = this.currentZapData;
            const recipient = this.currentModal.querySelector('.zap-modal-subtitle')?.textContent?.replace('to ', '') || 'this author';

            // Close current modal
            this.currentModal.remove();
            this.currentModal = null;

            // Reopen with same data
            this.showInputPhase(pubkey, lud16, lud06, zapSplits, recipient);
        },

        /**
         * Copy invoice to clipboard
         */
        copyInvoice(bolt11, button) {
            navigator.clipboard.writeText(bolt11).then(() => {
                const originalText = button.textContent;
                button.textContent = 'Copied!';
                setTimeout(() => {
                    button.textContent = originalText;
                }, 2000);
            }).catch(err => {
                console.error('Failed to copy:', err);
                alert('Failed to copy to clipboard');
            });
        },

        /**
         * Close modal
         */
        closeModal() {
            if (this.currentModal) {
                this.currentModal.remove();
                this.currentModal = null;
                this.currentInvoices = [];
                this.currentInvoiceIndex = 0;
            }
        },

        /**
         * Escape HTML to prevent XSS
         */
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

    // Expose globally
    window.ZapHandler = ZapHandler;

    // Initialize on load
    ZapHandler.init();
})();

