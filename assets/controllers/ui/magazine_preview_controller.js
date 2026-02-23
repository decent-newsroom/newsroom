import { Controller } from '@hotwired/stimulus';

/**
 * Live preview controller for the magazine setup wizard.
 * Listens to input events on title, summary, and image fields
 * and updates the MagazineHero component rendered in preview mode.
 */
export default class extends Controller {
    static targets = [
        'titleInput',
        'summaryInput',
        'imageInput',
        'preview',
    ];

    connect() {
        this.updatePreview();
    }

    updatePreview() {
        if (!this.hasPreviewTarget) return;

        const container = this.previewTarget;
        const title = this.hasTitleInputTarget ? this.titleInputTarget.value : '';
        const summary = this.hasSummaryInputTarget ? this.summaryInputTarget.value : '';
        const imageUrl = this.hasImageInputTarget ? this.imageInputTarget.value : '';

        // Update the title inside the hero (the h1)
        const h1 = container.querySelector('h1');
        if (h1) {
            h1.textContent = title || 'Your Magazine Title';
        }

        // Update the summary (the .eyebrow paragraph)
        let eyebrow = container.querySelector('.eyebrow');
        const sectionContainer = container.querySelector('.container');
        if (summary) {
            if (!eyebrow && sectionContainer) {
                eyebrow = document.createElement('p');
                eyebrow.className = 'eyebrow';
                const h1El = sectionContainer.querySelector('h1');
                if (h1El) {
                    h1El.after(eyebrow);
                }
            }
            if (eyebrow) {
                eyebrow.textContent = summary;
            }
        } else if (eyebrow) {
            eyebrow.remove();
        }

        // Update the cover image
        let coverDiv = container.querySelector('.magazine-hero__cover');
        if (imageUrl) {
            if (!coverDiv) {
                // Create the cover element before the section
                coverDiv = document.createElement('div');
                coverDiv.className = 'magazine-hero__cover';
                const firstChild = container.querySelector('section') || container.firstElementChild;
                if (firstChild) {
                    firstChild.parentNode.insertBefore(coverDiv, firstChild);
                }
            }
            const escapedUrl = this.escapeAttr(imageUrl);
            coverDiv.innerHTML = `<img src="${escapedUrl}" alt="${this.escapeAttr(title)}" class="magazine-hero__cover-img" onerror="this.parentNode.remove()">`;
        } else if (coverDiv) {
            coverDiv.remove();
        }
    }

    escapeAttr(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

