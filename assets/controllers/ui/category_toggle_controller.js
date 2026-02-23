import { Controller } from '@hotwired/stimulus';

/**
 * Toggles visibility of new-category fields based on the existing list dropdown.
 * When an existing list is selected, the title/summary/image/tags fields are hidden.
 * When "Create new" is selected (empty value), they are shown.
 */
export default class extends Controller {
    static targets = ['newFields'];

    connect() {
        // Find the select element within this controller's scope
        const select = this.element.querySelector('.category-source-select');
        if (select) {
            this.selectEl = select;
            this.toggle();
        }
    }

    toggle() {
        if (!this.selectEl || !this.hasNewFieldsTarget) return;

        const isExisting = this.selectEl.value !== '';
        this.newFieldsTarget.style.display = isExisting ? 'none' : '';
    }
}

