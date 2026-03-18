import { Controller } from '@hotwired/stimulus';

/**
 * Picture gallery lightbox controller for Instagram-style curation views (kind 30006).
 *
 * Opens a per-event image gallery: single-image events show only that image,
 * while multi-image events allow left/right navigation within the event.
 *
 * stimulusFetch: 'lazy'
 */
export default class extends Controller {
    static targets = ['item', 'lightbox', 'lightboxImage', 'lightboxDetails', 'prevButton', 'nextButton'];

    currentIndex = 0;
    activeImages = [];
    activeDetailsHtml = '';

    connect() {
        this._onKeydown = this._handleKeydown.bind(this);
        document.addEventListener('keydown', this._onKeydown);
    }

    disconnect() {
        document.removeEventListener('keydown', this._onKeydown);
    }

    open(event) {
        const item = event.currentTarget;
        const rawImages = item.dataset.images;
        const detailTemplate = item.querySelector('[data-picture-gallery-detail-template]');

        let images = [];
        if (rawImages) {
            try {
                const parsed = JSON.parse(rawImages);
                if (Array.isArray(parsed)) {
                    images = parsed.filter((img) => img && img.url);
                }
            } catch {
                images = [];
            }
        }

        if (images.length === 0 && item.dataset.imageUrl) {
            images = [{
                url: item.dataset.imageUrl,
                alt: item.dataset.imageAlt || '',
            }];
        }

        if (images.length === 0) {
            return;
        }

        this.activeImages = images;
        this.activeDetailsHtml = detailTemplate?.innerHTML?.trim() || '';
        this.currentIndex = 0;
        this._show();
    }

    close() {
        this.lightboxTarget.classList.remove('is-open');
        document.body.style.overflow = '';
        if (this.hasLightboxDetailsTarget) {
            this.lightboxDetailsTarget.innerHTML = '';
        }
    }

    prev(event = null) {
        event?.stopPropagation?.();
        if (this.currentIndex > 0) {
            this.currentIndex--;
            this._show();
        }
    }

    next(event = null) {
        event?.stopPropagation?.();
        if (this.currentIndex < this.activeImages.length - 1) {
            this.currentIndex++;
            this._show();
        }
    }

    _show() {
        const img = this.activeImages[this.currentIndex];
        if (!img) {
            return;
        }

        this.lightboxImageTarget.src = img.url;
        this.lightboxImageTarget.alt = img.alt || '';
        if (this.hasLightboxDetailsTarget) {
            this.lightboxDetailsTarget.innerHTML = this.activeDetailsHtml;
            this.lightboxDetailsTarget.classList.toggle('is-empty', !this.activeDetailsHtml);
        }
        this.lightboxTarget.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        this._updateNav();
    }

    _updateNav() {
        const hasMultiple = this.activeImages.length > 1;

        if (this.hasPrevButtonTarget) {
            this.prevButtonTarget.classList.toggle('is-hidden', !hasMultiple);
            this.prevButtonTarget.disabled = !hasMultiple || this.currentIndex === 0;
        }

        if (this.hasNextButtonTarget) {
            this.nextButtonTarget.classList.toggle('is-hidden', !hasMultiple);
            this.nextButtonTarget.disabled = !hasMultiple || this.currentIndex >= this.activeImages.length - 1;
        }
    }

    _handleKeydown(e) {
        if (!this.lightboxTarget.classList.contains('is-open')) return;
        if (e.key === 'Escape') this.close();
        else if (e.key === 'ArrowLeft' && this.activeImages.length > 1) this.prev();
        else if (e.key === 'ArrowRight' && this.activeImages.length > 1) this.next();
    }
}
