import { Controller } from '@hotwired/stimulus';
import { getSigner } from '../nostr/signer_manager.js';

/**
 * Media Publish Controller
 *
 * Combined upload + publish flow for creating media events (kinds 20, 21, 22).
 *
 * Step 1 — Add media: upload files to a provider (NIP-98 auth + backend proxy), or paste a URL.
 * Step 2 — Fill in metadata (title, description, hashtags, etc.).
 * Step 3 — Build an unsigned event draft, then sign & publish.
 */
export default class extends Controller {
    static targets = [
        // Upload / add media
        'dropzone', 'fileInput', 'uploadProgress', 'uploadFill', 'uploadText',
        'urlInput', 'providerSelect', 'mediaList',
        // Metadata form
        'title', 'content', 'alt', 'hashtags', 'clientTag',
        // Actions / result
        'submitBtn', 'draftResult', 'draftJson'
    ];

    static values = {
        kind: { type: Number, default: 20 },
    };

    // Provider → upstream endpoint (for NIP-98 "u" tag)
    static UPSTREAM_MAP = {
        nostrbuild:  'https://nostr.build/nip96/upload',
        nostrcheck:  'https://nostrcheck.me/api/v2/media',
        sovbit:      'https://files.sovbit.host/api/v2/media',
        blossomband: 'https://blossom.band/upload',
    };

    connect() {
        this.mediaItems = [];
        this.draft = null;
    }

    // Unicode-safe base64 encoder (same as image_upload_controller)
    base64Encode(str) {
        try { return btoa(unescape(encodeURIComponent(str))); }
        catch (_) { return btoa(str); }
    }

    // =====================================================================
    //  File upload via drag-drop / file picker
    // =====================================================================

    browseFiles() {
        this.fileInputTarget.click();
    }

    filesSelected(event) {
        const files = event.target.files;
        if (files.length) this.uploadFiles(Array.from(files));
    }

    dragover(e)  { e.preventDefault(); this.dropzoneTarget.classList.add('upload-dropzone--active'); }
    dragenter(e) { e.preventDefault(); this.dropzoneTarget.classList.add('upload-dropzone--active'); }
    dragleave(e) { e.preventDefault(); this.dropzoneTarget.classList.remove('upload-dropzone--active'); }

    drop(event) {
        event.preventDefault();
        this.dropzoneTarget.classList.remove('upload-dropzone--active');
        const files = event.dataTransfer?.files;
        if (files && files.length) this.uploadFiles(Array.from(files));
    }

    async uploadFiles(files) {
        const provider = (this.providerSelectTarget.value || '').trim();
        if (!provider) {
            alert('Please select an upload provider first.');
            return;
        }

        // NIP-98: get signer + pubkey
        let signer, pubkey;
        try {
            signer = await getSigner();
            pubkey = await signer.getPublicKey();
        } catch (e) {
            alert('No Nostr signer available: ' + e.message);
            return;
        }

        const upstreamEndpoint = this.constructor.UPSTREAM_MAP[provider]
                              || this.constructor.UPSTREAM_MAP['nostrcheck'];
        const proxyEndpoint = `/api/image-upload/${provider}`;

        this.uploadProgressTarget.hidden = false;

        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            this.uploadTextTarget.textContent = `Uploading ${file.name} (${i + 1}/${files.length})…`;
            this.uploadFillTarget.style.width = `${((i) / files.length) * 100}%`;

            try {
                // Sign a fresh NIP-98 event per file
                const authEvent = {
                    kind: 27235,
                    created_at: Math.floor(Date.now() / 1000),
                    pubkey,
                    tags: [['u', upstreamEndpoint], ['method', 'POST']],
                    content: '',
                };
                const signed = await signer.signEvent(authEvent);
                const authHeader = 'Nostr ' + this.base64Encode(JSON.stringify(signed));

                const formData = new FormData();
                formData.append('uploadtype', 'media');
                formData.append('file', file);

                const res = await fetch(proxyEndpoint, {
                    method: 'POST',
                    headers: { 'Authorization': authHeader },
                    body: formData,
                });
                const result = await res.json().catch(() => ({}));

                if (res.ok && result.status === 'success' && result.url) {
                    this.addMediaItem({
                        url:  result.url,
                        mime: file.type || null,
                    });
                } else {
                    alert(`Upload failed for ${file.name}: ${result.message || 'unknown error'}`);
                }
            } catch (err) {
                alert(`Upload error for ${file.name}: ${err.message}`);
            }
        }

        this.uploadFillTarget.style.width = '100%';
        this.uploadTextTarget.textContent = 'Done!';
        setTimeout(() => { this.uploadProgressTarget.hidden = true; }, 1500);

