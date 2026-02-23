import { Controller } from '@hotwired/stimulus';

/**
 * Article search controller for the magazine wizard articles step.
 *
 * Provides a search input that queries the article search API
 * and displays results with a "Copy coordinate" button so the user
 * can paste it into the appropriate category field.
 */
export default class extends Controller {
    static targets = ['input', 'results'];

    static values = {
        url: { type: String, default: '/api/articles/search' },
        mineUrl: { type: String, default: '/api/articles/mine' },
        debounce: { type: Number, default: 400 },
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
            this.resultsTarget.classList.add('hidden');
            return;
        }

        try {
            const url = `${this.urlValue}?q=${encodeURIComponent(query)}&limit=10`;
            const response = await fetch(url);
            if (!response.ok) return;
            const data = await response.json();
            this.renderResults(data.results || []);
        } catch (e) {
            // fail silently
        }
    }

    renderResults(results) {
        if (results.length === 0) {
            this.resultsTarget.innerHTML = '<p class="small text-muted p-3">No articles found.</p>';
            this.resultsTarget.classList.remove('hidden');
            return;
        }

        const html = results.map(art => `
            <li class="article-search-result">
                <div class="article-search-result__info">
                    <strong class="article-search-result__title">${this.escapeHtml(art.title)}</strong>
                    ${art.summary ? `<p class="article-search-result__summary">${this.escapeHtml(art.summary)}</p>` : ''}
                    <code class="article-search-result__coordinate">${this.escapeHtml(art.coordinate)}</code>
                </div>
                <button type="button"
                    class="btn btn-sm btn-secondary"
                    data-action="click->ui--article-search#copy"
                    data-coordinate="${this.escapeAttr(art.coordinate)}"
                >Copy</button>
            </li>`
        ).join('');

        this.resultsTarget.innerHTML = `<ul class="article-search-results list-unstyled">${html}</ul>`;
        this.resultsTarget.classList.remove('hidden');
    }

    async myArticles() {
        this.inputTarget.value = '';
        try {
            const response = await fetch(this.mineUrlValue);
            if (!response.ok) return;
            const data = await response.json();
            this.renderResults(data.results || []);
        } catch (e) {
            // fail silently
        }
    }

    copy(event) {
        const btn = event.currentTarget;
        const coordinate = btn.dataset.coordinate;
        if (!coordinate) return;

        navigator.clipboard.writeText(coordinate).then(() => {
            btn.textContent = '✓ Copied';
            btn.disabled = true;
            setTimeout(() => {
                btn.textContent = 'Copy';
                btn.disabled = false;
            }, 1500);
        });
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    escapeAttr(text) {
        return text
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }
}
