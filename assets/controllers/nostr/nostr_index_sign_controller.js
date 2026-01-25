import { Controller } from '@hotwired/stimulus';
import { getSigner, getRemoteSignerSession } from './signer_manager.js';

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

      // Check for remote signer session first - don't use extension if remote signer is active
      const session = getRemoteSignerSession();
      if (session) {
        pubkey = '<will be obtained from remote signer>';
      } else if (window.nostr && typeof window.nostr.getPublicKey === 'function') {
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

    let signer;
    try {
      this.showStatus('Connecting to signer…');
      signer = await getSigner();
      console.log('[nostr-index-sign] Signer obtained successfully');
    } catch (e) {
      this.showError('No Nostr signer available. Please connect Amber or install a Nostr signer extension.');
      return;
    }
    if (!this.publishUrlValue || !this.csrfTokenValue) {
      this.showError('Missing config');
      return;
    }
    this.publishButtonTarget.disabled = true;
    try {
      this.showStatus('Getting public key from signer…');
      const pubkey = await signer.getPublicKey();
      console.log('[nostr-index-sign] Public key:', pubkey);

      const catSkeletons = JSON.parse(this.categoryEventsValue || '[]');
      const magSkeleton = JSON.parse(this.magazineEventValue || '{}');
      const categoryCoordinates = [];

      // 1) Publish each category index
      const totalEvents = catSkeletons.length + 1; // categories + magazine
      let currentEvent = 0;

      for (let i = 0; i < catSkeletons.length; i++) {
        currentEvent++;
        const evt = catSkeletons[i];
        this.ensureCreatedAt(evt);
        this.ensureContent(evt);
        evt.pubkey = pubkey;
        const slug = this.extractSlug(evt.tags);
        if (!slug) throw new Error('Category missing slug (d tag)');

        this.showStatus(`[${currentEvent}/${totalEvents}] Requesting signature for category "${slug}"… (Please confirm in your signer)`);
        console.log(`[nostr-index-sign] Signing category ${i + 1}/${catSkeletons.length}: ${slug}`);

        // Sign with timeout and wait for response
        const signed = await this.signEventWithRetry(signer, evt, slug);
        console.log(`[nostr-index-sign] Category signed successfully: ${slug}`, signed);

        this.showStatus(`[${currentEvent}/${totalEvents}] Publishing category "${slug}"…`);
        await this.publishSigned(signed);
        console.log(`[nostr-index-sign] Category published: ${slug}`);

        // Add delay between events to ensure remote signer relay has processed the response
        // and is ready for the next request
        if (i < catSkeletons.length - 1) {
          console.log('[nostr-index-sign] Waiting 1000ms before next signing request...');
          await new Promise(resolve => setTimeout(resolve, 1000));
        }

        // Coordinate for the category index (kind:pubkey:slug)
        const coord = `30040:${pubkey}:${slug}`;
        categoryCoordinates.push(coord);
      }

      // 2) Build magazine event with 'a' tags referencing cats
      currentEvent++;
      const magSlug = this.extractSlug(magSkeleton.tags);
      this.showStatus(`[${currentEvent}/${totalEvents}] Preparing magazine index "${magSlug}"…`);
      this.ensureCreatedAt(magSkeleton);
      this.ensureContent(magSkeleton);
      magSkeleton.pubkey = pubkey;
      // Remove any pre-existing 'a' to avoid duplicates, then add new ones
      magSkeleton.tags = (magSkeleton.tags || []).filter(t => t[0] !== 'a');
      categoryCoordinates.forEach(c => magSkeleton.tags.push(['a', c]));

      // 3) Sign and publish magazine
      this.showStatus(`[${currentEvent}/${totalEvents}] Requesting signature for magazine "${magSlug}"… (Please confirm in your signer)`);
      console.log('[nostr-index-sign] Signing magazine index:', magSlug);

      // Wait before final magazine event
      console.log('[nostr-index-sign] Waiting 1000ms before magazine signing request...');
      await new Promise(resolve => setTimeout(resolve, 1000));

      const signedMag = await this.signEventWithRetry(signer, magSkeleton, magSlug);
      console.log('[nostr-index-sign] Magazine signed successfully:', magSlug, signedMag);

      this.showStatus(`[${currentEvent}/${totalEvents}] Publishing magazine index "${magSlug}"…`);
      await this.publishSigned(signedMag);
      console.log('[nostr-index-sign] Magazine published:', magSlug);

      this.showSuccess('✅ Published magazine and all categories successfully!');
    } catch (e) {
      console.error('[nostr-index-sign] Error:', e);
      this.showError(e.message || 'Publish failed');
    } finally {
      this.publishButtonTarget.disabled = false;
    }
  }

  async signEventWithRetry(signer, event, slug, maxRetries = 2) {
    console.log(`[nostr-index-sign] signEventWithRetry for "${slug}", maxRetries: ${maxRetries}`);

    for (let attempt = 0; attempt <= maxRetries; attempt++) {
      try {
        if (attempt > 0) {
          console.log(`[nostr-index-sign] Retry attempt ${attempt}/${maxRetries} for "${slug}"`);
          // Wait longer between retries
          await new Promise(resolve => setTimeout(resolve, 2000));
        }

        // Create timeout promise - 90 seconds should be enough for user to confirm in Amber
        const timeoutMs = 90000;
        const timeoutPromise = new Promise((_, reject) =>
          setTimeout(() => reject(new Error(`Signing timeout after ${timeoutMs/1000}s - please ensure you confirmed in your signer`)), timeoutMs)
        );

        console.log(`[nostr-index-sign] Calling signer.signEvent() for "${slug}"...`);
        const signPromise = signer.signEvent(event);

        // Race between signing and timeout
        const signed = await Promise.race([signPromise, timeoutPromise]);

        // Verify we got a valid signed event
        if (!signed || !signed.sig || !signed.id) {
          throw new Error('Invalid signed event returned - missing signature or id');
        }

        console.log(`[nostr-index-sign] ✅ Event signed successfully for "${slug}"`);
        return signed;

      } catch (error) {
        console.error(`[nostr-index-sign] Signing attempt ${attempt + 1} failed for "${slug}":`, error);

        // If this was the last attempt, throw the error
        if (attempt >= maxRetries) {
          throw new Error(`Failed to sign "${slug}" after ${maxRetries + 1} attempts: ${error.message}`);
        }

        // Otherwise, log and retry
        console.log(`[nostr-index-sign] Will retry signing "${slug}"...`);
      }
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
