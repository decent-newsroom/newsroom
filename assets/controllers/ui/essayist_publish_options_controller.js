import { Controller } from '@hotwired/stimulus';

/**
 * Wires the Essayist publish-options panel in the editor:
 *
 *  - When "Publish ONLY to Essayist" is checked, the warning callout is
 *    revealed and the "Also publish to Essayist" checkbox is hidden+unchecked
 *    (it is implied by the ONLY mode and showing both would be redundant).
 *  - When "Publish ONLY to Essayist" is unchecked, the warning hides and the
 *    "Also publish" checkbox becomes available again.
 *
 * The actual publish-target decisions happen in `nostr--nostr-publish` and on
 * the backend (`EditorController::publishNostrEvent`) which both read these
 * two checkbox values via the form. This controller is purely UX.
 */
export default class extends Controller {
    static targets = ['onlyInput', 'alsoInput', 'onlyWarning'];

    connect() {
        this.syncOnlyState();
    }

    onOnlyChanged() {
        this.syncOnlyState();
    }

    syncOnlyState() {
        if (!this.hasOnlyInputTarget) {
            return;
        }
        const only = this.onlyInputTarget.checked;

        if (this.hasOnlyWarningTarget) {
            this.onlyWarningTarget.hidden = !only;
        }

        if (this.hasAlsoInputTarget) {
            // When ONLY is selected, force "also" off and disable it.
            if (only) {
                this.alsoInputTarget.checked = false;
                this.alsoInputTarget.disabled = true;
            } else {
                this.alsoInputTarget.disabled = false;
            }
        }
    }
}

