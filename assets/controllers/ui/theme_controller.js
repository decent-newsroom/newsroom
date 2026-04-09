import { Controller } from '@hotwired/stimulus';

/**
 * Theme Controller
 *
 * Cycles through available themes (dark → light → space) and persists
 * the choice to localStorage. Also syncs the preference to the server
 * session so SSR pages honour the user's choice on hard navigation.
 *
 * Usage:
 *   <div data-controller="ui--theme"
 *        data-ui--theme-sync-url-value="/theme/switch">
 *       <button data-action="click->ui--theme#cycle"
 *               data-ui--theme-target="label">Dark</button>
 *   </div>
 */
const THEMES = ['dark', 'light', 'space'];

export default class extends Controller {
    static targets = ['label', 'option'];
    static values  = { syncUrl: String };

    connect() {
        this.currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';
        this.updateUI();

        // Listen for OS-level dark/light changes and auto-switch
        // when the user has no explicit preference saved.
        this._mediaQuery = window.matchMedia('(prefers-color-scheme: light)');
        this._onOsChange = (e) => {
            if (!localStorage.getItem('theme')) {
                this.currentTheme = e.matches ? 'light' : 'dark';
                document.documentElement.setAttribute('data-theme', this.currentTheme);
                this.updateUI();
            }
        };
        this._mediaQuery.addEventListener('change', this._onOsChange);
    }

    disconnect() {
        if (this._mediaQuery && this._onOsChange) {
            this._mediaQuery.removeEventListener('change', this._onOsChange);
        }
    }

    /**
     * Cycle to the next theme in the list.
     */
    cycle() {
        const idx = THEMES.indexOf(this.currentTheme);
        this.currentTheme = THEMES[(idx + 1) % THEMES.length];
        this.apply();
    }

    /**
     * Set a specific theme (use with data-ui--theme-param="theme").
     */
    pick({ params: { theme } }) {
        if (THEMES.includes(theme)) {
            this.currentTheme = theme;
            this.apply();
        }
    }

    // ── private ──────────────────────────────────────────────────

    apply() {
        document.documentElement.setAttribute('data-theme', this.currentTheme);
        localStorage.setItem('theme', this.currentTheme);
        this.updateUI();
        this.syncToServer();
    }

    updateUI() {
        if (this.hasLabelTarget) {
            this.labelTarget.textContent = this.currentTheme.charAt(0).toUpperCase() + this.currentTheme.slice(1);
        }
        // Highlight active option button (settings page)
        if (this.hasOptionTarget) {
            this.optionTargets.forEach(btn => {
                const btnTheme = btn.dataset.uiThemeThemeParam;
                btn.classList.toggle('active', btnTheme === this.currentTheme);
            });
        }
    }

    syncToServer() {
        const url = this.syncUrlValue;
        if (!url) return;

        const syncUrl = url.replace('__THEME__', encodeURIComponent(this.currentTheme));
        fetch(syncUrl, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        }).catch(() => { /* best-effort */ });
    }
}


