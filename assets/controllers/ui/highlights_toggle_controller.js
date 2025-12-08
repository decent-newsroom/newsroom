import { Controller } from '@hotwired/stimulus';
export default class extends Controller {
    static targets = ['button'];
    connect() {
        this.articleMain = this.element.querySelector('.article-main');
        if (!this.articleMain) return;
        const highlightsData = this.articleMain.getAttribute('data-highlights');
        if (highlightsData) {
            try {
                this.highlights = JSON.parse(highlightsData);
                this.applyHighlights();
            } catch (e) {
                console.error('Failed to parse highlights data:', e);
            }
        }
        // Check if user has a saved preference
        const enabled = localStorage.getItem('highlights-enabled') === 'true';
        this.setHighlightsVisibility(enabled);
    }
    applyHighlights() {
        if (!this.highlights || this.highlights.length === 0) return;
        // Get all text nodes in the article
        const walker = document.createTreeWalker(
            this.articleMain,
            NodeFilter.SHOW_TEXT,
            null,
            false
        );
        const textNodes = [];
        let node;
        while (node = walker.nextNode()) {
            // Skip if parent is already a highlight mark
            if (node.parentElement.classList.contains('article-highlight')) {
                continue;
            }
            textNodes.push(node);
        }
        // For each highlight, find and wrap matching text
        this.highlights.forEach((highlight, index) => {
            const searchText = highlight.content.trim();
            if (!searchText) return;
            textNodes.forEach(textNode => {
                const text = textNode.textContent;
                const startIndex = text.indexOf(searchText);
                if (startIndex !== -1) {
                    // Split the text node and wrap the match
                    const range = document.createRange();
                    range.setStart(textNode, startIndex);
                    range.setEnd(textNode, startIndex + searchText.length);
                    const mark = document.createElement('mark');
                    mark.className = 'article-highlight';
                    mark.setAttribute('data-ui--highlights-toggle-target', 'highlight');
                    mark.setAttribute('title', 'Highlighted by others (' + new Date(highlight.created_at * 1000).toLocaleDateString() + ')');
                    try {
                        range.surroundContents(mark);
                    } catch (e) {
                        // If surroundContents fails (e.g., spans multiple nodes), try extraction
                        try {
                            const extracted = range.extractContents();
                            mark.appendChild(extracted);
                            range.insertNode(mark);
                        } catch (e2) {
                            console.warn('Failed to highlight text:', searchText, e2);
                        }
                    }
                }
            });
        });
    }
    toggle() {
        if (!this.hasButtonTarget) return;
        const currentlyEnabled = this.buttonTarget.getAttribute('aria-pressed') === 'true';
        const newState = !currentlyEnabled;
        this.setHighlightsVisibility(newState);
        // Save preference
        localStorage.setItem('highlights-enabled', newState.toString());
    }
    setHighlightsVisibility(enabled) {
        if (!this.hasButtonTarget) return;
        this.buttonTarget.setAttribute('aria-pressed', enabled.toString());
        const highlightElements = this.element.querySelectorAll('.article-highlight');
        if (enabled) {
            this.buttonTarget.classList.add('active');
            highlightElements.forEach(el => el.classList.add('visible'));
        } else {
            this.buttonTarget.classList.remove('active');
            highlightElements.forEach(el => el.classList.remove('visible'));
        }
    }
}
