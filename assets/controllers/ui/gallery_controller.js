import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["mainImage", "thumbnail", "annotations", "warning", "content", "error"];

    connect() {
        this._onKeyDown = this._onKeyDown.bind(this);
        this.element.addEventListener('keydown', this._onKeyDown);
        this.prepare();
    }

    disconnect() {
        this.element.removeEventListener('keydown', this._onKeyDown);
    }

    switch(event) {
        event.preventDefault();
        this.showImageAtIndex(Number(event.currentTarget.dataset.galleryIndex));
    }

    reveal() {
        if (!this.hasContentTarget) return;
        const content = this.contentTarget;
        content.replaceWith(content.content.cloneNode(true));
        if (this.hasWarningTarget) this.warningTarget.remove();
        this.prepare();
        this.element.focus();
    }

    prepare() {
        this.element.setAttribute('tabindex', '0');
        this.element.querySelectorAll('[data-gallery-annotation]').forEach(annotation => {
            annotation.style.left = `${annotation.dataset.left}%`;
            annotation.style.top = `${annotation.dataset.top}%`;
        });
        this.element.querySelectorAll('img[data-fallbacks]').forEach(image => {
            if (image.complete && image.naturalWidth === 0) this.fallback({ target: image });
        });
    }

    fallback(event) {
        const image = event.target;
        const fallbacks = JSON.parse(image.dataset.fallbacks || '[]');
        const index = Number(image.dataset.fallbackIndex || 0);
        if (index < fallbacks.length) {
            image.dataset.fallbackIndex = String(index + 1);
            image.src = fallbacks[index];
            return;
        }
        console.warn('Gallery image could not be loaded.', image.currentSrc || image.src);
        if (this.hasMainImageTarget && image === this.mainImageTarget && this.hasErrorTarget) {
            this.errorTarget.hidden = false;
        }
    }

    showImageAtIndex(index) {
        const thumbnails = this.thumbnailTargets;
        if (!Number.isInteger(index) || !thumbnails[index] || !this.hasMainImageTarget) return;
        const thumbnail = thumbnails[index];
        const main = this.mainImageTarget;
        main.dataset.fallbacks = thumbnail.dataset.fallbacks;
        main.dataset.fallbackIndex = '0';
        main.src = thumbnail.dataset.url;
        main.alt = thumbnail.dataset.alt;
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
        thumbnails.forEach(t => {
            t.classList.remove('selected');
            t.setAttribute('aria-pressed', 'false');
        });
        thumbnail.classList.add('selected');
        thumbnail.setAttribute('aria-pressed', 'true');
        this.annotationsTargets.forEach(group => {
            group.hidden = Number(group.dataset.galleryIndex) !== index;
        });
        if (this.hasErrorTarget) this.errorTarget.hidden = true;
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
        } else if (event.key === 'Home') {
            event.preventDefault();
            this.showImageAtIndex(0);
        } else if (event.key === 'End') {
            event.preventDefault();
            this.showImageAtIndex(this.thumbnailTargets.length - 1);
        }
    }
}
