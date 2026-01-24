import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["preview", "full", "button"];

    connect() {
        // Check if content is short enough to not need the button
        // The preview has max-height of 280px, so if scrollHeight <= 280px, no need for toggle
        if (this.hasPreviewTarget && this.previewTarget.scrollHeight <= 280) {
            this.previewTarget.classList.add('no-fade');
            if (this.hasButtonTarget) {
                this.buttonTarget.classList.add('hidden');
            }
        }
    }

    toggle(event) {
        event?.preventDefault?.();
        if (this.fullTarget.style.display === "none") {
            this.fullTarget.style.display = "";
            this.previewTarget.style.display = "none";
            this.buttonTarget.textContent = "Show less";
        } else {
            this.fullTarget.style.display = "none";
            this.previewTarget.style.display = "";
            this.buttonTarget.textContent = "Show more";
        }
    }
}
