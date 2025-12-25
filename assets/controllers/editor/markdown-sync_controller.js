import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static targets = ["hidden", "code"];

  connect() {
    this.updateMarkdown();
    this.hiddenTarget.addEventListener("input", this.updateMarkdown.bind(this));
    // Also trigger a custom event for layout controller
    this.hiddenTarget.addEventListener("input", () => {
      this.element.dispatchEvent(new CustomEvent('content:changed', { bubbles: true }));
    });
    // Observe programmatic changes to the value attribute
    this.observer = new MutationObserver(() => this.updateMarkdown());
    this.observer.observe(this.hiddenTarget, { attributes: true, attributeFilter: ["value"] });
  }

  disconnect() {
    this.hiddenTarget.removeEventListener("input", this.updateMarkdown.bind(this));
    if (this.observer) this.observer.disconnect();
  }

  async updateMarkdown() {
    this.codeTarget.textContent = this.hiddenTarget.value;
    // Sync Markdown to Quill (content_html)
    if (window.appQuill) {
      let html = '';
      if (window.marked) {
        html = window.marked.parse(this.hiddenTarget.value || '');
      } else {
        // Fallback: use backend endpoint
        try {
          const resp = await fetch('/editor/markdown/preview', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ markdown: this.hiddenTarget.value || '' })
          });
          if (resp.ok) {
            const data = await resp.json();
            html = data.html || '';
          }
        } catch (e) { html = ''; }
      }
      // Set Quill content from HTML (replace contents)
      window.appQuill.root.innerHTML = html;
    }
  }
}
