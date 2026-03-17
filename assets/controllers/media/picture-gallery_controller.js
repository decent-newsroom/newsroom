import { Controller } from '@hotwired/stimulus';

/**
 * Picture gallery lightbox controller for Instagram-style curation views (kind 30006).
 *
 * stimulusFetch: 'lazy'
 */
export default class extends Controller {
    static targets = ['item', 'lightbox', 'lightboxImage'];

    currentIndex = 0;
    images = [];

    connect() {
        // Collect all image URLs and alt text from items
        this.images = this.itemTargets.map(item => ({
            url: item.dataset.imageUrl,
            alt: item.dataset.imageAlt || '',
        }));

        // Listen for keyboard nav
        this._onKeydown = this._handleKeydown.bind(this);
        document.addEventListener('keydown', this._onKeydown);
    }

    disconnect() {
        document.removeEventListener('keydown', this._onKeydown);
    }

    open(event) {
        const index = parseInt(event.currentTarget.dataset.index, 10);
        if (isNaN(index) || index < 0 || index >= this.images.length) return;

        this.currentIndex = index;
        this._show();
    }

    close() {
        this.lightboxTarget.classList.remove('is-open');
        document.body.style.overflow = '';
    }

    prev() {
        if (this.currentIndex > 0) {
            this.currentIndex--;
            this._show();
        }
    }

    next() {
        if (this.currentIndex < this.images.length - 1) {
            this.currentIndex++;
            this._show();
        }
    }

    _show() {
        const img = this.images[this.currentIndex];
        if (!img) return;

        this.lightboxImageTarget.src = img.url;
        this.lightboxImageTarget.alt = img.alt;
        this.lightboxTarget.classList.add('is-open');
        document.body.style.overflow = 'hidden';
    }

    _handleKeydown(e) {
        if (!this.lightboxTarget.classList.contains('is-open')) return;
        if (e.key === 'Escape') this.close();
        else if (e.key === 'ArrowLeft') this.prev();
        else if (e.key === 'ArrowRight') this.next();
    }
}

