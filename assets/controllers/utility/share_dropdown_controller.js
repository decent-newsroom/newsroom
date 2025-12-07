// assets/controllers/share_dropdown_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['menu', 'button'];

    connect() {
        document.addEventListener('click', this.closeMenu);
    }

    disconnect() {
        document.removeEventListener('click', this.closeMenu);
    }

    toggle(event) {
        event.stopPropagation();
        this.menuTarget.style.display = this.menuTarget.style.display === 'block' ? 'none' : 'block';
    }

    closeMenu = () => {
        this.menuTarget.style.display = 'none';
    }

    copy(event) {
        const el = event.currentTarget;
        const text = el.dataset.copy;
        navigator.clipboard.writeText(text).then(() => {
            const orig = el.innerHTML;
            el.innerHTML = 'Copied!';
            setTimeout(() => { el.innerHTML = orig; }, 1200);
        });
    }
}
