import { Controller } from '@hotwired/stimulus';

/*
 * Discover Scroll Controller
 * Handles infinite scroll for the media discovery page
 * Loads more media items as user scrolls down
 */
export default class extends Controller {
    static targets = ['grid', 'loader'];
    static values = {
        page: { type: Number, default: 1 },
        loading: { type: Boolean, default: false },
        hasMore: { type: Boolean, default: true }
    };

    connect() {
        // Infinite scroll observer
        this.scrollObserver = new IntersectionObserver(
            entries => this.handleIntersection(entries),
            {
                root: null,
                rootMargin: '400px', // Start loading 400px before reaching the end
                threshold: 0
            }
        );

        if (this.hasLoaderTarget) {
            this.scrollObserver.observe(this.loaderTarget);
        }
    }

    disconnect() {
        if (this.scrollObserver) {
            this.scrollObserver.disconnect();
        }
    }

    handleIntersection(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting && !this.loadingValue && this.hasMoreValue) {
                this.loadMore();
            }
        });
    }

    async loadMore() {
        if (this.loadingValue || !this.hasMoreValue) return;

        this.loadingValue = true;
        this.pageValue++;

        try {
            const url = `/discover/load-more?page=${this.pageValue}`;
            const response = await fetch(url);

            if (!response.ok) {
                throw new Error('Failed to load more items');
            }

            const data = await response.json();

            // Add new media items to the grid
            if (data.events && data.events.length > 0) {
                const fragment = document.createDocumentFragment();

                data.events.forEach(event => {
                    const item = this.createMediaItem(event);
                    const div = document.createElement('div');
                    div.innerHTML = item.trim();
                    fragment.appendChild(div.firstChild);
                });

                this.gridTarget.appendChild(fragment);
            }

            // Update hasMore flag
            this.hasMoreValue = data.hasMore;

            // Hide loader if no more items
            if (!data.hasMore && this.hasLoaderTarget) {
                this.loaderTarget.style.display = 'none';
            }

        } catch (error) {
            console.error('Error loading more media:', error);
            if (this.hasLoaderTarget) {
                this.loaderTarget.innerHTML = '<p style="text-align: center; color: #999;">Error loading more items. Scroll to retry.</p>';
            }
        } finally {
            this.loadingValue = false;
        }
    }

    createMediaItem(event) {
        // Extract title
        let title = null;
        let firstImageUrl = null;
        let firstVideoUrl = null;
        let imageAlt = null;
        let isVideo = false;
        let imageWidth = null;
        let imageHeight = null;

        // Find title tag
        if (event.tags) {
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
                    let width = null;
                    let height = null;

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
                        } else if (param.startsWith('dim ')) {
                            // Extract dimensions like "dim 1920x1080"
                            const dimensions = param.substring(4).split('x');
                            if (dimensions.length === 2) {
                                width = parseInt(dimensions[0]);
                                height = parseInt(dimensions[1]);
                            }
                        }
                    }

                    if (videoUrl && !firstVideoUrl) {
                        firstVideoUrl = videoUrl;
                        if (previewImage && !firstImageUrl) {
                            firstImageUrl = previewImage;
                        } else if (imageUrl && !firstImageUrl) {
                            firstImageUrl = imageUrl;
                        }
                        if (width && height && !imageWidth) {
                            imageWidth = width;
                            imageHeight = height;
                        }
                    }

                    if (!videoUrl && !firstImageUrl) {
                        if (imageUrl) {
                            firstImageUrl = imageUrl;
                            if (width && height && !imageWidth) {
                                imageWidth = width;
                                imageHeight = height;
                            }
                        } else if (previewImage) {
                            firstImageUrl = previewImage;
                        }
                    }
                }
            });
        }

        // Calculate aspect ratio percentage for placeholder if dimensions available
        let aspectRatio = null;
        let hasDimensions = false;
        if (imageWidth && imageHeight) {
            aspectRatio = (imageHeight / imageWidth * 100).toFixed(2);
            hasDimensions = true;
        }

        const contentPreview = event.content && event.content.length > 100
            ? event.content.substring(0, 100) + '...'
            : event.content || '';

        let imageHtml = '';
        if (firstImageUrl) {
            const containerClass = hasDimensions ? 'masonry-image-container has-dimensions' : 'masonry-image-container';
            const styleAttr = hasDimensions ? `style="padding-bottom: ${aspectRatio}%;"` : '';

            imageHtml = `
                <div class="${containerClass}" ${styleAttr} data-controller="image-loader">
                    <img data-src="${this.escapeHtml(firstImageUrl)}"
                         alt="${this.escapeHtml(imageAlt || title || (isVideo ? 'Video' : 'Picture'))}"
                         class="masonry-image"
                         data-image-loader-target="image"
                         onerror="this.closest('.masonry-item').style.display='none'" />
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
                <div class="masonry-image-container video-no-preview" style="padding-bottom: 75%;">
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
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}
