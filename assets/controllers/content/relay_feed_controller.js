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
        topic:                 String,
        keepaliveUrl:          String,
        mutedPubkeys:          Array,
        byLabel:               String,
        untitledLabel:         String,
        coverImageAltTemplate: String,
        loggedIn:              Boolean,
        bookmarkFetchUrl:      String,
        bookmarkPublishUrl:    String,
        bookmarkAddLabel:      String,
        bookmarkRemoveLabel:   String,
        bookmarkIcon:          String,
    };

    static targets = ['list', 'count', 'indicator', 'empty'];

    /** @type {EventSource|null} */
    es = null;

    /** @type {number|null} */
    keepaliveTimer = null;

    connect() {
        this._cardCount = this.listTarget.querySelectorAll('.article-card').length;
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
     * Build and prepend the same article-card structure emitted by
     * Molecules:Card for the server-rendered buffer.
     *
     * @param {{ id:string, pubkey:string, npub?:string, created_at:number, title:string, summary:string, image:string, d_tag:string, naddr:string }} card
     */
    _prependCard(card) {
        // Remove the "waiting" empty state on first card
        if (this.hasEmptyTarget) {
            this.emptyTarget.remove();
        }

        const title   = card.title   || this.untitledLabelValue;
        const summary = card.summary || '';
        const image   = card.image   || '';
        const naddr   = card.naddr   || '';
        const pubkey  = card.pubkey  || '';
        const npub    = card.npub    || '';
        const slug    = card.d_tag   || '';
        const time    = card.created_at ? new Date(card.created_at * 1000) : null;

        const timeIso   = time ? time.toISOString() : '';
        const timeLabel = time
            ? time.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' })
            : '';

        const articleUrl = naddr ? `/e/${encodeURIComponent(naddr)}` : null;
        const coordinate = pubkey && slug ? `30023:${pubkey}:${slug}` : '';
        const imageAlt = this.coverImageAltTemplateValue.replace('__TITLE__', title);

        const shortAuthor = npub
            ? `${npub.slice(0, 12)}...`
            : (pubkey ? `${pubkey.slice(0, 8)}...` : '');
        const authorHtml = npub
            ? `<a href="/p/${this._escape(npub)}" data-turbo-frame="_top">${this._escape(shortAuthor)}</a>`
            : this._escape(shortAuthor);
        const bookmarkHtml = this.loggedInValue && coordinate
            ? `
                <div class="card-footer">
                    <button
                        type="button"
                        class="card-bookmark-btn"
                        data-controller="ui--card-bookmark"
                        data-ui--card-bookmark-target="button"
                        data-action="click->ui--card-bookmark#toggle"
                        data-ui--card-bookmark-coordinate-value="${this._escape(coordinate)}"
                        data-ui--card-bookmark-bookmark-fetch-url-value="${this._escape(this.bookmarkFetchUrlValue)}"
                        data-ui--card-bookmark-bookmark-publish-url-value="${this._escape(this.bookmarkPublishUrlValue)}"
                        data-ui--card-bookmark-add-label-value="${this._escape(this.bookmarkAddLabelValue)}"
                        data-ui--card-bookmark-remove-label-value="${this._escape(this.bookmarkRemoveLabelValue)}"
                        title="${this._escape(this.bookmarkAddLabelValue)}"
                    >
                        ${this.bookmarkIconValue}
                        <span data-ui--card-bookmark-target="label">${this._escape(this.bookmarkAddLabelValue)}</span>
                    </button>
                </div>
            `
            : '';

        const cardEl = document.createElement('article');
        cardEl.className = `card article-card${image ? '' : ' article-card--text-only'}`;
        cardEl.dataset.articleId = card.id || '';
        cardEl.dataset.uuid = card.id || '';
        cardEl.dataset.coordinate = coordinate;
        cardEl.dataset.npub = npub;

        cardEl.innerHTML = `
            <div class="article-card__body">
                <div class="article-card__content">
                    <div class="metadata">
                        ${shortAuthor ? `<span>${this._escape(this.byLabelValue)} ${authorHtml}</span>` : ''}
                        ${timeIso ? `<span><time datetime="${timeIso}">${timeLabel}</time></span>` : ''}
                    </div>
                    <h2 class="card-title line-clamp-5">
                        ${articleUrl
                            ? `<a class="article-card__title-link" href="${articleUrl}" data-turbo-frame="_top">${this._escape(title)}</a>`
                            : this._escape(title)}
                    </h2>
                    ${summary
                        ? `<p class="lede line-clamp-5" data-controller="utility--katex" data-utility--katex-display-value="false">${this._escape(summary)}</p>`
                        : ''}
                </div>
                ${image
                    ? `
                        <figure class="article-card__media">
                            <img
                                src="${this._escape(image)}"
                                alt="${this._escape(imageAlt)}"
                                loading="lazy"
                                decoding="async"
                            >
                        </figure>
                    `
                    : ''}
            </div>
            ${bookmarkHtml}
        `;

        this.listTarget.prepend(cardEl);

        // Trim the visible list to BUFFER_MAX (100)
        const cards = this.listTarget.querySelectorAll('.article-card');
        if (cards.length > 100) {
            cards[cards.length - 1].remove();
        }

        this._cardCount = Math.min(cards.length, 100);
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
