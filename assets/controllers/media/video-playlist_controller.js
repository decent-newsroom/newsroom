import { Controller } from '@hotwired/stimulus';

/**
 * Video playlist controller for YouTube-style curation views (kind 30005).
 * Clicking a list item loads that video in the main player.
 *
 * stimulusFetch: 'lazy'
 */
export default class extends Controller {
    static targets = ['player', 'playerTitle', 'playerMeta', 'item'];

    static values = {
        activeIndex: { type: Number, default: 0 }
    };

    connect() {
        // Auto-play first video
        if (this.itemTargets.length > 0) {
            this._activate(0);
        }
    }

    select(event) {
        event.preventDefault();
        const index = parseInt(event.currentTarget.dataset.index, 10);
        if (isNaN(index) || index < 0 || index >= this.itemTargets.length) return;
        this._activate(index);
    }

    _activate(index) {
        const item = this.itemTargets[index];
        if (!item) return;

        const videoUrl = item.dataset.videoUrl;
        const title = item.dataset.videoTitle || '';
        const author = item.dataset.videoAuthor || '';

        // Update active states
        this.itemTargets.forEach((el, i) => {
            el.classList.toggle('is-active', i === index);
        });
        this.activeIndexValue = index;

        // Update player
        if (this.hasPlayerTarget && videoUrl) {
            const video = this.playerTarget.querySelector('video');
            if (video) {
                video.src = videoUrl;
                video.load();
            }
        }

        if (this.hasPlayerTitleTarget) {
            this.playerTitleTarget.textContent = title;
        }
        if (this.hasPlayerMetaTarget) {
            this.playerMetaTarget.textContent = author;
        }
    }
}

