import { Controller } from '@hotwired/stimulus';

/**
 * Card Placeholder Controller
 * Handles fetching articles that haven't been fully loaded yet
 */
export default class extends Controller {
    static targets = ['button'];

    connect() {
        console.log('Card placeholder controller connected');
    }

    async fetch(event) {
        event.preventDefault();
        const button = event.currentTarget;

        // Get article identifiers from data attributes
        const articleId = button.dataset.articleId;
        const articlePubkey = button.dataset.articlePubkey;
        const articleSlug = button.dataset.articleSlug;
        const articleKind = button.dataset.articleKind;
        const articleCoordinate = button.dataset.articleCoordinate;

        // Disable button and show loading state
        button.disabled = true;
        const originalText = button.innerHTML;
        button.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="spinning">
                <path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/>
            </svg>
            Fetching...
        `;

        try {
            // Build the coordinate identifier
            // Prefer coordinate provided by backend/template; otherwise build from kind/pubkey/slug.
            let coordinate = (articleCoordinate || '').trim();
            if (!coordinate && articlePubkey && articleSlug) {
                const kind = (articleKind || '30023').trim();
                coordinate = `${kind}:${articlePubkey}:${articleSlug}`;
            }

            // Call the API endpoint to fetch the article
            const response = await fetch('/api/fetch-article', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    id: articleId,
                    kind: articleKind,
                    pubkey: articlePubkey,
                    slug: articleSlug,
                    coordinate: coordinate
                })
            });

            const data = await response.json();

            if (response.ok && data.success) {
                // Show success toast
                window.showToast('Article fetched successfully from Nostr relays!', 'success', 3000);

                // Show success message on button
                button.innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    Fetched!
                `;

                // Reload the page to show the fetched article
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                throw new Error(data.error || 'Failed to fetch article');
            }
        } catch (error) {
            console.error('Error fetching article:', error);

            // Show error toast
            window.showToast(`Failed to fetch article: ${error.message}`, 'danger', 5000);

            // Show error state on button
            button.innerHTML = `
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                Failed - Try Again
            `;

            // Re-enable button after a delay
            setTimeout(() => {
                button.disabled = false;
                button.innerHTML = originalText;
            }, 2000);
        }
    }
}
