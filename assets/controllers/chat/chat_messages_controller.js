import { Controller } from '@hotwired/stimulus';

/**
 * Chat messages controller — handles sending, receiving (via Mercure SSE),
 * and loading older messages from the relay via the backend API.
 *
 * Supports two signing modes:
 * - custodial: server signs events (POST plaintext to sendUrl)
 * - self-sovereign: client signs kind-42 events via NIP-07/NIP-46 (POST signed JSON to signedSendUrl)
 *
 * data-controller="chat--messages"
 */
export default class extends Controller {
    static values = {
        groupSlug: String,
        communityId: String,
        sendUrl: String,
        signedSendUrl: String,
        historyUrl: String,
        currentPubkey: String,
        signingMode: { type: String, default: 'custodial' },
        channelEventId: String,
        relayUrl: String,
    };

    static targets = ['messageList', 'input', 'sendButton', 'loadMore'];

    connect() {
        this.scrollToBottom();

        // Subscribe to Mercure SSE if hub URL is available
        const hubMeta = document.querySelector('meta[name="mercure-hub"]');
        if (hubMeta) {
            const hubUrl = new URL(hubMeta.content);
            const topic = `/chat/${this.communityIdValue}/group/${this.groupSlugValue}`;
            hubUrl.searchParams.append('topic', topic);

            this.eventSource = new EventSource(hubUrl.toString());
            this.eventSource.onmessage = (event) => this.onMercureMessage(event);
        }
    }

    disconnect() {
        if (this.eventSource) {
            this.eventSource.close();
            this.eventSource = null;
        }
    }

    onMercureMessage(event) {
        try {
            const msg = JSON.parse(event.data);
            this.appendMessage(msg);
        } catch (e) {
            console.error('Failed to parse Mercure message', e);
        }
    }

    async send(event) {
        event.preventDefault();
        const content = this.inputTarget.value.trim();
        if (!content) return;

        this.sendButtonTarget.disabled = true;

        try {
            if (this.signingModeValue === 'self-sovereign') {
                await this.sendSelfSovereign(content);
            } else {
                await this.sendCustodial(content);
            }
            this.inputTarget.value = '';
        } catch (e) {
            console.error('Send error:', e);
        } finally {
            this.sendButtonTarget.disabled = false;
            this.inputTarget.focus();
        }
    }

    /**
     * Custodial path: POST plaintext content, server signs the event.
     */
    async sendCustodial(content) {
        const response = await fetch(this.sendUrlValue, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ content }),
        });

        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            console.error('Send failed:', data.error || response.status);
        }
    }

    /**
     * Self-sovereign path: build kind-42 event, sign client-side via NIP-07/NIP-46,
     * then POST the signed event to the server for validation and relay publishing.
     */
    async sendSelfSovereign(content) {
        const { getSigner } = await import('../nostr/signer_manager.js');
        const signer = await getSigner();

        const tags = [
            ['e', this.channelEventIdValue, this.relayUrlValue, 'root'],
        ];

        const unsignedEvent = {
            kind: 42,
            content: content,
            tags: tags,
            created_at: Math.floor(Date.now() / 1000),
        };

        let signedEvent;
        if (typeof signer.signEvent === 'function') {
            // NIP-07 browser extension
            signedEvent = await signer.signEvent(unsignedEvent);
        } else {
            throw new Error('Signer does not support signEvent');
        }

        const response = await fetch(this.signedSendUrlValue, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(signedEvent),
        });

        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            console.error('Signed send failed:', data.error || response.status);
        }
    }

    async loadMore() {
        const firstMessage = this.messageListTarget.querySelector('.chat-message');
        if (!firstMessage) return;

        const oldestTime = firstMessage.querySelector('.chat-message__time');
        const before = oldestTime ? oldestTime.getAttribute('datetime') : null;
        if (!before) return;

        this.loadMoreTarget.disabled = true;

        try {
            const url = `${this.historyUrlValue}?before=${before}`;
            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) return;

            const messages = await response.json();
            if (messages.length === 0) {
                this.loadMoreTarget.style.display = 'none';
                return;
            }

            // Prepend older messages
            const fragment = document.createDocumentFragment();
            messages.forEach(msg => {
                fragment.appendChild(this.buildMessageElement(msg));
            });

            this.messageListTarget.insertBefore(fragment, this.loadMoreTarget.nextSibling);
        } catch (e) {
            console.error('Load more error:', e);
        } finally {
            this.loadMoreTarget.disabled = false;
        }
    }

    appendMessage(msg) {
        const el = this.buildMessageElement(msg);
        this.messageListTarget.appendChild(el);
        this.scrollToBottom();
    }

    buildMessageElement(msg) {
        const div = document.createElement('div');
        const isOwn = msg.senderPubkey === this.currentPubkeyValue;
        div.className = `chat-message${isOwn ? ' chat-message--own' : ''}`;

        div.innerHTML = `
            <div class="chat-message__sender">${this.escapeHtml(msg.senderDisplayName)}</div>
            <div class="chat-message__content">${this.escapeHtml(msg.content)}</div>
            <time class="chat-message__time" datetime="${msg.createdAt}">
                ${new Date(msg.createdAt * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
            </time>
        `;

        return div;
    }

    scrollToBottom() {
        this.messageListTarget.scrollTop = this.messageListTarget.scrollHeight;
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

