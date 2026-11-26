import { Controller } from '@hotwired/stimulus';

const PREFIX = 'article-recovery:v1:';

export default class extends Controller {
    static targets = ['status', 'list'];
    static values = { owner: String, url: String, token: String, labels: Object };

    connect() {
        this.id = crypto.randomUUID();
        this.remote = new Map();
        this.pending = new Map();
        this.sending = false;
        this.restoring = false;
        this.form = this.element.querySelector('#editor-form');
        this.onChange = () => {
            if (this.restoring) return;
            if (!this.localTimer) this.localTimer = setTimeout(() => this.saveSnapshot(), 200);
        };
        this.onClick = (event) => {
            if (event.target.closest('[data-action*="content--advanced-metadata#"]')) {
                this.onChange();
            }
        };
        this.onPageHide = () => {
            if (this.localTimer) {
                clearTimeout(this.localTimer);
                this.saveSnapshot();
            }
            this.flushServer();
        };
        this.onOnline = () => this.saveSnapshot(true);
        this.element.addEventListener('input', this.onChange);
        this.element.addEventListener('change', this.onChange);
        this.element.addEventListener('content:changed', this.onChange);
        this.element.addEventListener('click', this.onClick);
        window.addEventListener('pagehide', this.onPageHide);
        window.addEventListener('online', this.onOnline);
        this.refresh();
    }

    disconnect() {
        this.onPageHide();
        clearTimeout(this.serverTimer);
        this.flushServer();
        this.element.removeEventListener('input', this.onChange);
        this.element.removeEventListener('change', this.onChange);
        this.element.removeEventListener('content:changed', this.onChange);
        this.element.removeEventListener('click', this.onClick);
        window.removeEventListener('pagehide', this.onPageHide);
        window.removeEventListener('online', this.onOnline);
    }

    get prefix() {
        return `${PREFIX}${this.ownerValue}:`;
    }

    capture() {
        const layout = this.application.getControllerForElementAndIdentifier(this.element, 'editor--layout');
        if (!layout?.state || !this.form) throw new Error('Editor is not ready');
        const state = structuredClone(layout.state);
        const markdown = this.form.querySelector('textarea[name="editor[content]"]');
        if (state.active_source === 'md' && markdown) {
            state.content_NMD = markdown._codemirror?.state.doc.toString() ?? markdown.value;
        }
        const fields = {};
        for (const field of this.form.elements) {
            if (!this.restorableField(field.name)
                || ['file', 'submit', 'button'].includes(field.type)) continue;
            fields[field.name] = field.type === 'checkbox' || field.type === 'radio'
                ? { checked: field.checked, value: field.value }
                : { value: field.value };
        }
        const json = this.element.querySelector('#json-editor-textarea');
        const jsonController = json && this.application.getControllerForElementAndIdentifier(
            json.closest('[data-controller~="nostr--nostr-publish"]'), 'nostr--nostr-publish'
        );
        return {
            version: 1,
            fields,
            editorState: state,
            json: jsonController?.getCurrentJson() ?? '',
            jsonEdited: !!jsonController?.jsonEdited,
            timestamp: Date.now(),
        };
    }

    saveNow() {
        clearTimeout(this.localTimer);
        this.saveSnapshot(true);
    }

    restorableField(name) {
        return typeof name === 'string' && name.startsWith('editor[')
            && !/\[(?:_token|pubkey|loginMethod)\]$/.test(name);
    }

    saveSnapshot(immediate = false) {
        this.localTimer = null;
        let snapshot;
        try {
            snapshot = this.capture();
        } catch (error) {
            console.error('[Article recovery] Cannot capture editor state', error);
            this.statusTarget.textContent = this.labelsValue.browser_failed;
            return false;
        }
        this.browserSaved = false;
        try {
            localStorage.setItem(this.prefix + this.id, JSON.stringify(snapshot));
            this.browserSaved = true;
            this.statusTarget.textContent = this.urlValue ? this.labelsValue.saved_browser : this.labelsValue.saved_local_only;
        } catch (error) {
            console.error('[Article recovery] Browser storage failed', error);
            this.statusTarget.textContent = this.labelsValue.browser_failed;
        }
        this.renderList();
        if (this.urlValue) {
            this.pending.set(this.id, snapshot);
            if (immediate) clearTimeout(this.serverTimer);
            if (immediate || !this.serverTimer) {
                this.serverTimer = setTimeout(() => {
                    this.serverTimer = null;
                    this.flushServer();
                }, immediate ? 0 : 2000);
            }
        }
        return this.browserSaved;
    }

