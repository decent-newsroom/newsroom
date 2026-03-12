import { Controller } from '@hotwired/stimulus';

/**
 * Mentions panel controller for the article editor.
 *
 * Provides user search via /api/users/search and inserts
 * nostr:npub1... mentions into the Quill editor at cursor position.
 * Per NIP-27, 'p' tags are auto-added at publish time by the
 * extractNostrTags() utility.
 */
export default class extends Controller {
    static targets = ['input', 'results'];

    static values = {
        url: { type: String, default: '/api/users/search' },
        debounce: { type: Number, default: 350 },
    };

    connect() {
        this._timer = null;
    }

    disconnect() {
        if (this._timer) clearTimeout(this._timer);
    }

    onInput() {
        if (this._timer) clearTimeout(this._timer);
        this._timer = setTimeout(() => this.search(), this.debounceValue);
    }

    async search() {
        const query = this.inputTarget.value.trim();
        if (query.length < 2) {
            this.resultsTarget.innerHTML = '';
            return;
        }

        try {
            const url = `${this.urlValue}?q=${encodeURIComponent(query)}&limit=8`;
            const response = await fetch(url);
            if (!response.ok) return;
            const data = await response.json();
            this.renderResults(data.users || []);
        } catch (e) {
            console.error('[mentions] Search error:', e);
        }
    }

    renderResults(users) {
        if (users.length === 0) {
            this.resultsTarget.innerHTML = '<p class="mention-empty">No users found.</p>';
            return;
        }

        const html = users.map(user => {
            const name = this.esc(user.displayName || user.name || 'Anonymous');
            const nip05 = user.nip05 ? `<span class="mention-nip05">${this.esc(user.nip05)}</span>` : '';
            const pic = user.picture
                ? `<img src="${this.esc(user.picture)}" alt="" class="mention-avatar" loading="lazy" />`
                : '<span class="mention-avatar mention-avatar--placeholder">?</span>';

            return `
                <button type="button"
                    class="mention-result"
                    data-action="click->editor--mentions#insert"
                    data-npub="${this.esc(user.npub)}"
                    data-name="${this.esc(name)}"
                >
                    ${pic}
                    <span class="mention-info">
                        <span class="mention-name">${name}</span>
                        ${nip05}
                    </span>
                </button>`;
        }).join('');

        this.resultsTarget.innerHTML = `<div class="mention-results-list">${html}</div>`;
    }

    insert(event) {
        const btn = event.currentTarget;
        const npub = btn.dataset.npub;
        const name = btn.dataset.name || 'unknown';
        if (!npub) return;

        const raw = `nostr:${npub}`;

        // Detect which editor mode is active by checking pane visibility
        const mdPane = document.querySelector('.editor-pane--markdown');
        const isMdMode = mdPane && !mdPane.classList.contains('is-hidden');

        if (isMdMode) {
            // Markdown / CodeMirror mode — insert raw nostr:npub1... at cursor
            const textarea = document.querySelector('textarea[name="editor[content]"]');
            const cm = textarea && textarea._codemirror;
            if (cm) {
                const cursor = cm.state.selection.main.head;
                cm.dispatch({ changes: { from: cursor, insert: raw + ' ' } });
            } else if (textarea) {
                // Plain textarea fallback
                const start = textarea.selectionStart || textarea.value.length;
                textarea.value = textarea.value.slice(0, start) + raw + ' ' + textarea.value.slice(start);
                textarea.selectionStart = textarea.selectionEnd = start + raw.length + 1;
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
            }
        } else if (window.appQuill) {
            // Quill / Rich text mode — insert mention embed
            const quill = window.appQuill;
            const range = quill.getSelection(true);
            const index = range ? range.index : quill.getLength() - 1;
            quill.insertEmbed(index, 'nostrMention', { npub, name }, 'user');
            quill.insertText(index + 1, ' ', 'user');
            quill.setSelection(index + 2, 0, 'user');
        }

        // Clear search
        this.inputTarget.value = '';
        this.resultsTarget.innerHTML = '<p class="mention-empty">Mention inserted ✓</p>';
    }

    esc(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }
}

