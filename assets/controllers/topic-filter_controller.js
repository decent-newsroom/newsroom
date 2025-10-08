import { Controller } from '@hotwired/stimulus';

/*
 * Topic Filter Controller
 * Handles topic filtering interactions for the media discovery page
 * Provides visual feedback and smooth transitions
 */
export default class extends Controller {
    connect() {
        console.log('Topic filter controller connected');
    }

    // Add smooth scroll to top when changing topics
    selectTopic(event) {
        // Let the link navigate normally, but add smooth scroll
        setTimeout(() => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }, 100);
    }
}

