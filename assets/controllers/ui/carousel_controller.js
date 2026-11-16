import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['track', 'previous', 'next'];

    connect() {
        this.update = this.update.bind(this);
        this.trackTarget.addEventListener('scroll', this.update, { passive: true });
        this.observer = new ResizeObserver(this.update);
        this.observer.observe(this.trackTarget);
        this.frame = requestAnimationFrame(this.update);
    }

    disconnect() {
        this.trackTarget.removeEventListener('scroll', this.update);
        this.observer.disconnect();
        cancelAnimationFrame(this.frame);
    }

    previous() {
        this.scroll(-1);
    }

    next() {
        this.scroll(1);
    }

    scroll(direction) {
        const slide = this.trackTarget.firstElementChild;
        const gap = parseFloat(getComputedStyle(this.trackTarget).columnGap) || 0;
        const distance = slide ? slide.getBoundingClientRect().width + gap : this.trackTarget.clientWidth;
        this.trackTarget.scrollBy({ left: direction * distance, behavior: 'auto' });
    }

    update() {
        const track = this.trackTarget;
        const overflow = track.scrollWidth > track.clientWidth + 1;
        this.previousTarget.hidden = !overflow;
        this.nextTarget.hidden = !overflow;
        this.previousTarget.disabled = track.scrollLeft <= 1;
        this.nextTarget.disabled = track.scrollLeft + track.clientWidth >= track.scrollWidth - 1;
    }
}
