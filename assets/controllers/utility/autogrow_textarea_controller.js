import { Controller } from '@hotwired/stimulus';

/**
 * Auto-grow textarea controller
 * Makes textareas automatically resize to fit their content
 */
export default class extends Controller {
    connect() {
        // Set initial height
        this.resize();

        // Listen for input changes
        this.element.addEventListener('input', () => this.resize());

        // Also resize on focus (in case content was changed programmatically)
        this.element.addEventListener('focus', () => this.resize());
    }

    resize() {
        // Reset height to auto to get the correct scrollHeight
        this.element.style.height = 'auto';

        // Set height to scrollHeight to fit all content
        this.element.style.height = this.element.scrollHeight + 'px';
    }
}

