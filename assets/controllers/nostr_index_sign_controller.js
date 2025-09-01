import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['status', 'publishButton', 'computedPreview'];
  static values = {
    categoryEvents: String,
    magazineEvent: String,
    publishUrl: String,
    csrfToken: String
  };

  async connect() {
    try {
      console.debug('[nostr-index-sign] connected');
      await this.preparePreview();
    } catch (_) {}
  }

  async preparePreview() {
    try {
      const catSkeletons = JSON.parse(this.categoryEventsValue || '[]');
      const magSkeleton = JSON.parse(this.magazineEventValue || '{}');
      let pubkey = '<pubkey>';
      if (window.nostr && typeof window.nostr.getPublicKey === 'function') {
        try { pubkey = await window.nostr.getPublicKey(); } catch (_) {}
      }

      const categoryCoordinates = [];
      for (let i = 0; i < catSkeletons.length; i++) {
        const evt = catSkeletons[i];
        const slug = this.extractSlug(evt.tags);
        if (slug) {
          categoryCoordinates.push(`30040:${pubkey}:${slug}`);
        }
      }

      const previewMag = JSON.parse(JSON.stringify(magSkeleton));
      previewMag.tags = (previewMag.tags || []).filter(t => t[0] !== 'a');
      categoryCoordinates.forEach(c => previewMag.tags.push(['a', c]));
      previewMag.pubkey = pubkey;

      if (this.hasComputedPreviewTarget) {
        this.computedPreviewTarget.textContent = JSON.stringify(previewMag, null, 2);
      }
    } catch (e) {
      // no-op preview errors
    }
  }

  async signAndPublish(event) {
    event.preventDefault();

    if (!window.nostr) {
      this.showError('Nostr extension not found');
      return;
    }
    if (!this.publishUrlValue || !this.csrfTokenValue) {
      this.showError('Missing config');
      return;
    }

    this.publishButtonTarget.disabled = true;
    try {
      const pubkey = await window.nostr.getPublicKey();
      const catSkeletons = JSON.parse(this.categoryEventsValue || '[]');
      const magSkeleton = JSON.parse(this.magazineEventValue || '{}');

      const categoryCoordinates = [];

      // 1) Publish each category index
      for (let i = 0; i < catSkeletons.length; i++) {
        const evt = catSkeletons[i];
        this.ensureCreatedAt(evt);
        this.ensureContent(evt);
        evt.pubkey = pubkey;

        const slug = this.extractSlug(evt.tags);
        if (!slug) throw new Error('Category missing slug (d tag)');

        this.showStatus(`Signing category ${i + 1}/${catSkeletons.length}…`);
        const signed = await window.nostr.signEvent(evt);

        this.showStatus(`Publishing category ${i + 1}/${catSkeletons.length}…`);
        await this.publishSigned(signed);

        // Coordinate for the category index (kind:pubkey:slug)
        const coord = `30040:${pubkey}:${slug}`;
        categoryCoordinates.push(coord);
      }

      // 2) Build magazine event with 'a' tags referencing cats
      this.showStatus('Preparing magazine index…');
      this.ensureCreatedAt(magSkeleton);
      this.ensureContent(magSkeleton);
      magSkeleton.pubkey = pubkey;

      // Remove any pre-existing 'a' to avoid duplicates, then add new ones
      magSkeleton.tags = (magSkeleton.tags || []).filter(t => t[0] !== 'a');
      categoryCoordinates.forEach(c => magSkeleton.tags.push(['a', c]));

      // 3) Sign and publish magazine
      this.showStatus('Signing magazine index…');
      const signedMag = await window.nostr.signEvent(magSkeleton);

      this.showStatus('Publishing magazine index…');
      await this.publishSigned(signedMag);

      this.showSuccess('Published magazine and categories successfully');

    } catch (e) {
      console.error(e);
      this.showError(e.message || 'Publish failed');
    } finally {
      this.publishButtonTarget.disabled = false;
    }
  }

  async publishSigned(signedEvent) {
    const res = await fetch(this.publishUrlValue, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': this.csrfTokenValue,
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({ event: signedEvent })
    });
    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      throw new Error(data.error || `HTTP ${res.status}`);
    }
    return res.json();
  }

  extractSlug(tags) {
    if (!Array.isArray(tags)) return null;
    for (const t of tags) {
      if (Array.isArray(t) && t[0] === 'd' && t[1]) return t[1];
    }
    return null;
  }

  ensureCreatedAt(evt) {
    if (!evt.created_at) evt.created_at = Math.floor(Date.now() / 1000);
  }
  ensureContent(evt) {
    if (typeof evt.content !== 'string') evt.content = '';
  }

  showStatus(message) {
    if (this.hasStatusTarget) {
      this.statusTarget.innerHTML = `<div class="alert alert-info">${message}</div>`;
    }
  }
  showSuccess(message) {
    if (this.hasStatusTarget) {
      this.statusTarget.innerHTML = `<div class="alert alert-success">${message}</div>`;
    }
  }
  showError(message) {
    if (this.hasStatusTarget) {
      this.statusTarget.innerHTML = `<div class="alert alert-danger">${message}</div>`;
    }
  }
}

