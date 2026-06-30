import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'highlights-enabled';

export default class extends Controller {
    static targets = ['actionBar', 'button', 'count'];

    connect() {
        this.highlights = [];
        this.onHighlightsLoaded = this.onHighlightsLoaded.bind(this);
        this.element.addEventListener('article:highlights-loaded', this.onHighlightsLoaded);

        this.articleMain = this.element.querySelector('.article-main');
        this.loadHighlightsFromArticle();
        this.updateControlState();
        this.setHighlightsVisibility(this.isSavedEnabled());
    }

    disconnect() {
        this.element.removeEventListener('article:highlights-loaded', this.onHighlightsLoaded);
    }

    loadHighlightsFromArticle() {
        if (!this.articleMain) return;
        const highlightsData = this.articleMain.getAttribute('data-highlights');
        if (!highlightsData) return;

        try {
            const highlights = JSON.parse(highlightsData);
            this.highlights = Array.isArray(highlights) ? highlights : [];
            this.applyHighlights();
        } catch (e) {
            console.error('Failed to parse highlights data:', e);
        }
    }

    onHighlightsLoaded(event) {
        const highlights = event.detail?.highlights || [];
        this.highlights = Array.isArray(highlights) ? highlights : [];
        this.applyHighlights();
        this.updateControlState();
        this.setHighlightsVisibility(this.isSavedEnabled());
    }

    applyHighlights() {
        if (!this.articleMain) return;
        this.clearArticleHighlights();
        if (!this.highlights || this.highlights.length === 0) return;

        // Re-read text nodes after each wrap so repeated phrases do not reuse stale nodes.
        this.highlights.forEach((highlight) => {
            const searchText = highlight.content?.trim();
            if (!searchText) return;

            this.getArticleTextNodes().forEach(textNode => {
                const text = textNode.textContent;
                const startIndex = text.indexOf(searchText);
                if (startIndex === -1) {
                    return;
                }

                this.wrapTextNode(textNode, startIndex, searchText.length, highlight);
            });
        });
    }

    clearArticleHighlights() {
        this.articleMain.querySelectorAll('mark.article-highlight').forEach(mark => {
            mark.replaceWith(document.createTextNode(mark.textContent));
        });
        this.articleMain.normalize();
    }

    getArticleTextNodes() {
        const walker = document.createTreeWalker(
            this.articleMain,
            NodeFilter.SHOW_TEXT,
            null,
            false
        );
        const textNodes = [];
        let node;
        while (node = walker.nextNode()) {
            if (node.parentElement.closest('.article-highlight')) {
                continue;
            }
            textNodes.push(node);
        }

        return textNodes;
    }

    wrapTextNode(textNode, startIndex, length, highlight) {
        const range = document.createRange();
        range.setStart(textNode, startIndex);
        range.setEnd(textNode, startIndex + length);
        const mark = document.createElement('mark');
        mark.className = 'article-highlight';
        mark.setAttribute('title', 'Highlighted by others (' + new Date(highlight.created_at * 1000).toLocaleDateString() + ')');

        try {
            range.surroundContents(mark);
        } catch (e) {
            try {
                const extracted = range.extractContents();
                mark.appendChild(extracted);
                range.insertNode(mark);
            } catch (e2) {
                console.warn('Failed to highlight text:', textNode.textContent, e2);
            }
        }
    }

    toggle() {
        if (!this.hasButtonTarget || this.buttonTarget.disabled) return;
        const currentlyEnabled = this.buttonTarget.getAttribute('aria-pressed') === 'true';
        const newState = !currentlyEnabled;
        this.setHighlightsVisibility(newState);
        localStorage.setItem(STORAGE_KEY, newState.toString());
    }

    setHighlightsVisibility(enabled) {
        if (this.hasButtonTarget) {
            this.buttonTarget.setAttribute('aria-pressed', enabled.toString());
            this.buttonTarget.classList.toggle('active', enabled);
        }

        this.element.querySelectorAll('.article-highlight').forEach(el => {
            el.classList.toggle('visible', enabled);
        });
    }

    updateControlState() {
        const count = Array.isArray(this.highlights) ? this.highlights.length : 0;
        if (this.hasActionBarTarget && this.actionBarTarget.dataset.highlightsOnly === 'true') {
            this.actionBarTarget.hidden = count === 0;
        }

        if (this.hasButtonTarget) {
            this.buttonTarget.hidden = count === 0;
            this.buttonTarget.disabled = count === 0;
        }

        if (this.hasCountTarget) {
            this.countTarget.textContent = count > 0 ? `(${count})` : '';
            this.countTarget.hidden = count === 0;
        }
    }

    isSavedEnabled() {
        return localStorage.getItem(STORAGE_KEY) === 'true';
    }
}
