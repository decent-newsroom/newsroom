import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  connect() {
    // For debug
    // console.log("Header controller connected");
  }

  saveDraft(event) {
    event.preventDefault();
    const btn = document.querySelector('[data-editor--layout-target="saveDraftSubmit"]');
    if (btn) btn.click();
  }

  publish(event) {
    event.preventDefault();
    const btn = document.querySelector('[data-editor--layout-target="publishSubmit"]');
    if (btn) btn.click();
  }
}
