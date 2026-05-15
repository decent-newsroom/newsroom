import { Controller } from '@hotwired/stimulus';

/**
 * Relay Feed controller.
 *
 * Subscribes to a Mercure topic for a specific relay feed and prepends new
 * article cards as they arrive. Also sends periodic keepalive pings so that
 * the async worker keeps its subscription alive while the page is open.
 *
 * stimulusFetch: 'lazy'
 */
export default class extends Controller {
    static values = {
        topic:        String,
        keepaliveUrl: String,
        mutedPubkeys: Array,
    };

    static targets = ['list', 'count', 'indicator', 'empty'];

    /** @type {EventSource|null} */
    es = null;

    /** @type {number|null} */
    keepaliveTimer = null;

    connect() {
        this._cardCount = this.listTarget.querySelectorAll('.card').length;
        this._updateCount();

        this._openEventSource();
        this._startKeepalive();
    }

    disconnect() {
        this.es?.close();
        this.es = null;

        if (this.keepaliveTimer !== null) {
            clearInterval(this.keepaliveTimer);
            this.keepaliveTimer = null;
        }
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    _openEventSource() {
        const hubUrl = window.MercureHubUrl
            || document.querySelector('meta[name="mercure-hub"]')?.content;

        if (!hubUrl || !this.topicValue) {
            console.warn('[relay-feed] No Mercure hub URL or topic configured');
            this._setStatus('offline');
            return;
        }

        const url = new URL(hubUrl);
        url.searchParams.append('topic', this.topicValue);

        // Use default (no credentials): the Mercure hub has `anonymous` enabled
        // so unauthenticated EventSource subscriptions work for public topics.
        this.es = new EventSource(url.toString());

        this.es.onopen = () => {
            this._setStatus('live');
            console.debug('[relay-feed] Connected to', this.topicValue);
        };

        this.es.onmessage = (event) => {
            try {
                const card = JSON.parse(event.data);

                // Skip articles from muted authors (live updates).
                if (card.pubkey && this.mutedPubkeysValue.includes(card.pubkey)) {
                    return;
                }

                this._prependCard(card);
            } catch (e) {
                console.warn('[relay-feed] Failed to parse Mercure message', e);
            }
        };

        this.es.onerror = () => {
            this._setStatus('offline');
            if (this.es?.readyState === EventSource.CLOSED) {
                console.warn('[relay-feed] SSE connection closed');
            }
        };
    }

    /**
     * Build and prepend a card element from raw JSON card data.
     * Uses the same .card / .card-header / .card-body / .card-footer structure
     * as the server-side Card component so no extra CSS is needed.
     *
     * @param {{ id:string, pubkey:string, npub?:string, created_at:number, title:string, summary:string, image:string, naddr:string }} card
     */
    _prependCard(card) {
        // Remove the "waiting" empty state on first card
        if (this.hasEmptyTarget) {
            this.emptyTarget.remove();
        }

        const title   = card.title   || 'Untitled';
        const summary = card.summary || '';
        const image   = card.image   || '';
        const naddr   = card.naddr   || '';
        const pubkey  = card.pubkey  || '';
        const npub    = card.npub    || '';
        const time    = card.created_at ? new Date(card.created_at * 1000) : null;

        const timeIso   = time ? time.toISOString() : '';
        const timeLabel = time
            ? time.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric', timeZone: 'UTC' })
            : '';

        const articleUrl = naddr ? `/article/${naddr}` : null;

        const shortHex = pubkey ? `${pubkey.slice(0, 8)}...` : '';
        const authorHtml = npub
            ? `<p class="m-0">by <a href="/p/${this._escape(npub)}" data-turbo-frame="_top">${this._escape(npub.slice(0, 12))}...</a></p>`
            : (shortHex ? `<p class="m-0">by ${this._escape(shortHex)}</p>` : '');

        const cardEl = document.createElement('div');
        cardEl.className = 'card';

        cardEl.innerHTML = `
            <div class="metadata">
                ${authorHtml}
                ${timeIso ? `<span><small><time datetime="${timeIso}">${timeLabel}</time></small></span>` : ''}
            </div>
            ${articleUrl ? `<a href="${articleUrl}" data-turbo-frame="_top">` : ''}
            <div class="card-header">
                ${image ? `<img src="${this._escape(image)}" alt="${this._escape(title)}" loading="lazy">` : ''}
            </div>
            <div class="card-body">
                <h2 class="card-title line-clamp-5">${this._escape(title)}</h2>
                ${summary ? `<p class="lede line-clamp-5">${this._escape(summary)}</p>` : ''}
            </div>
            ${articleUrl ? `</a>` : ''}
            <div class="card-footer"></div>
        `;

        this.listTarget.prepend(cardEl);

        // Trim the visible list to BUFFER_MAX (100)
        const cards = this.listTarget.querySelectorAll('.card');
        if (cards.length > 100) {
            cards[cards.length - 1].remove();
        }

        this._cardCount++;
        this._updateCount();
    }

    _updateCount() {
        if (this.hasCountTarget) {
            this.countTarget.textContent = String(this._cardCount);
        }
    }

    _setStatus(status) {
        if (!this.hasIndicatorTarget) return;
        this.indicatorTarget.className = `relay-feed__indicator relay-feed__indicator--${status}`;
    }

    /**
     * Ping the keepalive endpoint every 5 minutes to extend the Redis active flag,
     * ensuring the async worker keeps re-dispatching.
     */
    _startKeepalive() {
        if (!this.keepaliveUrlValue) return;

        // Immediate ping on connect
        this._ping();

        // Then every 5 minutes
        this.keepaliveTimer = setInterval(() => this._ping(), 5 * 60 * 1000);
    }

    async _ping() {
        try {
            await fetch(this.keepaliveUrlValue, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
        } catch (e) {
            console.warn('[relay-feed] Keepalive ping failed', e);
        }
    }

    /** HTML-escape a string to prevent XSS when building innerHTML. */
    _escape(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
}

