import { Controller } from '@hotwired/stimulus';

/**
 * Media Library Controller
 *
 * Orchestrates the media manager: loads assets/posts, handles view/scope/filter toggles,
 * renders thumbnail and list grids, manages create/upload/publish modals.
 */
export default class extends Controller {
    static targets = [
        'grid', 'status', 'loadMore',
        'scopeBtn', 'filterBtn',
        'publishModal', 'publishTitle',
        'uploadModal',
        'createMenu'
    ];

    static values = {
        pubkey: String,
        scope: { type: String, default: 'assets' },
        view: { type: String, default: 'thumbnails' },
        filter: { type: String, default: 'all' },
        provider: { type: String, default: '' },
        page: { type: Number, default: 0 },
    };

    connect() {
        this._loadController = null;   // AbortController for the active fetch
        this.loadContent();

        // Listen for provider changes
        this.element.addEventListener('media--provider-selector:changed', (e) => {
            this.providerValue = e.detail.providerId || '';
            this.pageValue = 0;
            this.loadContent();
        });

        // Close the create-menu when clicking anywhere outside it
        this._onDocumentClick = (e) => {
            if (this.hasCreateMenuTarget &&
                !this.createMenuTarget.hidden &&
                !e.target.closest('.media-manager__create-menu')) {
                this.createMenuTarget.hidden = true;
            }
        };
        document.addEventListener('click', this._onDocumentClick);
    }

    disconnect() {
        document.removeEventListener('click', this._onDocumentClick);
    }

    // --- Create-new dropdown ---
    toggleCreateMenu(event) {
        event.stopPropagation();           // don't trigger the document listener immediately
        this.createMenuTarget.hidden = !this.createMenuTarget.hidden;
    }

    // --- Scope toggle ---
    setScope(event) {
        const scope = event.currentTarget.dataset.scope;
        this.scopeValue = scope;
        this.pageValue = 0;

        this.scopeBtnTargets.forEach(btn => {
            btn.classList.toggle('active', btn.dataset.scope === scope);
        });

        this.loadContent();
    }

    // --- View toggle ---
    setView(view) {
        this.viewValue = view;
        this.gridTarget.className = `media-manager__grid media-manager__grid--${view}`;
    }

    // --- Filter toggle ---
    setFilter(event) {
        const filter = event.currentTarget.dataset.filter;
        this.filterValue = filter;
        this.pageValue = 0;

        this.filterBtnTargets.forEach(btn => {
            btn.classList.toggle('active', btn.dataset.filter === filter);
        });

        this.loadContent();
    }

    // --- Content loading ---
    async loadContent() {
        // Cancel any in-flight load so scope/filter/provider changes
        // always take effect immediately.
        if (this._loadController) {
            this._loadController.abort();
        }
        this._loadController = new AbortController();
        const signal = this._loadController.signal;

        this.statusTarget.textContent = 'Loading...';

        try {
            let items;
            if (this.scopeValue === 'assets') {
                items = await this.loadAssets(signal);
            } else {
                items = await this.loadPosts(signal);
            }

            if (signal.aborted) return;   // superseded by a newer call
            this.renderItems(items);
        } catch (error) {
            if (error.name === 'AbortError') return;   // expected — newer call replaced us
            console.error('Failed to load content:', error);
            this.statusTarget.textContent = 'Failed to load media. Please try again.';
        }
    }

    async loadAssets(signal) {
        // When no specific provider is selected, show all uploads from the
        // local DB (the same source the editor aside and standalone upload
        // modal use).  When a provider IS selected, try the remote API first
        // and fall back to filtering the local DB by provider.

        if (!this.providerValue) {
            return this._loadLocalUploads(null, signal);
        }

        // Try the remote provider API
        const params = new URLSearchParams({
            provider: this.providerValue,
            pubkey: this.pubkeyValue,
            limit: '50',
        });

        if (this.pageValue > 0) {
            params.set('cursor', String(this.pageValue));
        }

        try {
            const response = await fetch(`/api/media/assets?${params}`, { signal });
            const data = await response.json();
            const remoteAssets = data.assets || [];

            if (remoteAssets.length) {
                this.statusTarget.textContent = `Showing ${data.count || 0} uploaded assets from provider`;
                return remoteAssets;
            }
        } catch (e) {
            if (e.name === 'AbortError') throw e;   // let caller handle abort
            console.warn('Remote asset listing failed, falling back to local DB', e);
        }

        // Fallback: show locally-tracked uploads filtered by provider
        return this._loadLocalUploads(this.providerValue, signal);
    }

    /**
     * Load uploads from the local user_upload DB and normalize them
     * to the shape renderCard() expects (NormalizedMedia-compatible).
     */
    async _loadLocalUploads(providerFilter = null, signal = null) {
        const limit = 50;
        const offset = this.pageValue * limit;
        const params = new URLSearchParams({ limit: String(limit), offset: String(offset) });
        if (providerFilter) {
            params.set('provider', providerFilter);
        }

        const fetchOpts = signal ? { signal } : {};
        const response = await fetch(`/api/user-uploads?${params}`, fetchOpts);
        if (!response.ok) {
            this.statusTarget.textContent = 'Sign in to see your uploads.';
            return [];
        }

        const json = await response.json();
        const uploads = json.uploads || [];
        const total = json.total || 0;

        this.statusTarget.textContent = `Showing ${Math.min(offset + uploads.length, total)} of ${total} uploads`;

        // Normalize each user_upload row into the shape renderCard() needs
        return uploads.map(u => {
            const isImage = (u.mime_type || '').startsWith('image/');
            return {
                source_type: 'asset',
                provider_id: u.provider,
                primary_url: u.url,
                best_preview: isImage ? u.url : null,
                title: u.original_filename || null,
                description: null,
                mime: u.mime_type,
                kind: isImage ? 20 : 21,
                kind_label: isImage ? 'Picture' : 'Video',
                size: u.file_size,
                uploaded_at: u.created_at ? Math.floor(new Date(u.created_at).getTime() / 1000) : null,
                created_at: null,
                alt: u.original_filename || null,
            };
        });
    }

