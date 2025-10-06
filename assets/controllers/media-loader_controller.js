import { Controller } from '@hotwired/stimulus';

/*
 * Media Loader Controller
 * Handles "Load More" functionality for author media galleries
 * Fetches additional media items from cache and appends to masonry grid
 */
export default class extends Controller {
    static targets = ['grid', 'button', 'status'];
    static values = {
        npub: String,
        page: { type: Number, default: 2 },
        total: Number
    };

    connect() {
        this.isLoading = false;
    }

    async loadMore() {
        if (this.isLoading) return;

        this.isLoading = true;
        this.buttonTarget.disabled = true;
        this.buttonTarget.textContent = 'Loading...';

        try {
            const url = `/p/${this.npubValue}/media/load-more?page=${this.pageValue}`;
            const response = await fetch(url);
            const data = await response.json();

            // Add new media items to the grid
            data.events.forEach(event => {
                const item = this.createMediaItem(event);
                this.gridTarget.insertAdjacentHTML('beforeend', item);
            });

            this.pageValue++;

            // Update status
            const currentCount = this.gridTarget.querySelectorAll('.masonry-item').length;
            this.statusTarget.textContent = `Showing ${currentCount} of ${data.total} media items`;

            // Hide button if no more items
            if (!data.hasMore) {
                this.buttonTarget.style.display = 'none';
                this.statusTarget.textContent = `All ${data.total} media items loaded`;
            }
        } catch (error) {
            console.error('Error loading more media:', error);
            this.buttonTarget.textContent = 'Error - Click to retry';
        } finally {
            this.isLoading = false;
            this.buttonTarget.disabled = false;
            if (this.buttonTarget.textContent === 'Loading...') {
                this.buttonTarget.textContent = 'Load More';
            }
        }
    }

    createMediaItem(event) {
        // Extract title
        let title = null;
        let firstImageUrl = null;
        let firstVideoUrl = null;
        let imageAlt = null;
        let isVideo = false;

        // Find title tag
        event.tags.forEach(tag => {
            if (tag[0] === 'title') {
                title = tag[1];
            }
        });

        // Extract first image from imeta tags
        event.tags.forEach(tag => {
            if (tag[0] === 'imeta') {
                let videoUrl = null;
                let imageUrl = null;
                let previewImage = null;

                for (let i = 1; i < tag.length; i++) {
                    const param = tag[i];
                    if (param.startsWith('url ')) {
                        const potentialUrl = param.substring(4);
                        if (/\.(mp4|webm|ogg|mov)$/i.test(potentialUrl) || /video/i.test(potentialUrl)) {
                            videoUrl = potentialUrl;
                            isVideo = true;
                        } else {
                            imageUrl = potentialUrl;
                        }
                    } else if (param.startsWith('image ')) {
                        previewImage = param.substring(6);
                    } else if (param.startsWith('alt ')) {
                        imageAlt = param.substring(4);
                    }
                }

                if (videoUrl && !firstVideoUrl) {
                    firstVideoUrl = videoUrl;
                    if (previewImage && !firstImageUrl) {
                        firstImageUrl = previewImage;
                    } else if (imageUrl && !firstImageUrl) {
                        firstImageUrl = imageUrl;
                    }
                }

                if (!videoUrl && !firstImageUrl) {
                    if (imageUrl) {
                        firstImageUrl = imageUrl;
                    } else if (previewImage) {
                        firstImageUrl = previewImage;
                    }
                }
            }
        });

        const eventDate = new Date(event.created_at * 1000).toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric'
        });
        const contentPreview = event.content && event.content.length > 100
            ? event.content.substring(0, 100) + '...'
            : event.content || '';

        let imageHtml = '';
        if (firstImageUrl) {
            imageHtml = `
                <div class="masonry-image-container">
                    <img src="${this.escapeHtml(firstImageUrl)}"
                         alt="${this.escapeHtml(imageAlt || title || (isVideo ? 'Video' : 'Picture'))}"
                         class="masonry-image"
                         loading="lazy" />
                    ${isVideo ? `
                        <div class="video-overlay">
                            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="white" opacity="0.9">
                                <path d="M8 5v14l11-7z"/>
                            </svg>
                        </div>
                    ` : ''}
                    ${title || contentPreview ? `
                        <div class="masonry-hover-caption">
                            ${title ? `<h4>${this.escapeHtml(title)}</h4>` : ''}
                            ${contentPreview ? `<p>${this.escapeHtml(contentPreview)}</p>` : ''}
                        </div>
                    ` : ''}
                </div>
            `;
        } else if (isVideo) {
            imageHtml = `
                <div class="masonry-image-container video-no-preview">
                    <div class="video-placeholder">
                        <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="currentColor" opacity="0.4">
                            <path d="M8 5v14l11-7z"/>
                        </svg>
                    </div>
                    ${title || contentPreview ? `
                        <div class="masonry-hover-caption">
                            ${title ? `<h4>${this.escapeHtml(title)}</h4>` : ''}
                            ${contentPreview ? `<p>${this.escapeHtml(contentPreview)}</p>` : ''}
                        </div>
                    ` : ''}
                </div>
            `;
        }

        return `
            <div class="masonry-item">
                <a href="/e/${event.noteId}" class="masonry-link">
                    ${imageHtml}
                </a>
            </div>
        `;
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}
