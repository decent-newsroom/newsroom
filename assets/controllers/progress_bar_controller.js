// assets/controllers/progress_bar_controller.js
import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static targets = ["bar"];

  connect() {
    document.addEventListener("click", this.handleInteraction);
    document.addEventListener("touchstart", this.handleTouchStart);
    document.addEventListener("touchend", this.handleTouchEnd);
  }

  disconnect() {
    document.removeEventListener("click", this.handleInteraction);
    document.removeEventListener("touchstart", this.handleTouchStart);
    document.removeEventListener("touchend", this.handleTouchEnd);
  }

  handleTouchStart = (event) => {
    const touch = event.changedTouches[0];
    this.touchStartX = touch.screenX;
    this.touchStartY = touch.screenY;
  };

  handleTouchEnd = (event) => {
    const touch = event.changedTouches[0];
    const dx = Math.abs(touch.screenX - this.touchStartX);
    const dy = Math.abs(touch.screenY - this.touchStartY);
    if (dx < 10 && dy < 10) {
      this.handleInteraction(event);
    }
  };

  handleInteraction = (event) => {
    const link = event.target.closest("a");
    if (link && !link.hasAttribute("data-no-progress") &&
        !event.ctrlKey && !event.metaKey && !event.shiftKey) {
      this.start();
    }
  };

  start() {
    this.barTarget.style.width = "0";
    this.barTarget.style.transition = "none";
    setTimeout(() => {
      this.barTarget.style.transition = "width 5s ease-in-out";
      this.barTarget.style.width = "100%";
    }, 10);
  }
}
