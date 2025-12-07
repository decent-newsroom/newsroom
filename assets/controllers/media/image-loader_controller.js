import { Controller } from '@hotwired/stimulus';

/*
 * Image Loader Controller
 * Defers loading images until they scroll nearby using Intersection Observer
 */
export default class extends Controller {
    static targets = ['image'];

    connect() {
        // Create intersection observer to load images when nearby
        this.observer = new IntersectionObserver(
            (entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        this.loadImage(entry.target);
                        this.observer.unobserve(entry.target);
                    }
                });
            },
            {
                root: null,
                rootMargin: '200px', // Start loading 200px before image enters viewport
                threshold: 0
            }
        );

        // Start observing the image
        this.observer.observe(this.imageTarget);
    }

    disconnect() {
        if (this.observer) {
            this.observer.disconnect();
        }
    }

    loadImage(img) {
        const src = img.getAttribute('data-src');
        if (!src) return;

        // Set up load event before setting src
        img.addEventListener('load', () => {
            img.classList.add('loaded');
        }, { once: true });

        // Start loading the image
        img.src = src;

        // If image is already cached, it might load instantly
        if (img.complete) {
            img.classList.add('loaded');
        }
    }
}
