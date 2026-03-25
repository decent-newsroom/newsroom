import { Controller } from '@hotwired/stimulus';

/**
 * Horizontal slider for magazine category cards.
 * Connects to data-controller="ui--category-slider"
 *
 * Supports:
 *  - Arrow-button scrolling (smooth)
 *  - Touch / mouse drag scrolling (native via CSS overflow-x)
 *  - Auto-hides arrows when content fits without scrolling
 */
export default class extends Controller {
    static targets = ['track', 'prevBtn', 'nextBtn'];

    connect() {
        this._updateArrows = this._updateArrows.bind(this);
        this.trackTarget.addEventListener('scroll', this._updateArrows, { passive: true });
        // Initial check after layout settles
        requestAnimationFrame(() => this._updateArrows());
        // Re-check on window resize
        this._resizeObserver = new ResizeObserver(() => this._updateArrows());
        this._resizeObserver.observe(this.trackTarget);
    }

    disconnect() {
        this.trackTarget.removeEventListener('scroll', this._updateArrows);
        if (this._resizeObserver) {
            this._resizeObserver.disconnect();
        }
    }

    scrollLeft() {
        this._scrollBy(-1);
    }

    scrollRight() {
        this._scrollBy(1);
    }

    // ── private ──────────────────────────────────────────────────

    _scrollBy(direction) {
        const track = this.trackTarget;
        // Scroll by roughly one slide width (first child width + gap)
        const slide = track.querySelector('.category-slider__slide');
        const scrollAmount = slide ? slide.offsetWidth + 16 : 300;
        track.scrollBy({ left: direction * scrollAmount, behavior: 'smooth' });
    }

    _updateArrows() {
        const track = this.trackTarget;
        const atStart = track.scrollLeft <= 1;
        const atEnd = track.scrollLeft + track.clientWidth >= track.scrollWidth - 1;

        if (this.hasPrevBtnTarget) {
            this.prevBtnTarget.classList.toggle('category-slider__arrow--hidden', atStart);
        }
        if (this.hasNextBtnTarget) {
            this.nextBtnTarget.classList.toggle('category-slider__arrow--hidden', atEnd);
        }
    }
}

