import { Controller } from '@hotwired/stimulus';

/**
 * Simple analytics controller to record page visits
 */
export default class extends Controller {
    static values = {
        path: String
    }

    connect() {
        // Record the visit when the controller connects
        this.recordVisit();
    }

    recordVisit() {
        // Get the current route path
        const path = this.pathValue || window.location.pathname;

        // Send visit data to API
        fetch('/api/visit', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                route: path
            })
        })
        .catch(error => {
            console.error('Error recording visit:', error);
        });
    }
}
