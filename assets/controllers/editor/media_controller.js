import { Controller } from '@hotwired/stimulus';

/**
 * Editor Media Panel Controller
 *
 * Provides three sections in the editor's left sidebar:
 *  1. Upload an image (delegated to publishing--image-upload) and insert at cursor
 *  2. Browse your previously uploaded files (from DB)
 *  3. Browse your media posts (kinds 20, 21, 22 from DB)
 *
 * Clicking any item inserts a Markdown image/link at the current cursor
 * position in either the Quill rich-text or CodeMirror markdown editor.
 */
export default class extends Controller {
    static targets = [
        'uploadsList', 'loadMoreUploads',
        'postsList', 'loadMorePosts',
    ];

    connect() {
        this.uploadsOffset = 0;
        this.uploadsLimit = 20;
        this.postsOffset = 0;
        this.postsLimit = 20;

        this.loadUploads();
        this.loadPosts();
    }

    // =====================================================================
    //  Helpers
    // =====================================================================

    esc(str) {
        const d = document.createElement('div');
        d.textContent = str || '';
        return d.innerHTML;
    }

    truncate(str, max) {
        if (!str || str.length <= max) return str || '';
        return str.substring(0, max - 1) + '\u2026';
    }

    // =====================================================================
    //  Insert into editor (mode-aware, Quill or CodeMirror)
    // =====================================================================

    _insertIntoEditor(text, imageData = null) {
        const mdPane = document.querySelector('.editor-pane--markdown');
        const isMdMode = mdPane && !mdPane.classList.contains('is-hidden');

        if (isMdMode) {
            const textarea = document.querySelector('textarea[name="editor[content]"]');
            const cm = textarea && textarea._codemirror;
            if (cm) {
                const cursor = cm.state.selection.main.head;
                cm.dispatch({ changes: { from: cursor, insert: text } });
            } else if (textarea) {
                const start = textarea.selectionStart || textarea.value.length;
                textarea.value = textarea.value.slice(0, start) + text + textarea.value.slice(start);
                textarea.selectionStart = textarea.selectionEnd = start + text.length;
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
            }
        } else if (window.appQuill) {
            const quill = window.appQuill;
            const range = quill.getSelection(true);
            const index = range ? range.index : quill.getLength() - 1;

            if (imageData && imageData.src) {
                // Insert as a visual image embed in Quill
                quill.insertEmbed(index, 'imageAlt', { src: imageData.src, alt: imageData.alt || '' }, 'user');
                quill.setSelection(index + 1, 0, 'user');
            } else {
                quill.insertText(index, text, 'user');
                quill.setSelection(index + text.length, 0, 'user');
            }
        }
    }

    // =====================================================================
    //  1. Upload success handler (event from publishing--image-upload)
    // =====================================================================

    onUploadSuccess(event) {
        const { url, filename } = event.detail;
        if (url) {
            const alt = (filename || 'image').replace(/\.[^.]+$/, '');
            this._insertIntoEditor(`\n![${alt}](${url})\n`, { src: url, alt });

            // Refresh "Your uploads" list
            this.uploadsOffset = 0;
            this.loadUploads();
        }
    }

    // =====================================================================
    //  2. Your Uploads (from /api/user-uploads)
    // =====================================================================

    async loadUploads() {
        if (!this.hasUploadsListTarget) return;

        try {
            const params = new URLSearchParams({ limit: String(this.uploadsLimit), offset: String(this.uploadsOffset) });
            const res = await fetch(`/api/user-uploads?${params}`);
            if (!res.ok) {
                this.uploadsListTarget.innerHTML = '<p class="media-panel-empty">Sign in to see uploads.</p>';
                return;
            }

            const json = await res.json();
            const uploads = json.uploads || [];
            const total = json.total || 0;

            if (this.uploadsOffset === 0 && !uploads.length) {
                this.uploadsListTarget.innerHTML = '<p class="media-panel-empty">No uploads yet.</p>';
                if (this.hasLoadMoreUploadsTarget) this.loadMoreUploadsTarget.hidden = true;
                return;
            }

            const html = uploads.map(u => this._renderUploadItem(u)).join('');
            if (this.uploadsOffset === 0) {
                this.uploadsListTarget.innerHTML = html;
            } else {
                this.uploadsListTarget.insertAdjacentHTML('beforeend', html);
            }

            this.uploadsOffset += uploads.length;
            if (this.hasLoadMoreUploadsTarget) this.loadMoreUploadsTarget.hidden = this.uploadsOffset >= total;
        } catch (e) {
            console.error('[editor-media] loadUploads error', e);
        }
    }

