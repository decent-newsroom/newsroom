import { Controller } from '@hotwired/stimulus';
import { encodeNaddr } from '../../typescript/nostr-utils.ts';

/**
 * Embeds panel controller for the article editor.
 *
 * Provides:
 *  - Article search → inserts nostr:naddr1... (article embed card)
 *  - Raw paste for note1/nevent1/naddr1/npub1/nprofile1 codes
 *
 * Per NIP-27, the corresponding 'p', 'e' and 'a' tags are auto-generated
 * at publish time by extractNostrTags().
 */
export default class extends Controller {
    static targets = [
        'articleInput', 'articleResults',
        'rawInput',
    ];

    static values = {
        articleUrl: { type: String, default: '/api/articles/search' },
        debounce: { type: Number, default: 350 },
    };

    connect() {
        this._articleTimer = null;
    }

    disconnect() {
        if (this._articleTimer) clearTimeout(this._articleTimer);
    }


    // ── Article embed search ───────────────────────────

    onArticleInput() {
        if (this._articleTimer) clearTimeout(this._articleTimer);
        this._articleTimer = setTimeout(() => this.searchArticles(), this.debounceValue);
    }

    async searchArticles() {
        const query = this.articleInputTarget.value.trim();
        if (query.length < 2) {
            this.articleResultsTarget.innerHTML = '';
            return;
        }

        try {
            const url = `${this.articleUrlValue}?q=${encodeURIComponent(query)}&limit=6`;
            const response = await fetch(url);
            if (!response.ok) return;
            const data = await response.json();
            this.renderArticleResults(data.results || []);
        } catch (e) {
            console.error('[embeds] Article search error:', e);
        }
    }

    renderArticleResults(results) {
        if (results.length === 0) {
            this.articleResultsTarget.innerHTML = '<p class="embed-empty">No articles found.</p>';
            return;
        }

        const html = results.map(art => {
            const title = this.esc(art.title);
            const summary = art.summary ? `<span class="embed-summary">${this.esc(art.summary.substring(0, 80))}</span>` : '';
            const date = art.createdAt ? `<span class="embed-date">${this.esc(art.createdAt)}</span>` : '';

            return `
                <button type="button"
                    class="embed-result"
                    data-action="click->editor--embeds#insertArticle"
                    data-kind="${art.kind || 30023}"
                    data-pubkey="${this.esc(art.pubkey)}"
                    data-slug="${this.esc(art.slug)}"
                    data-title="${this.esc(art.title)}"
                >
                    <span class="embed-info">
                        <span class="embed-name">${title}</span>
                        ${summary}
                        ${date}
                    </span>
                </button>`;
        }).join('');

        this.articleResultsTarget.innerHTML = `<div class="embed-results-list">${html}</div>`;
    }

    insertArticle(event) {
        const btn = event.currentTarget;
        const kind = parseInt(btn.dataset.kind, 10) || 30023;
        const pubkey = btn.dataset.pubkey;
        const slug = btn.dataset.slug;
        if (!pubkey || !slug) return;

        try {
            const naddr = encodeNaddr(kind, pubkey, slug);
            const insertText = `\nnostr:${naddr}\n`;
            this._insertIntoEditor(insertText);

            this.articleInputTarget.value = '';
            this.articleResultsTarget.innerHTML = '<p class="embed-empty">Article embed inserted ✓</p>';
        } catch (e) {
            console.error('[embeds] Failed to encode naddr:', e);
            const insertText = `\nnostr:naddr (${kind}:${pubkey}:${slug})\n`;
            this._insertIntoEditor(insertText);
        }
    }

    // ── Raw paste ──────────────────────────────────────

    insertRaw() {
        if (!this.hasRawInputTarget) return;

        let raw = this.rawInputTarget.value.trim();
        if (!raw) return;

        // Strip nostr: prefix if user included it
        if (raw.startsWith('nostr:')) {
            raw = raw.substring(6);
        }

        // Validate that it starts with a known NIP-19 prefix
        const validPrefixes = ['npub1', 'nprofile1', 'note1', 'nevent1', 'naddr1'];
        const hasValidPrefix = validPrefixes.some(p => raw.startsWith(p));

        if (!hasValidPrefix) {
            this.rawInputTarget.classList.add('is-invalid');
            setTimeout(() => this.rawInputTarget.classList.remove('is-invalid'), 2000);
            return;
        }

        const insertText = `\nnostr:${raw}\n`;
        this._insertIntoEditor(insertText);

        this.rawInputTarget.value = '';
    }

    // ── Shared insert helper (mode-aware) ──────────────

    _insertIntoEditor(text) {
        const mdPane = document.querySelector('.editor-pane--markdown');
        const isMdMode = mdPane && !mdPane.classList.contains('is-hidden');

        if (isMdMode) {
            const textarea = document.querySelector('textarea[name="editor[content]"]');
            const cm = textarea && textarea._codemirror;
            if (cm) {
                const cursor = cm.state.selection.main.head;
                cm.dispatch({ changes: { from: cursor, insert: text } });
            } else if (textarea) {
                const start = textarea.selectionStart || textarea.value.length;
                textarea.value = textarea.value.slice(0, start) + text + textarea.value.slice(start);
                textarea.selectionStart = textarea.selectionEnd = start + text.length;
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
            }
        } else if (window.appQuill) {
            const quill = window.appQuill;
            const range = quill.getSelection(true);
            const index = range ? range.index : quill.getLength() - 1;
            quill.insertText(index, text, 'user');
            quill.setSelection(index + text.length, 0, 'user');
        }
    }

    esc(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }
}


