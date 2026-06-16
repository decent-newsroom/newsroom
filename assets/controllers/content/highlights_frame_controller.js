import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static values = {
    items: Array,
  };

  connect() {
    this.element.dispatchEvent(new CustomEvent('article:highlights-loaded', {
      bubbles: true,
      detail: {
        highlights: this.itemsValue || [],
      },
    }));
  }
}

