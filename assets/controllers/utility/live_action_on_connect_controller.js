import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        // Trigger the attached live action once after this element appears.
        setTimeout(() => {
            if (this.element?.isConnected) {
                this.element.click();
            }
        }, 0);
    }
}

