import { Controller } from "@hotwired/stimulus";

// Connects to data-controller="comments-mercure"
export default class extends Controller {
    static values = {
        coordinate: String
    }
    static targets = ["list", "loading"];

    connect() {
        const coordinate = this.coordinateValue;
        const topic = `/comments/${coordinate}`;
        const hubUrl = window.MercureHubUrl || (document.querySelector('meta[name="mercure-hub"]')?.content);
        console.log('[comments-mercure] connect', { coordinate, topic, hubUrl });
        if (!hubUrl) return;
        const url = new URL(hubUrl);
        url.searchParams.append('topic', topic);
        this.eventSource = new EventSource(url.toString());
        this.eventSource.onopen = () => {
            console.log('[comments-mercure] EventSource opened', url.toString());
        };
        this.eventSource.onerror = (e) => {
            console.error('[comments-mercure] EventSource error', e);
        };
        this.eventSource.onmessage = (event) => {
            console.log('[comments-mercure] Event received', event.data);
            const data = JSON.parse(event.data);
            this.profiles = data.profiles || {};
            if (this.hasLoadingTarget) this.loadingTarget.style.display = 'none';
            if (this.hasListTarget) {
                if (data.comments && data.comments.length > 0) {
                    this.listTarget.innerHTML = data.comments.map((item) => {
                        const zapData = this.parseZapAmount(item) || {};
                        const zapAmount = zapData.amount;
                        const zapperPubkey = zapData.zapper;
                        const parsedContent = this.parseContent(item.content);
                        const isZap = item.kind === 9735;
                        const displayPubkey = isZap ? (zapperPubkey || item.pubkey) : item.pubkey;
                        const profile = this.profiles[displayPubkey];
                        const displayName = profile?.name || displayPubkey;
                        return `<div class="card comment ${isZap ? 'zap-comment' : ''}">
  <div class="metadata">
    <span><a href="/p/${displayPubkey}">${displayName}</a></span>
    <small>${item.created_at ? new Date(item.created_at * 1000).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : ''}</small>
  </div>
  <div class="card-body">
    ${isZap ? `<div class="zap-amount">${zapAmount ? `<strong>${zapAmount} sat</strong>` : '<em>Zap</em>'}</div>` : ''}
    <div>${parsedContent}</div>
  </div>
</div>`;
                    }).join('');
                } else {
                    this.listTarget.innerHTML = '<div class="no-comments">No comments yet.</div>';
                }
                this.listTarget.style.display = '';
            }
        };
    }

    disconnect() {
        if (this.eventSource) {
            this.eventSource.close();
            console.log('[comments-mercure] EventSource closed');
        }
    }

    parseContent(content) {
        if (!content) return '';

        // Escape HTML to prevent XSS
        let html = content.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

        // Parse URLs
        html = html.replace(/(https?:\/\/[^\s]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>');

        // Parse Nostr npub
        html = html.replace(/\b(npub1[a-z0-9]+)\b/g, '<a href="/user/$1">$1</a>');

        // Parse Nostr nevent
        html = html.replace(/\b(nevent1[a-z0-9]+)\b/g, '<a href="/event/$1">$1</a>');

        // Parse Nostr nprofile
        html = html.replace(/\b(nprofile1[a-z0-9]+)\b/g, '<a href="/profile/$1">$1</a>');

        // Parse Nostr note
        html = html.replace(/\b(note1[a-z0-9]+)\b/g, '<a href="/note/$1">$1</a>');

        return html;
    }

    parseZapAmount(item) {
        if (item.kind !== 9735) return null;

        const tags = item.tags || [];
        let amount = null;
        let zapper = null;

        // Find zapper from 'p' tag
        const pTag = tags.find(tag => tag[0] === 'p');
        if (pTag && pTag[1]) {
            zapper = pTag[1];
        }

        // Find amount in 'amount' tag (msat)
        const amountTag = tags.find(tag => tag[0] === 'amount');
        if (amountTag && amountTag[1]) {
            const msat = parseInt(amountTag[1], 10);
            amount = Math.floor(msat / 1000); // Convert to sat
        }

        // Fallback to description for content
        const descTag = tags.find(tag => tag[0] === 'description');
        if (descTag && descTag[1]) {
            try {
                const desc = JSON.parse(descTag[1]);
                if (desc.content) {
                    item.content = desc.content; // Update content
                }
            } catch (e) {}
        }

        return { amount, zapper };
    }
}
