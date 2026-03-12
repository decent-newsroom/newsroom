import { Controller } from '@hotwired/stimulus';
import { getSigner } from '../nostr/signer_manager.js';

/**
 * Standalone Upload Controller
 *
 * Upload images/videos to a provider *without* creating a kind 20/21/22 event.
 * Uses the same NIP-98 + backend-proxy flow as the existing image_upload_controller,
 * but supports multi-file uploads and shows a results list with copy-able URLs.
 */
export default class extends Controller {
    static targets = ['provider', 'dropzone', 'fileInput', 'progress', 'error', 'results',
                      'previousUploads', 'loadMoreBtn'];

    connect() {
        this.uploadedItems = [];
        this.previousOffset = 0;
        this.previousLimit = 20;
        this.loadPreviousUploads();
    }

    // -- helpers -------------------------------------------------------

    base64Encode(str) {
        try { return btoa(unescape(encodeURIComponent(str))); }
        catch (_) { return btoa(str); }
    }

    esc(text) {
        if (!text) return '';
        const d = document.createElement('div');
        d.textContent = text;
        return d.innerHTML;
    }

    showProgress(msg) {
        this.progressTarget.textContent = msg;
        this.progressTarget.hidden = false;
    }

    hideProgress() {
        this.progressTarget.hidden = true;
        this.progressTarget.textContent = '';
    }

    showError(msg) {
        this.errorTarget.textContent = msg;
        this.errorTarget.hidden = false;
        this.hideProgress();
    }

    clearError() {
        this.errorTarget.textContent = '';
        this.errorTarget.hidden = true;
    }

    // -- file picking / drag-drop --------------------------------------

    browseFiles() { this.fileInputTarget.click(); }

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

    // -- upload logic --------------------------------------------------

    async uploadFiles(files) {
        const provider = (this.providerTarget.value || '').trim();
        if (!provider) { this.showError('Please select a provider.'); return; }

        this.clearError();

        // NIP-98: get signer + pubkey
        let signer, pubkey;
        try {
            signer = await getSigner();
            pubkey = await signer.getPublicKey();
        } catch (e) {
            this.showError('No Nostr signer available: ' + e.message);
            return;
        }

        // Provider → upstream URL (for the NIP-98 "u" tag)
        const upstreamMap = {
            nostrbuild:  'https://nostr.build/nip96/upload',
            nostrcheck:  'https://nostrcheck.me/api/v2/media',
            sovbit:      'https://files.sovbit.host/api/v2/media',
            blossomband: 'https://blossom.band/upload',
        };
        const upstreamEndpoint = upstreamMap[provider] || upstreamMap['nostrcheck'];
        const proxyEndpoint    = `/api/image-upload/${provider}`;

        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            this.showProgress(`Uploading ${file.name} (${i + 1}/${files.length})…`);

            try {
                // Sign a fresh NIP-98 auth event for each file
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
                    this.uploadedItems.push({ name: file.name, url: result.url });
                } else {
                    this.uploadedItems.push({
                        name: file.name,
                        error: result.message || `Upload failed (HTTP ${res.status})`,
                    });
                }
            } catch (err) {
                this.uploadedItems.push({ name: file.name, error: err.message });
            }
        }

        this.hideProgress();
        this.fileInputTarget.value = '';
        this.renderResults();

        // Refresh the "Your uploads" list so the newly saved items appear
        this.previousOffset = 0;
        this.loadPreviousUploads();
    }

    // -- results -------------------------------------------------------

    renderResults() {
        if (!this.uploadedItems.length) {
            this.resultsTarget.innerHTML = '';
            return;
        }

        const rows = this.uploadedItems.map(item => {
            if (item.error) {
                return `<div class="upload-result-row upload-result-row--error">
                    <span class="upload-result-row__name">${this.esc(item.name)}</span>
                    <span class="upload-result-row__msg">✗ ${this.esc(item.error)}</span>
                </div>`;
            }

            return `<div class="upload-result-row upload-result-row--ok">
                <span class="upload-result-row__name">${this.esc(item.name)}</span>
                <input type="text" readonly class="upload-result-row__url" value="${this.esc(item.url)}">
                <button type="button" class="btn btn--small"
                        data-action="click->media--standalone-upload#copyUrl"
                        data-url="${this.esc(item.url)}">
                    Copy
                </button>
            </div>`;
        }).join('');

        this.resultsTarget.innerHTML = rows;
    }

    copyUrl(event) {
        const url = event.currentTarget.dataset.url;
        if (!url) return;
        navigator.clipboard.writeText(url).then(() => {
            event.currentTarget.textContent = 'Copied!';
            setTimeout(() => { event.currentTarget.textContent = 'Copy'; }, 2000);
        });
    }

    // -- previous uploads -----------------------------------------------

    async loadPreviousUploads() {
        if (!this.hasPreviousUploadsTarget) return;

        try {
            const params = new URLSearchParams({
                limit: String(this.previousLimit),
                offset: String(this.previousOffset),
            });
            const res = await fetch(`/api/user-uploads?${params}`);
            if (!res.ok) {
                this.previousUploadsTarget.innerHTML = '<p class="previous-uploads__empty">Sign in to see your uploads.</p>';
                return;
            }

            const data = await res.json();
            const uploads = data.uploads || [];
            const total = data.total || 0;

            if (this.previousOffset === 0 && !uploads.length) {
                this.previousUploadsTarget.innerHTML = '<p class="previous-uploads__empty">No uploads yet.</p>';
                return;
            }

            const html = uploads.map(u => this.renderPreviousRow(u)).join('');

            if (this.previousOffset === 0) {
                this.previousUploadsTarget.innerHTML = html;
            } else {
                this.previousUploadsTarget.insertAdjacentHTML('beforeend', html);
            }

            this.previousOffset += uploads.length;

            // Show/hide "load more"
            if (this.hasLoadMoreBtnTarget) {
                this.loadMoreBtnTarget.hidden = this.previousOffset >= total;
            }
        } catch (e) {
            console.error('Failed to load previous uploads', e);
            this.previousUploadsTarget.innerHTML = '<p class="previous-uploads__empty">Failed to load uploads.</p>';
        }
    }

    loadMorePrevious() {
        this.loadPreviousUploads();
    }

    renderPreviousRow(upload) {
        const isImg = (upload.mime_type || '').startsWith('image/');
        const date = upload.created_at ? new Date(upload.created_at).toLocaleDateString() : '';
        const name = upload.original_filename || '—';

        return `<div class="upload-result-row upload-result-row--previous">
            ${isImg
                ? `<img src="${this.esc(upload.url)}" alt="" class="previous-uploads__thumb" loading="lazy" />`
                : `<span class="previous-uploads__icon">${'\u{1F3AC}'}</span>`}
            <span class="upload-result-row__name" title="${this.esc(name)}">${this.esc(this.truncate(name, 20))}</span>
            <input type="text" readonly class="upload-result-row__url" value="${this.esc(upload.url)}">
            <span class="previous-uploads__date">${date}</span>
            <button type="button" class="btn btn--small"
                    data-action="click->media--standalone-upload#copyUrl"
                    data-url="${this.esc(upload.url)}">
                Copy
            </button>
        </div>`;
    }

    truncate(str, max) {
        if (!str || str.length <= max) return str || '';
        return str.substring(0, max - 1) + '\u2026';
    }
}

