import { Controller } from '@hotwired/stimulus';

/**
 * Subscribes to the Mercure relay-feed topic for the strfry-essayist relay
 * and prepends new article cards to the sidebar widget as they arrive.
 * Keeps the list capped at 2 items (newest first) and sends periodic
 * keepalive pings so the async worker maintains its WebSocket subscription.
 *
 * Usage (set by EssayistController::home()):
 *   <div data-controller="content--essayist-latest"
 *        data-content--essayist-latest-topic-value="/relay-feed/{key}"
 *        data-content--essayist-latest-keepalive-url-value="/essayist/home/keepalive">
 *     <twig:Atoms:LatestEssayistArticles :limit="2" />
 *   </div>
 *
 * stimulusFetch: 'lazy'
 */
export default class extends Controller {
    static values = {
        topic:        String,
        keepaliveUrl: String,
    };

    static targets = ['list'];

    /** Maximum items to keep visible in the sidebar */
    static MAX = 2;

    /** @type {EventSource|null} */
    es = null;

    /** @type {number|null} */
    keepaliveTimer = null;

    connect() {
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
            console.debug('[essayist-latest] No Mercure hub URL or topic — skipping live updates');
            return;
        }

        const url = new URL(hubUrl);
        url.searchParams.append('topic', this.topicValue);

        this.es = new EventSource(url.toString());

        this.es.onopen = () => {
            console.debug('[essayist-latest] Subscribed to', this.topicValue);
        };

        this.es.onmessage = (event) => {
            try {
                const card = JSON.parse(event.data);
                this._prependCard(card);
            } catch (e) {
                console.warn('[essayist-latest] Failed to parse Mercure message', e);
            }
        };

        this.es.onerror = () => {
            if (this.es?.readyState === EventSource.CONNECTING) {
                console.debug('[essayist-latest] Reconnecting…');
            } else {
                console.warn('[essayist-latest] Connection lost');
            }
        };
    }

    /**
     * Build a list item from the raw card data and prepend it to the list,
     * trimming entries beyond MAX.
     *
     * @param {{ title:string, naddr:string, npub?:string, pubkey:string, created_at:number }} card
     */
    _prependCard(card) {
        if (!this.hasListTarget) return;

        const title   = card.title   || 'Untitled';
        const naddr   = card.naddr   || '';
        const npub    = card.npub    || '';
        const time    = card.created_at ? new Date(card.created_at * 1000) : null;
        const timeIso = time ? time.toISOString() : '';
        const timeLabel = time
            ? time.toLocaleDateString('en-US', { month: 'short', day: 'numeric', timeZone: 'UTC' })
            : '';
        const articleUrl = naddr ? `/article/${this._escape(naddr)}` : null;

        const li = document.createElement('li');
        li.className = 'essayist-latest-essays__item essayist-latest-essays__item--new';

        li.innerHTML = articleUrl
            ? `<a href="${articleUrl}" class="essayist-latest-essays__link" data-turbo-frame="_top">
                   <span class="essayist-latest-essays__title">${this._escape(title)}</span>
                   ${timeIso ? `<span class="essayist-latest-essays__meta"><time datetime="${timeIso}">${timeLabel}</time></span>` : ''}
               </a>`
            : `<span class="essayist-latest-essays__title">${this._escape(title)}</span>`;

        this.listTarget.prepend(li);

        // Remove the animation class after it completes so it doesn't replay
        li.addEventListener('animationend', () => li.classList.remove('essayist-latest-essays__item--new'), { once: true });

        // Trim the list to MAX items
        const items = this.listTarget.querySelectorAll('li');
        if (items.length > this.constructor.MAX) {
            items[items.length - 1].remove();
        }
    }

    /**
     * Ping the keepalive endpoint every 5 minutes to extend the Redis active flag,
     * ensuring the async worker keeps re-dispatching.
     */
    _startKeepalive() {
        if (!this.keepaliveUrlValue) return;
        this._ping();
        this.keepaliveTimer = setInterval(() => this._ping(), 5 * 60 * 1000);
    }

    async _ping() {
        try {
            await fetch(this.keepaliveUrlValue, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
        } catch (e) {
            console.warn('[essayist-latest] Keepalive ping failed', e);
        }
    }

    /** HTML-escape a value to prevent XSS when building innerHTML. */
    _escape(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
}