        this.fileInputTarget.value = '';
    }

    // =====================================================================
    //  Add by URL
    // =====================================================================

    addUrl() {
        const url = (this.urlInputTarget.value || '').trim();
        if (!url) return;

        // Guess MIME from extension
        const ext = (url.split('?')[0].split('.').pop() || '').toLowerCase();
        const mimeMap = {
            jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png',
            gif: 'image/gif', webp: 'image/webp', avif: 'image/avif',
            svg: 'image/svg+xml',
            mp4: 'video/mp4', webm: 'video/webm', mov: 'video/quicktime',
            ogg: 'video/ogg', mkv: 'video/x-matroska',
        };

        this.addMediaItem({
            url,
            mime: mimeMap[ext] || null,
        });

        this.urlInputTarget.value = '';
    }

    // =====================================================================
    //  Media item list management
    // =====================================================================

    addMediaItem(item) {
        this.mediaItems.push(item);
        this.renderMediaList();
    }

    removeMedia(event) {
        const idx = Number(event.currentTarget.dataset.index);
        this.mediaItems.splice(idx, 1);
        this.renderMediaList();
    }

    renderMediaList() {
        if (!this.mediaItems.length) {
            this.mediaListTarget.innerHTML = '<p class="publish-media-list__empty">No media added yet.</p>';
            this.submitBtnTarget.disabled = true;
            return;
        }

        this.submitBtnTarget.disabled = false;

        const rows = this.mediaItems.map((item, i) => {
            const isImg = (item.mime || '').startsWith('image/');
            const preview = item.thumb_url || item.url;
            const previewHtml = isImg && preview
                ? `<img src="${this.esc(preview)}" alt="" class="publish-media-item__preview" />`
                : `<span class="publish-media-item__icon">${isImg ? '\u{1F5BC}' : '\u{1F3AC}'}</span>`;

            return `<div class="publish-media-item">
                ${previewHtml}
                <span class="publish-media-item__url" title="${this.esc(item.url)}">${this.esc(this.truncate(item.url, 40))}</span>
                <span class="publish-media-item__mime">${item.mime || '?'}</span>
                <button type="button" class="publish-media-item__remove"
                        data-action="click->media--media-publish#removeMedia"
                        data-index="${i}"
                        title="Remove">&times;</button>
            </div>`;
        }).join('');

        this.mediaListTarget.innerHTML = rows;
    }

    // =====================================================================
    //  Build event draft
    // =====================================================================

    async publish() {
        if (!this.mediaItems.length) {
            alert('Add at least one image or video first.');
            return;
        }

        const title = this.titleTarget.value.trim();
        if (!title) {
            alert('Title is required.');
            return;
        }

        const hashtagsStr = this.hashtagsTarget.value.trim();
        const hashtags = hashtagsStr ? hashtagsStr.split(',').map(h => h.trim()).filter(Boolean) : [];

        const kindEndpoints = { 20: '/api/media/publish/picture', 21: '/api/media/publish/video', 22: '/api/media/publish/short-video' };
        const endpoint = kindEndpoints[this.kindValue];
        if (!endpoint) { alert('Invalid media kind'); return; }

        this.submitBtnTarget.disabled = true;
        this.submitBtnTarget.textContent = 'Building draft…';

        try {
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    pubkey: '',
                    title,
                    content: this.contentTarget.value.trim(),
                    alt: this.altTarget.value.trim() || null,
                    hashtags,
                    add_client_tag: this.clientTagTarget.checked,
                    media_items: this.mediaItems.map(item => ({
                        url: item.url,
                        mime: item.mime,
                        sha256: item.sha256 || null,
                        original_sha256: item.original_sha256 || null,
                        size: item.size || null,
                        dimensions: item.dimensions || null,
                        duration: item.duration || null,
                        bitrate: item.bitrate || null,
                        alt: item.alt || null,
                        blurhash: item.blurhash || null,
                        thumb_url: item.thumb_url || null,
                        preview_images: item.preview_images || [],
                        fallback_urls: item.fallback_urls || [],
                    })),
                }),
            });

            const data = await res.json();

            if (data.status === 'success' && data.draft) {
                this.draft = data.draft;
                this.draftJsonTarget.textContent = JSON.stringify(data.draft, null, 2);
                this.draftResultTarget.hidden = false;
                if (data.draft.warnings) alert('Warning: ' + data.draft.warnings.join('\n'));
            } else {
                alert('Error: ' + (data.error || 'Failed to build draft'));
            }
        } catch (err) {
            console.error('Publish error:', err);
            alert('Failed to build event draft: ' + err.message);
        } finally {
            this.submitBtnTarget.disabled = false;
            this.submitBtnTarget.textContent = 'Build Event Draft';
        }
    }

    // =====================================================================
    //  Sign & publish
    // =====================================================================

    async signAndPublish() {
        if (!this.draft) { alert('No draft available'); return; }

        this.dispatch('signAndPublish', {
            detail: { draft: this.draft, kind: this.kindValue }
        });
    }

    // =====================================================================
    //  Helpers
    // =====================================================================

    esc(text) {
        if (!text) return '';
        const d = document.createElement('div');
        d.textContent = text;
        return d.innerHTML;
    }

    truncate(str, max) {
        if (!str || str.length <= max) return str || '';
        return str.substring(0, max - 1) + '…';
    }
}
