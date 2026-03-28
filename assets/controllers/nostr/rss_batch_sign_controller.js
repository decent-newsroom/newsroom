import { Controller } from '@hotwired/stimulus';
import { getSigner } from './signer_manager.js';

/**
 * RSS Batch Sign Controller
 *
 * Signs and publishes multiple RSS-imported event skeletons sequentially.
 * Each skeleton is signed client-side via the user's Nostr signer,
 * then POSTed to the backend for persistence and relay broadcasting.
 */
export default class extends Controller {
    static targets = [
        'status', 'signAllButton', 'card', 'cardStatus', 'preview',
        'progressContainer', 'progressFill', 'progressText'
    ];
    static values = {
        publishUrl: String,
        skeletons: Array
    };

    connect() {
        this.results = {};
        console.log('[rss-batch-sign] Connected with', this.skeletonsValue.length, 'skeletons');
    }

    /**
     * Sign and publish all pending articles sequentially.
     */
    async signAll() {
        let signer;
        try {
            this.showStatus('Connecting to signer…', 'info');
            signer = await getSigner();
        } catch (e) {
            this.showStatus('No Nostr signer available. Please connect Amber or install a Nostr signer extension.', 'error');
            return;
        }

        this.signAllButtonTarget.disabled = true;
        const skeletons = this.skeletonsValue;
        const total = skeletons.length;
        let success = 0;
        let failed = 0;
        let skipped = 0;

        this.showProgress(0, total);

        for (let i = 0; i < total; i++) {
            // Skip already-published
            if (this.results[i] === 'success') {
                skipped++;
                this.updateProgress(i + 1, total, success, failed, skipped);
                continue;
            }

            this.updateCardStatus(i, 'signing', 'signing…');

            try {
                await this.signAndPublishSingle(signer, skeletons[i], i);
                success++;
                this.updateCardStatus(i, 'success', 'published ✓');
            } catch (e) {
                failed++;
                console.error(`[rss-batch-sign] Failed to sign/publish #${i}:`, e);

                if (e.message && e.message.includes('rejected')) {
                    this.updateCardStatus(i, 'skipped', 'skipped (rejected)');
                    skipped++;
                    failed--;
                } else {
                    this.updateCardStatus(i, 'error', 'failed: ' + e.message);
                }
            }

            this.updateProgress(i + 1, total, success, failed, skipped);
        }

        this.signAllButtonTarget.disabled = false;

        const parts = [];
        if (success > 0) parts.push(`${success} published`);
        if (failed > 0) parts.push(`${failed} failed`);
        if (skipped > 0) parts.push(`${skipped} skipped`);
        this.showStatus(`Done: ${parts.join(', ')}.`, failed > 0 ? 'warning' : 'success');
    }

    /**
     * Sign and publish a single article.
     */
    async signSingle({ params: { index } }) {
        const idx = parseInt(index, 10);
        const skeleton = this.skeletonsValue[idx];
        if (!skeleton) return;

        let signer;
        try {
            this.showStatus('Connecting to signer…', 'info');
            signer = await getSigner();
        } catch (e) {
            this.showStatus('No Nostr signer available.', 'error');
            return;
        }

        this.updateCardStatus(idx, 'signing', 'signing…');

        try {
            await this.signAndPublishSingle(signer, skeleton, idx);
            this.updateCardStatus(idx, 'success', 'published ✓');
            this.showStatus('Article published successfully!', 'success');
        } catch (e) {
            console.error('[rss-batch-sign] Single sign error:', e);
            this.updateCardStatus(idx, 'error', 'failed: ' + e.message);
            this.showStatus('Publishing failed: ' + e.message, 'error');
        }
    }

    /**
     * Toggle JSON preview for a card.
     */
    togglePreview({ params: { index } }) {
        const idx = parseInt(index, 10);
        const previewEl = this.previewTargets.find(el => el.dataset.index === String(idx));
        if (previewEl) {
            previewEl.style.display = previewEl.style.display === 'none' ? 'block' : 'none';
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internal
    // ─────────────────────────────────────────────────────────────────────

    async signAndPublishSingle(signer, skeleton, index) {
        const pubkey = await signer.getPublicKey();

        // Build the event to sign (without _meta)
        const event = {
            kind: skeleton.kind,
            created_at: skeleton.created_at || Math.floor(Date.now() / 1000),
            content: skeleton.content || '',
            tags: skeleton.tags || [],
            pubkey: pubkey,
        };

        console.log(`[rss-batch-sign] Signing event #${index}:`, event);
        const signed = await signer.signEvent(event);
        console.log(`[rss-batch-sign] Event #${index} signed:`, signed.id);

        // Publish to backend
        const res = await fetch(this.publishUrlValue, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ event: signed })
        });

        if (!res.ok) {
            const data = await res.json().catch(() => ({}));
            throw new Error(data.error || `HTTP ${res.status}`);
        }

        const result = await res.json();
        this.results[index] = 'success';

        // Show relay results as toast if available
        if (result.relayResults && typeof window.showToast === 'function') {
            const successes = result.relayResults.filter(r => r.success).length;
            const total = result.relayResults.length;
            if (total > 0) {
                window.showToast(`Relay: ${successes}/${total} OK`, successes === total ? 'success' : 'warning', 3000);
            }
        }

        return result;
    }

    updateCardStatus(index, status, text) {
        const statusEl = this.cardStatusTargets.find(el => el.dataset.index === String(index));
        if (statusEl) {
            statusEl.innerHTML = `<span class="rss-status-badge rss-status-${status}">${text}</span>`;
        }

        const cardEl = this.cardTargets.find(el => el.dataset.index === String(index));
        if (cardEl) {
            cardEl.classList.remove('rss-card-signing', 'rss-card-success', 'rss-card-error', 'rss-card-skipped');
            cardEl.classList.add(`rss-card-${status}`);
        }
    }

    showProgress(current, total) {
        if (this.hasProgressContainerTarget) {
            this.progressContainerTarget.style.display = 'block';
        }
        this.updateProgress(current, total, 0, 0, 0);
    }

    updateProgress(current, total, success, failed, skipped) {
        const pct = total > 0 ? Math.round((current / total) * 100) : 0;

        if (this.hasProgressFillTarget) {
            this.progressFillTarget.style.width = `${pct}%`;
        }
        if (this.hasProgressTextTarget) {
            this.progressTextTarget.textContent = `${current} / ${total} — ${success} published, ${failed} failed, ${skipped} skipped`;
        }
    }

    showStatus(message, type = 'info') {
        if (this.hasStatusTarget) {
            this.statusTarget.innerHTML = `<div class="notice ${type}">${message}</div>`;
        }
    }
}

