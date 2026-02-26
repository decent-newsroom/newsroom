import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["button"];

    connect() {
        this.scrollHandler = this.onScroll.bind(this);
        window.addEventListener("scroll", this.scrollHandler, { passive: true });
        this.onScroll();
    }

    disconnect() {
        window.removeEventListener("scroll", this.scrollHandler);
    }

    onScroll() {
        if (window.scrollY > 400) {
            this.buttonTarget.classList.add("visible");
        } else {
            this.buttonTarget.classList.remove("visible");
        }
    }

    scrollToTop() {
        window.scrollTo({ top: 0, behavior: "smooth" });
    }
}

