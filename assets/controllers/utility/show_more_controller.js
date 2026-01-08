import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["preview", "full", "button"];

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

