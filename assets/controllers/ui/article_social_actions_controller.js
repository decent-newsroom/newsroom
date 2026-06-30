import { Controller } from '@hotwired/stimulus';
import { getSigner } from '../nostr/signer_manager.js';

export default class extends Controller {
  static targets = ['likeButton', 'likeIcon', 'likeCount'];

  static values = {
    coordinate: String,
    authorPubkey: String,
    articleKind: Number,
    commentFormId: String,
    canonicalUrl: String,
    articleTitle: String,
    reactionFetchUrl: String,
    reactionPublishUrl: String,
    copiedLabel: String,
    shareFailedLabel: String,
    signerUnavailableLabel: String,
    likedLabel: String,
    likeFailedLabel: String,
  };

  connect() {
    this.liked = false;
    this.likeCount = 0;
    this.likeSubmitting = false;

    this.fetchReactionState();
  }

  openComment(event) {
    event.preventDefault();

    const id = this.commentFormIdValue || 'article-comment-form';
    const formSection = document.getElementById(id);

    if (formSection instanceof HTMLDetailsElement) {
      formSection.open = true;
    }

    if (formSection) {
      formSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
      const focusable = formSection.querySelector('textarea, input, [contenteditable="true"], button');
      if (focusable) {
        window.setTimeout(() => focusable.focus(), 250);
      }
      return;
    }

    const comments = document.getElementById('article-comments');
    if (comments) {
      comments.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  async fetchReactionState() {
    if (!this.hasReactionFetchUrlValue || !this.reactionFetchUrlValue) {
      return;
    }

    try {
      const response = await fetch(this.reactionFetchUrlValue, {
        headers: { Accept: 'application/json' },
      });

      if (!response.ok) {
        return;
      }

      const data = await response.json();
      this.liked = Boolean(data.liked);
      this.likeCount = Number.isFinite(Number(data.count)) ? Number(data.count) : 0;
      this.updateLikeUI();
    } catch (error) {
      console.warn('[article-social-actions] Failed to fetch reaction state:', error);
    }
  }

  async publishLike(event) {
    event.preventDefault();

    if (this.likeSubmitting || this.liked || !this.hasReactionPublishUrlValue) {
      return;
    }

    this.likeSubmitting = true;
    this.updateLikeUI();

    let signer;
    try {
      signer = await getSigner();
    } catch (error) {
      this.toast(this.signerUnavailableLabelValue || 'No Nostr signer available', 'danger');
      this.likeSubmitting = false;
      this.updateLikeUI();
      return;
    }

    try {
      const pubkey = await signer.getPublicKey();
      const signedEvent = await signer.signEvent({
        kind: 7,
        pubkey,
        created_at: Math.floor(Date.now() / 1000),
        tags: [
          ['a', this.coordinateValue],
          ['p', this.authorPubkeyValue],
          ['k', String(this.articleKindValue || 30023)],
        ],
        content: '+',
      });

      const data = await this.postJSON(this.reactionPublishUrlValue, { event: signedEvent });

      this.liked = true;
      this.likeCount = Number.isFinite(Number(data.count)) ? Number(data.count) : this.likeCount + 1;
      this.toast(this.likedLabelValue || 'Liked', 'success');
    } catch (error) {
      console.error('[article-social-actions] Like failed:', error);
      this.toast(this.likeFailedLabelValue || 'Like failed', 'danger');
    } finally {
      this.likeSubmitting = false;
      this.updateLikeUI();
    }
  }

  async share(event) {
    event.preventDefault();

    const url = this.canonicalUrlValue || window.location.href;
    const title = this.articleTitleValue || document.title;

    if (navigator.share) {
      try {
        await navigator.share({ title, url });
        return;
      } catch (error) {
        if (error?.name === 'AbortError') {
          return;
        }
      }
    }

    try {
      await this.copyToClipboard(url);
      this.toast(this.copiedLabelValue || 'Copied', 'success');
    } catch (error) {
      console.error('[article-social-actions] Share failed:', error);
      this.toast(this.shareFailedLabelValue || 'Could not share', 'danger');
    }
  }

  updateLikeUI() {
    if (this.hasLikeButtonTarget) {
      this.likeButtonTarget.disabled = this.likeSubmitting;
      this.likeButtonTarget.classList.toggle('is-active', this.liked);
      this.likeButtonTarget.classList.toggle('is-loading', this.likeSubmitting);
      this.likeButtonTarget.setAttribute('aria-pressed', this.liked ? 'true' : 'false');
    }

    if (this.hasLikeIconTarget) {
      this.likeIconTarget.setAttribute('fill', this.liked ? 'currentColor' : 'none');
    }

    if (this.hasLikeCountTarget) {
      this.likeCountTarget.textContent = this.likeCount > 0 ? String(this.likeCount) : '';
      this.likeCountTarget.hidden = this.likeCount <= 0;
    }
  }

  async copyToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text);
      return;
    }

    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.top = '-1000px';
    document.body.appendChild(textarea);
    textarea.select();

    try {
      document.execCommand('copy');
    } finally {
      textarea.remove();
    }
  }

  async postJSON(url, body) {
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify(body),
    });

    const contentType = response.headers.get('content-type');
    if (!contentType || !contentType.includes('application/json')) {
      throw new Error(`Server returned non-JSON response (${response.status})`);
    }

    const data = await response.json();
    if (!response.ok) {
      throw new Error(data.error || `HTTP ${response.status}`);
    }

    return data;
  }

  toast(message, type = 'info', duration = 3000) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, type, duration);
    }
  }
}
