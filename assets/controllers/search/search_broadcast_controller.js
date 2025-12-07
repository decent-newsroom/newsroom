import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  onInput(e) {
    const value = e.target.value || '';
    const active = value.trim().length > 0;
    window.dispatchEvent(new CustomEvent('search:changed', { detail: { active } }));
  }
}
