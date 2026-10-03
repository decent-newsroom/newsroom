import { Application } from '@hotwired/stimulus';
import SignerModalController from './controllers/utility/signer_modal_controller.js';
import { syncServerSessionIfPending } from './controllers/nostr/signer_manager.js';
import './unfold-reader-interactions.js';

const application = Application.start();
application.register('utility--signer-modal', SignerModalController);
syncServerSessionIfPending();

function enhanceSignerDialogs() {
    document.querySelectorAll('.unfold-reader-signer').forEach((wrapper) => {
        const dialog = wrapper.querySelector('[data-utility--signer-modal-target="dialog"]');
        if (!dialog || dialog.dataset.readerDialogReady) return;
        dialog.dataset.readerDialogReady = '1';
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-label', wrapper.querySelector('button')?.textContent.trim() || '');
        let previousFocus = null;
        const focusable = () => [...dialog.querySelectorAll('button, input, a[href], [tabindex="0"]')]
            .filter((element) => !element.disabled && element.getClientRects().length > 0);
        const observer = new MutationObserver(() => {
            if (dialog.style.display !== 'none') {
                previousFocus = document.activeElement;
                focusable()[0]?.focus();
            } else {
                previousFocus?.focus();
            }
        });
        observer.observe(dialog, { attributes: true, attributeFilter: ['style'] });
        dialog.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                application.getControllerForElementAndIdentifier(wrapper, 'utility--signer-modal')?.closeDialog();
            }
            if (event.key === 'Tab') {
                const elements = focusable();
                const index = elements.indexOf(document.activeElement);
                if (event.shiftKey && index <= 0) {
                    event.preventDefault();
                    elements.at(-1)?.focus();
                } else if (!event.shiftKey && index === elements.length - 1) {
                    event.preventDefault();
                    elements[0]?.focus();
                }
            }
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', enhanceSignerDialogs, { once: true });
} else {
    enhanceSignerDialogs();
}
