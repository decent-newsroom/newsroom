import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static targets = ["hidden", "code"];

  connect() {
    this.updateMarkdown();
    this.hiddenTarget.addEventListener("input", this.updateMarkdown.bind(this));
    // Observe programmatic changes to the value attribute
    this.observer = new MutationObserver(() => this.updateMarkdown());
    this.observer.observe(this.hiddenTarget, { attributes: true, attributeFilter: ["value"] });
  }

  disconnect() {
    this.hiddenTarget.removeEventListener("input", this.updateMarkdown.bind(this));
    if (this.observer) this.observer.disconnect();
  }

  updateMarkdown() {
    this.codeTarget.textContent = this.hiddenTarget.value;
  }
}