    async flushServer() {
        if (this.sending || !this.pending.size) return;
        this.sending = true;
        const [id, snapshot] = this.pending.entries().next().value;
        const entry = { id, snapshot };
        this.sendingId = entry.id;
        this.pending.delete(id);
        try {
            const response = await fetch(`${this.urlValue}/${entry.id}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.tokenValue },
                body: JSON.stringify(entry.snapshot),
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            this.remote.set(entry.id, entry.snapshot);
            if (entry.id === this.id && !this.pending.has(entry.id)) {
                this.statusTarget.textContent = this.browserSaved
                    ? this.labelsValue.saved_both : this.labelsValue.browser_failed;
            }
            this.renderList();
        } catch (error) {
            console.error('[Article recovery] Server save failed', error);
            this.statusTarget.textContent = this.labelsValue.server_failed;
        } finally {
            this.sending = false;
            this.sendingId = null;
            if (this.pending.size) this.flushServer();
        }
    }

    async refresh() {
        this.renderList();
        if (!this.urlValue) return;
        try {
            const response = await fetch(this.urlValue, { cache: 'no-store' });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const copies = await response.json();
            for (const copy of copies) {
                if (!this.remote.has(copy.id)) this.remote.set(copy.id, copy.snapshot);
            }
            this.renderList();
        } catch (error) {
            console.error('[Article recovery] Server copies unavailable', error);
            this.statusTarget.textContent = this.labelsValue.server_failed;
        }
    }

    browserCopies() {
        const copies = new Map();
        try {
            for (let i = 0; i < localStorage.length; i++) {
                const key = localStorage.key(i);
                if (!key?.startsWith(this.prefix)) continue;
                try {
                    const snapshot = JSON.parse(localStorage.getItem(key));
                    if (this.valid(snapshot)) copies.set(key.slice(this.prefix.length), snapshot);
                } catch (error) {
                    console.error('[Article recovery] Invalid browser copy', error);
                    this.statusTarget.textContent = this.labelsValue.invalid_file;
                }
            }
        } catch (error) {
            console.error('[Article recovery] Browser copies unavailable', error);
            this.statusTarget.textContent = this.labelsValue.browser_failed;
        }
        return copies;
    }

    valid(snapshot) {
        return snapshot?.version === 1 && snapshot.fields && typeof snapshot.fields === 'object'
            && snapshot.editorState && typeof snapshot.editorState === 'object';
    }

    renderList() {
        const local = this.browserCopies();
        const ids = new Set([...local.keys(), ...this.remote.keys()]);
        this.listTarget.replaceChildren();
        for (const id of ids) {
            for (const [source, snapshot] of [['browser', local.get(id)], ['server', this.remote.get(id)]]) {
                if (!this.valid(snapshot)) continue;
                const item = document.createElement('li');
                const title = snapshot.fields['editor[title]']?.value || this.labelsValue.untitled;
                const date = new Date(snapshot.timestamp).toLocaleString();
                item.append(document.createTextNode(`${title} — ${date} (${this.labelsValue[source]}) `));
                const restore = document.createElement('button');
                restore.type = 'button';
                restore.textContent = this.labelsValue.restore;
                restore.addEventListener('click', () => this.restore(snapshot));
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.textContent = this.labelsValue.delete;
                remove.addEventListener('click', () => this.deleteCopy(id, source));
                item.append(restore, remove);
                this.listTarget.append(item);
            }
        }
    }

    restore(snapshot) {
        if (!this.valid(snapshot) || !window.confirm(this.labelsValue.confirm_restore)) return;
        // Preserve the work currently in the editor before replacing it.
        clearTimeout(this.localTimer);
        if (!this.saveSnapshot(true)) return;
        this.id = crypto.randomUUID();
        this.restoring = true;
        try {
            const layout = this.application.getControllerForElementAndIdentifier(this.element, 'editor--layout');
            this.restoreCollections(snapshot.fields);
            for (const [name, value] of Object.entries(snapshot.fields)) {
                if (!this.restorableField(name)) continue;
                const field = this.form.elements.namedItem(name);
                if (!field || !value || typeof value !== 'object') continue;
                if ('checked' in value) field.checked = !!value.checked;
                else if (typeof value.value === 'string') field.value = value.value;
            }
            layout.state = structuredClone(snapshot.editorState);
            if (layout.state.active_source === 'md') {
                layout.state.content_delta = layout.safeNmdToDelta(layout.state.content_NMD, {
                    context: 'recovery', fallbackMarkdown: layout.state.content_NMD, fallbackToPlainTextDelta: true,
                });
            }
            layout.updateMarkdownEditor();
            layout.updateQuillEditor();
            layout.syncTitleToMarkdown();
            const json = this.element.querySelector('#json-editor-textarea');
            const jsonController = json && this.application.getControllerForElementAndIdentifier(
                json.closest('[data-controller~="nostr--nostr-publish"]'), 'nostr--nostr-publish'
            );
            if (json && typeof snapshot.json === 'string') {
                json.value = snapshot.json;
                jsonController?.cmView?.dispatch({
                    changes: { from: 0, to: jsonController.cmView.state.doc.length, insert: snapshot.json },
                });
                if (jsonController) jsonController.jsonEdited = !!snapshot.jsonEdited;
            }
            layout.updatePreview();
        } catch (error) {
            console.error('[Article recovery] Restore failed', error);
            this.statusTarget.textContent = this.labelsValue.invalid_file;
            return;
        } finally {
            this.restoring = false;
        }
        this.saveSnapshot(true);
    }

    restoreCollections(fields) {
        const element = this.element.querySelector('[data-controller~="content--advanced-metadata"]');
        const controller = element && this.application.getControllerForElementAndIdentifier(element, 'content--advanced-metadata');
        if (!controller) return;
        for (const [name, target, method] of [
            ['zapSplits', 'zapSplitsContainerTarget', 'addZapSplit'],
            ['sources', 'sourcesContainerTarget', 'addSource'],
            ['mediaAttachments', 'mediaAttachmentsContainerTarget', 'addMediaAttachment'],
        ]) {
            const container = controller[target];
            container.replaceChildren();
            const expression = new RegExp(`^editor\\[advancedMetadata\\]\\[${name}\\]\\[(\\d+)\\]`);
            const indices = new Set(Object.keys(fields).map((key) => key.match(expression)?.[1]).filter(Boolean));
            for (const index of indices) {
                container.dataset.index = index;
                controller[method]({ preventDefault() {} });
            }
            container.dataset.index = String(Math.max(-1, ...[...indices].map(Number)) + 1);
        }
    }

    async deleteCopy(id, source) {
        if (!window.confirm(this.labelsValue.confirm_delete)) return;
        if (source === 'server' && (this.sendingId === id || this.pending.has(id))) {
            this.statusTarget.textContent = this.labelsValue.server_failed;
            return;
        }
        try {
            if (source === 'server') {
                const response = await fetch(`${this.urlValue}/${id}`, {
                    method: 'DELETE', headers: { 'X-CSRF-Token': this.tokenValue },
                });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                this.remote.delete(id);
            } else {
                localStorage.removeItem(this.prefix + id);
            }
            if (id === this.id) this.id = crypto.randomUUID();
            this.renderList();
        } catch (error) {
            console.error('[Article recovery] Delete failed', error);
            this.statusTarget.textContent = source === 'server' ? this.labelsValue.server_failed : this.labelsValue.browser_failed;
        }
    }

    exportCopy() {
        try {
            const blob = new Blob([JSON.stringify(this.capture(), null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'article-recovery.json';
            link.click();
            setTimeout(() => URL.revokeObjectURL(url), 0);
        } catch (error) {
            console.error('[Article recovery] Export failed', error);
            this.statusTarget.textContent = this.labelsValue.invalid_file;
        }
    }

    async importCopy(event) {
        try {
            const file = event.target.files[0];
            if (!file || file.size > 2 * 1024 * 1024) throw new Error('Invalid file size');
            const snapshot = JSON.parse(await file.text());
            if (!this.valid(snapshot)) throw new Error('Invalid recovery copy');
            this.restore(snapshot);
        } catch (error) {
            console.error('[Article recovery] Import failed', error);
            this.statusTarget.textContent = this.labelsValue.invalid_file;
        } finally {
            event.target.value = '';
        }
    }
}
