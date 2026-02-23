import { Controller } from '@hotwired/stimulus';

/**
 * Wizard autosave controller — persists form data to localStorage
 * so progress survives reloads, session timeouts, and browser crashes.
 *
 * Usage: attach to a <form> or a wrapper containing the form.
 *   data-controller="ui--wizard-autosave"
 *   data-ui--wizard-autosave-key-value="mag_wizard"
 *
 * On connect, checks for a saved snapshot and shows a restore banner.
 * While the user works, debounced saves run on every input/change event.
 * On successful form submission the snapshot is cleared.
 */
export default class extends Controller {
    static values = {
        key: { type: String, default: 'mag_wizard_draft' },
        debounce: { type: Number, default: 1500 },
    };

    static targets = ['banner', 'form', 'status'];

    connect() {
        this._timer = null;
        this._form = this.hasFormTarget
            ? this.formTarget
            : this.element.querySelector('form') || this.element.closest('form');

        if (!this._form) return;

        // Listen for input changes to trigger autosave
        this._onInput = this.scheduleSave.bind(this);
        this._form.addEventListener('input', this._onInput);
        this._form.addEventListener('change', this._onInput);

        // Clear snapshot on successful submit
        this._onSubmit = () => {
            // Clear after a short delay so the form can submit first
            setTimeout(() => this.clearSnapshot(), 200);
        };
        this._form.addEventListener('submit', this._onSubmit);

        // Check for existing snapshot
        this.checkForSnapshot();
    }

    disconnect() {
        if (this._timer) clearTimeout(this._timer);
        if (this._form) {
            this._form.removeEventListener('input', this._onInput);
            this._form.removeEventListener('change', this._onInput);
            this._form.removeEventListener('submit', this._onSubmit);
        }
    }

    // ── Snapshot lifecycle ──────────────────────────────────────────

    scheduleSave() {
        if (this._timer) clearTimeout(this._timer);
        this._timer = setTimeout(() => this.save(), this.debounceValue);
    }

    save() {
        if (!this._form) return;

        const data = {};
        const formData = new FormData(this._form);
        for (const [key, value] of formData.entries()) {
            // Skip CSRF tokens and file inputs
            if (key.includes('_token') || value instanceof File) continue;

            // Handle multiple values (e.g. collection fields) as arrays
            if (data[key] !== undefined) {
                if (!Array.isArray(data[key])) {
                    data[key] = [data[key]];
                }
                data[key].push(value);
            } else {
                data[key] = value;
            }
        }

        const snapshot = {
            url: window.location.pathname,
            savedAt: new Date().toISOString(),
            fields: data,
        };

        try {
            localStorage.setItem(this.keyValue, JSON.stringify(snapshot));
            this.showStatus('Draft saved');
        } catch (e) {
            // localStorage full or unavailable — fail silently
        }
    }

    checkForSnapshot() {
        let snapshot;
        try {
            const raw = localStorage.getItem(this.keyValue);
            if (!raw) return;
            snapshot = JSON.parse(raw);
        } catch (e) {
            return;
        }

        if (!snapshot || !snapshot.fields) return;

        // Only offer restore if there's meaningful data
        const fieldCount = Object.keys(snapshot.fields).length;
        if (fieldCount === 0) return;

        // Show the restore banner
        if (this.hasBannerTarget) {
            const time = this.formatTime(snapshot.savedAt);
            this.bannerTarget.innerHTML =
                `<span>You have a draft from <strong>${time}</strong>.</span> ` +
                `<button type="button" class="btn btn-sm btn-primary" data-action="click->ui--wizard-autosave#restore">Restore</button> ` +
                `<button type="button" class="btn btn-sm btn-secondary" data-action="click->ui--wizard-autosave#dismiss">Dismiss</button>`;
            this.bannerTarget.classList.remove('hidden');
        }
    }

    restore() {
        let snapshot;
        try {
            snapshot = JSON.parse(localStorage.getItem(this.keyValue));
        } catch (e) {
            return;
        }

        if (!snapshot || !snapshot.fields || !this._form) return;

        const fields = snapshot.fields;
        for (const [name, value] of Object.entries(fields)) {
            const elements = this._form.querySelectorAll(`[name="${CSS.escape(name)}"]`);
            elements.forEach(el => {
                if (el.type === 'checkbox' || el.type === 'radio') {
                    el.checked = (el.value === value);
                } else if (el.tagName === 'SELECT') {
                    el.value = value;
                } else {
                    el.value = value;
                }
                // Trigger input event so other controllers (e.g. preview) update
                el.dispatchEvent(new Event('input', { bubbles: true }));
            });
        }

        this.hideBanner();
        this.showStatus('Draft restored');
    }

    dismiss() {
        this.clearSnapshot();
        this.hideBanner();
    }

    clearSnapshot() {
        try {
            localStorage.removeItem(this.keyValue);
        } catch (e) {
            // ignore
        }
    }

    /**
     * Clear all wizard autosave keys and follow the link.
     * Use on Cancel buttons: data-action="click->ui--wizard-autosave#clearAll"
     */
    clearAll(event) {
        const keys = ['mag_wizard_setup', 'mag_wizard_categories', 'mag_wizard_articles'];
        keys.forEach(k => {
            try { localStorage.removeItem(k); } catch (e) {}
        });
        // Let the default link navigation proceed
    }

    // ── UI helpers ─────────────────────────────────────────────────

    hideBanner() {
        if (this.hasBannerTarget) {
            this.bannerTarget.classList.add('hidden');
        }
    }

    showStatus(message) {
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = message;
            this.statusTarget.classList.add('is-visible');
            setTimeout(() => {
                this.statusTarget.classList.remove('is-visible');
            }, 2000);
        }
    }

    formatTime(isoString) {
        try {
            const d = new Date(isoString);
            return d.toLocaleString(undefined, {
                month: 'short', day: 'numeric',
                hour: '2-digit', minute: '2-digit',
            });
        } catch (e) {
            return 'recently';
        }
    }
}