    loadMoreUploads() { this.loadUploads(); }

    _renderUploadItem(u) {
        const isImg = (u.mime_type || '').startsWith('image/');
        const name = u.original_filename || '—';
        const preview = isImg
            ? `<img src="${this.esc(u.url)}" alt="" class="media-panel-thumb" loading="lazy" />`
            : `<span class="media-panel-icon">\u{1F3AC}</span>`;

        return `<button type="button" class="media-panel-item"
                    data-action="click->editor--media#insertUpload"
                    data-url="${this.esc(u.url)}"
                    data-name="${this.esc(name)}"
                    title="${this.esc(u.url)}">
            ${preview}
            <span class="media-panel-item__label">${this.esc(this.truncate(name, 22))}</span>
        </button>`;
    }

    insertUpload(event) {
        const btn = event.currentTarget;
        const url = btn.dataset.url;
        const name = (btn.dataset.name || 'image').replace(/\.[^.]+$/, '');
        if (url) this._insertIntoEditor(`\n![${name}](${url})\n`, { src: url, alt: name });
    }

    // =====================================================================
    //  3. Media Posts (from /api/user-media-posts)
    // =====================================================================

    async loadPosts() {
        if (!this.hasPostsListTarget) return;

        try {
            const params = new URLSearchParams({ limit: String(this.postsLimit), offset: String(this.postsOffset) });
            const res = await fetch(`/api/user-media-posts?${params}`);
            if (!res.ok) {
                this.postsListTarget.innerHTML = '<p class="media-panel-empty">Sign in to see media posts.</p>';
                return;
            }

            const json = await res.json();
            const posts = json.posts || [];
            const total = json.total || 0;

            if (this.postsOffset === 0 && !posts.length) {
                this.postsListTarget.innerHTML = '<p class="media-panel-empty">No media posts found.</p>';
                if (this.hasLoadMorePostsTarget) this.loadMorePostsTarget.hidden = true;
                return;
            }

            const html = posts.map(p => this._renderPostItem(p)).join('');
            if (this.postsOffset === 0) {
                this.postsListTarget.innerHTML = html;
            } else {
                this.postsListTarget.insertAdjacentHTML('beforeend', html);
            }

            this.postsOffset += posts.length;
            if (this.hasLoadMorePostsTarget) this.loadMorePostsTarget.hidden = this.postsOffset >= total;
        } catch (e) {
            console.error('[editor-media] loadPosts error', e);
        }
    }

    loadMorePosts() { this.loadPosts(); }

    _renderPostItem(p) {
        const kindLabels = { 20: 'Picture', 21: 'Video', 22: 'Short', 34235: 'Video', 34236: 'Short' };
        const kindLabel = kindLabels[p.kind] || `kind ${p.kind}`;
        const title = p.title || p.content || 'Untitled';
        const thumb = p.preview_url || p.primary_url;
        const isVideo = [21, 22, 34235, 34236].includes(p.kind);
        const date = p.created_at ? new Date(p.created_at * 1000).toLocaleDateString() : '';

        const preview = thumb
            ? `<img src="${this.esc(thumb)}" alt="" class="media-panel-thumb" loading="lazy" />`
            : `<span class="media-panel-icon">${isVideo ? '\u{1F3AC}' : '\u{1F5BC}'}</span>`;

        return `<button type="button" class="media-panel-item"
                    data-action="click->editor--media#insertPost"
                    data-url="${this.esc(p.primary_url || '')}"
                    data-title="${this.esc(title)}"
                    title="${this.esc(p.primary_url || '')}">
            ${preview}
            <span class="media-panel-item__info">
                <span class="media-panel-item__label">${this.esc(this.truncate(title, 24))}</span>
                <span class="media-panel-item__meta">${kindLabel} · ${date}</span>
            </span>
        </button>`;
    }

    insertPost(event) {
        const btn = event.currentTarget;
        const url = btn.dataset.url;
        const title = (btn.dataset.title || 'media').replace(/\n/g, ' ');
        if (url) this._insertIntoEditor(`\n![${title}](${url})\n`, { src: url, alt: title });
    }
}

