import { Controller } from "@hotwired/stimulus";

// Connects to data-controller="gallery"
export default class extends Controller {
    static targets = ["mainImage", "thumbnail"];

    connect() {
        this._onKeyDown = this._onKeyDown.bind(this);
        this.element.addEventListener('keydown', this._onKeyDown);
        // Make the gallery focusable for keyboard events
        this.element.setAttribute('tabindex', '0');
    }

    disconnect() {
        this.element.removeEventListener('keydown', this._onKeyDown);
    }

    switch(event) {
        const index = event.currentTarget.dataset.galleryIndex;
        this.showImageAtIndex(Number(index));
    }

    showImageAtIndex(index) {
        const thumbnails = this.thumbnailTargets;
        if (!thumbnails[index]) return;
        const thumbnail = thumbnails[index];
        const main = this.mainImageTarget;
        main.src = thumbnail.src;
        main.alt = thumbnail.alt;
        if (thumbnail.dataset.dimensions) {
            main.setAttribute('data-dimensions', thumbnail.dataset.dimensions);
        } else {
            main.removeAttribute('data-dimensions');
        }
        if (thumbnail.dataset.blurhash) {
            main.setAttribute('data-blurhash', thumbnail.dataset.blurhash);
        } else {
            main.removeAttribute('data-blurhash');
        }
        thumbnails.forEach(t => t.classList.remove('selected'));
        thumbnail.classList.add('selected');
        // Store current index for keyboard navigation
        this.currentIndex = index;
    }

    _onKeyDown(event) {
        if (!this.thumbnailTargets.length) return;
        // Find the currently selected index
        let current = this.currentIndex;
        if (typeof current !== 'number') {
            current = this.thumbnailTargets.findIndex(t => t.classList.contains('selected'));
        }
        if (event.key === 'ArrowRight') {
            event.preventDefault();
            const next = (current + 1) % this.thumbnailTargets.length;
            this.showImageAtIndex(next);
        } else if (event.key === 'ArrowLeft') {
            event.preventDefault();
            const prev = (current - 1 + this.thumbnailTargets.length) % this.thumbnailTargets.length;
            this.showImageAtIndex(prev);
        }
    }
}
