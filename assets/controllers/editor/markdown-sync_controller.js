import { Controller } from "@hotwired/stimulus";
import { EditorView, basicSetup } from "codemirror";
import { markdown } from "@codemirror/lang-markdown";

export default class extends Controller {
  connect() {
    this.textarea = this.element.querySelector(".editor-md-field");
    this.codePreview = this.element.querySelector("pre");
    // Only initialize CodeMirror if not already done
    if (!this.textarea._codemirror) {
      this.textarea.style.display = "none";
      this.cmParent = document.createElement("div");
      this.textarea.parentNode.insertBefore(this.cmParent, this.textarea);
      this.cmView = new EditorView({
        doc: this.textarea.value,
        extensions: [
          basicSetup,
          markdown(),
          EditorView.lineWrapping,
          EditorView.updateListener.of((update) => {
            if (update.docChanged) {
              this.textarea.value = update.state.doc.toString();
              this.updateMarkdown();
              this.element.dispatchEvent(new CustomEvent('content:changed', { bubbles: true }));
            }
          })
        ],
        parent: this.cmParent
      });
      this.textarea._codemirror = this.cmView;
    } else {
      this.cmView = this.textarea._codemirror;
    }
    this.updateMarkdown();
    // Observe programmatic changes to the value attribute
    this.observer = new MutationObserver(() => this.updateMarkdown());
    this.observer.observe(this.textarea, { attributes: true, attributeFilter: ["value"] });

    // --- Call layoutController.onMarkdownChange for state sync ---
    this.textarea.addEventListener('input', () => {
      const layoutController = this.application.getControllerForElementAndIdentifier(
        this.element.closest('[data-controller~="editor--layout"]'),
        'editor--layout'
      );
      if (layoutController && typeof layoutController.onMarkdownChange === 'function') {
        layoutController.onMarkdownChange(this.textarea.value);
      }
    });
  }

  disconnect() {
    if (this.observer) this.observer.disconnect();
    if (this.cmView) this.cmView.destroy();
    if (this.cmParent && this.cmParent.parentNode) {
      this.cmParent.parentNode.removeChild(this.cmParent);
    }
    this.textarea.style.display = "";
    this.textarea._codemirror = null;
  }

  async updateMarkdown() {
    if (this.codePreview) {
      this.codePreview.textContent = this.textarea.value;
    }
  }
}
