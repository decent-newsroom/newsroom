import { Controller } from '@hotwired/stimulus';

/**
 * Deferred Embed Controller
 *
 * Handles nostr embeds that couldn't be resolved from local data at
 * render time.  Automatically attempts to fetch and render them
 * client-side via the preview API.
 */
export default class extends Controller {
    static values = {
        bech: String,
        type: String,
    };

    connect() {
        this.fetchEmbed();
    }

    async fetchEmbed() {
        const bech = this.bechValue;
        const type = this.typeValue;

        if (!bech || !type) return;

        try {
            const response = await fetch('/api/preview/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    identifier: bech,
                    type: type,
                    decoded: this.decodeBech(bech, type),
                }),
            });

            if (!response.ok) return;

            const html = await response.text();
            if (html && !html.includes('alert-warning')) {
                this.element.outerHTML = html;
            }
        } catch (e) {
            // Silently fail — the placeholder link remains usable
        }
    }

    /**
     * Build a minimal decoded payload for the preview API.
     * The server only needs kind/pubkey/identifier for naddr,
     * and id/relays for nevent/note.
     */
    decodeBech(bech, type) {
        // We can't decode bech32 client-side without a library,
        // but the server API already handles raw bech identifiers.
        // Pass what we know — the API extracts the rest.
        return { bech, type };
    }
}