    async loadPosts(signal) {
        const kindsMap = {
            'all': '20,21,22',
            'pictures': '20',
            'videos': '21',
            'short-videos': '22',
        };

        const params = new URLSearchParams({
            pubkey: this.pubkeyValue,
            kinds: kindsMap[this.filterValue] || '20,21,22',
            limit: '50',
            filter: this.filterValue,
        });

        const response = await fetch(`/api/media/posts?${params}`, { signal });
        const data = await response.json();

        this.statusTarget.textContent = `Showing ${data.count || 0} published posts`;
        return data.posts || [];
    }

    // --- Rendering ---
    renderItems(items) {
        if (!items.length) {
            this.gridTarget.innerHTML = `
                <div class="media-manager__empty">
                    <p>No media found.</p>
                    <p>${this.scopeValue === 'assets'
                        ? 'Upload media to a provider to see it here.'
                        : 'Publish a picture or video to see it here.'}</p>
                </div>`;
            this.loadMoreTarget.hidden = true;
            return;
        }

        const html = items.map(item => this.renderCard(item)).join('');
        this.gridTarget.innerHTML = html;
        this.loadMoreTarget.hidden = items.length < 50;
    }

    renderCard(item) {
        const preview = item.best_preview || item.primary_url;
        const kindLabel = item.kind_label || 'Unknown';
        const title = item.title || item.description?.substring(0, 50) || 'Untitled';
        const isVideo = item.kind === 21 || item.kind === 22;
        const date = item.created_at || item.uploaded_at;
        const dateStr = date ? new Date(date * 1000).toLocaleDateString() : '';

        // Badge classes
        const kindBadge = `media-card__badge media-card__badge--${kindLabel.toLowerCase().replace(' ', '-')}`;
        const sourceBadge = item.source_type === 'asset' ? 'Uploaded' : 'Published';

        let previewHtml;
        if (preview) {
            previewHtml = `
                <div class="media-card__preview">
                    <img src="${this.escapeHtml(preview)}" alt="${this.escapeHtml(item.alt || title)}" loading="lazy" />
                    ${isVideo ? '<div class="media-card__play-icon">▶</div>' : ''}
                </div>`;
        } else {
            previewHtml = `
                <div class="media-card__preview media-card__preview--placeholder">
                    <span>${isVideo ? '🎬' : '📷'}</span>
                </div>`;
        }

        return `
            <div class="media-card" data-event-id="${item.event_id || ''}" data-url="${this.escapeHtml(item.primary_url || '')}">
                ${previewHtml}
                <div class="media-card__info">
                    <span class="${kindBadge}">${kindLabel}</span>
                    <span class="media-card__source">${sourceBadge}</span>
                    <h4 class="media-card__title">${this.escapeHtml(title)}</h4>
                    ${item.dimensions ? `<span class="media-card__meta">${item.dimensions}</span>` : ''}
                    ${item.duration ? `<span class="media-card__meta">${this.formatDuration(item.duration)}</span>` : ''}
                    <span class="media-card__date">${dateStr}</span>
                </div>
                <div class="media-card__actions">
                    <button class="btn btn--small" data-action="click->media--media-library#copyUrl" data-url="${this.escapeHtml(item.primary_url || '')}">
                        Copy URL
                    </button>
                </div>
            </div>`;
    }

    // --- Actions ---
    async loadMore() {
        this.pageValue++;
        await this.loadContent();
    }

    copyUrl(event) {
        const url = event.currentTarget.dataset.url;
        if (url) {
            navigator.clipboard.writeText(url).then(() => {
                event.currentTarget.textContent = 'Copied!';
                setTimeout(() => { event.currentTarget.textContent = 'Copy URL'; }, 2000);
            });
        }
    }

    createPicture() {
        this._openPublishModal(20, 'Create Picture (kind 20)');
    }

    createVideo() {
        this._openPublishModal(21, 'Create Video (kind 21)');
    }

    createShortVideo() {
        this._openPublishModal(22, 'Create Short Video (kind 22)');
    }

    uploadAsset() {
        this.openModal('upload');
    }

    _openPublishModal(kind, title) {
        this.publishTitleTarget.textContent = title;

        // Set the kind on the media-publish controller's element
        const publishEl = this.publishModalTarget.querySelector('[data-controller*="media--media-publish"]');
        if (publishEl) {
            publishEl.dataset.mediaMediaPublishKindValue = kind;
        }

        this.openModal('publish');
    }

    openModal(type) {
        // Close the create-menu dropdown first
        if (this.hasCreateMenuTarget) {
            this.createMenuTarget.hidden = true;
        }

        if (type === 'publish') {
            this.publishModalTarget.hidden = false;
        } else if (type === 'upload') {
            this.uploadModalTarget.hidden = false;
        }
    }

    closeModal() {
        this.publishModalTarget.hidden = true;
        if (this.hasUploadModalTarget) {
            this.uploadModalTarget.hidden = true;
        }
    }

    // --- Utilities ---
    formatDuration(seconds) {
        const mins = Math.floor(seconds / 60);
        const secs = Math.floor(seconds % 60);
        return `${mins}:${secs.toString().padStart(2, '0')}`;
    }

    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

