import { Controller } from '@hotwired/stimulus';

/**
 * Chat groups controller — optional unread badge updates via Mercure.
 *
 * data-controller="chat--groups"
 */
export default class extends Controller {
    static values = {
        communityId: String,
    };

    connect() {
        // Future: subscribe to /chat/{communityId}/unread for live unread badges
    }

    disconnect() {
        // Clean up any EventSource
    }
}

