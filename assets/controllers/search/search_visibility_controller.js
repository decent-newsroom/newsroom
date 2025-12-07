import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['list'];

  toggle(event) {
    const active = !!event.detail?.active;
    if (this.hasListTarget) {
      this.listTarget.hidden = active;
    }
  }
}
