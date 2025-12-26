import { Controller } from '@hotwired/stimulus';

// Inline utility functions (simplified versions)
function buildAdvancedTags(metadata) {
  const tags = [];

  // Policy: Do not republish
  if (metadata.doNotRepublish) {
    tags.push(['L', 'rights.decent.newsroom']);
    tags.push(['l', 'no-republish', 'rights.decent.newsroom']);
  }

  // License
  const license = metadata.license === 'custom' ? metadata.customLicense : metadata.license;
  if (license && license !== 'All rights reserved') {
    tags.push(['L', 'spdx.org/licenses']);
    tags.push(['l', license, 'spdx.org/licenses']);
  } else if (license === 'All rights reserved') {
    tags.push(['L', 'rights.decent.newsroom']);
    tags.push(['l', 'all-rights-reserved', 'rights.decent.newsroom']);
  }

  // Zap splits
  for (const split of metadata.zapSplits) {
    const zapTag = ['zap', split.recipient, split.relay || ''];
    if (split.weight !== undefined && split.weight !== null) {
      zapTag.push(split.weight.toString());
    }
    tags.push(zapTag);
  }

  // Content warning
  if (metadata.contentWarning) {
    tags.push(['content-warning', metadata.contentWarning]);
  }

  // Expiration
  if (metadata.expirationTimestamp) {
    tags.push(['expiration', metadata.expirationTimestamp.toString()]);
  }

  // Protected event
  if (metadata.isProtected) {
    tags.push(['-']);
  }

  return tags;
}

function validateAdvancedMetadata(metadata) {
  const errors = [];

  // Basic validation
  for (let i = 0; i < metadata.zapSplits.length; i++) {
    const split = metadata.zapSplits[i];

    if (!split.recipient) {
      errors.push(`Zap split ${i + 1}: Recipient is required`);
    }

    if (split.relay && !split.relay.startsWith('wss://')) {
      errors.push(`Zap split ${i + 1}: Invalid relay URL (must start with wss://)`);
    }

    if (split.weight !== undefined && split.weight !== null && split.weight < 0) {
      errors.push(`Zap split ${i + 1}: Weight must be 0 or greater`);
    }
  }

  // Validate expiration is in the future
  if (metadata.expirationTimestamp) {
    const now = Math.floor(Date.now() / 1000);
    if (metadata.expirationTimestamp <= now) {
      errors.push('Expiration date must be in the future');
    }
  }

  return {
    valid: errors.length === 0,
    errors
  };
}

export default class extends Controller {
  static targets = ['form', 'publishButton', 'status', 'jsonContainer', 'jsonTextarea', 'jsonToggle', 'jsonDirtyHint'];
  static values = {
    publishUrl: String
  };

  connect() {
    console.log('Nostr publish controller connected');
    try {
      console.debug('[nostr-publish] publishUrl:', this.publishUrlValue || '(none)');
      console.debug('[nostr-publish] existing slug:', (this.element.dataset.slug || '(none)'));
    } catch (_) {}

    // Track whether JSON has been manually edited
    this.jsonEdited = false;
  }

  // Toggle JSON preview visibility. If opening and empty, generate from form.
  toggleJsonPreview() {
    if (!this.hasJsonContainerTarget) return;
    const wasHidden = this.jsonContainerTarget.hasAttribute('hidden');
    if (wasHidden) {
      // opening
      if (!this.jsonEdited && (!this.hasJsonTextareaTarget || !this.jsonTextareaTarget.value.trim())) {
        this.regenerateJsonPreview();
      }
      this.jsonContainerTarget.removeAttribute('hidden');
      if (this.hasJsonToggleTarget) this.jsonToggleTarget.textContent = 'Hide raw event JSON';
    } else {
      // closing, keep content as-is
      this.jsonContainerTarget.setAttribute('hidden', '');
      if (this.hasJsonToggleTarget) this.jsonToggleTarget.textContent = 'Show raw event JSON';
    }
  }

  // Rebuild JSON from form data (clears edited flag)
  async regenerateJsonPreview() {
    try {
      const formData = this.collectFormData();
      const nostrEvent = await this.createNostrEvent(formData);
      const pretty = JSON.stringify(nostrEvent, null, 2);
      if (this.hasJsonTextareaTarget) this.jsonTextareaTarget.value = pretty;
      this.jsonEdited = false;
      if (this.hasJsonDirtyHintTarget) this.jsonDirtyHintTarget.style.display = 'none';
      // Dispatch event to notify others that JSON is ready
      this.element.dispatchEvent(new CustomEvent('nostr-json-ready', { bubbles: true }));
    } catch (e) {
      this.showError('Could not build event JSON: ' + (e?.message || e));
    }
  }

  // Mark JSON as edited on user input
  onJsonInput() {
    this.jsonEdited = true;
    if (this.hasJsonDirtyHintTarget) this.jsonDirtyHintTarget.style.display = '';
  }

  async publish(event = null) {
    if (event) {
      event.preventDefault();
    }

    if (!this.publishUrlValue) {
      this.showError('Publish URL is not configured');
      return;
    }

    if (!window.nostr) {
      this.showError('Nostr extension not found');
      return;
    }

    this.publishButtonTarget.disabled = true;
    this.showStatus('Preparing article for signing...');

    try {
      // Collect form data (always, for fallback and backend extras)
      const formData = this.collectFormData();

      // Validate required fields if no JSON override
      if (!this.jsonEdited) {
        if (!formData.title || !formData.content) {
          throw new Error('Title and content are required');
        }
      }

      // Create or use overridden Nostr event
      let nostrEvent;
      if (this.jsonEdited && this.hasJsonTextareaTarget && this.jsonTextareaTarget.value.trim()) {
        try {
          const parsed = JSON.parse(this.jsonTextareaTarget.value);
          // Ensure required fields exist; supplement from form when missing
          nostrEvent = this.applyEventDefaults(parsed, formData);
        } catch (e) {
          throw new Error('Invalid JSON in raw event area: ' + (e?.message || e));
        }
      } else {
        nostrEvent = await this.createNostrEvent(formData);
      }

      // Ensure pubkey present before signing
      if (!nostrEvent.pubkey) {
        try { nostrEvent.pubkey = await window.nostr.getPublicKey(); } catch (_) {}
      }

      this.showStatus('Requesting signature from Nostr extension...');

      // Sign the event with Nostr extension
      const signedEvent = await window.nostr.signEvent(nostrEvent);

      this.showStatus('Publishing article...');

      // Send to backend
      await this.sendToBackend(signedEvent, formData);

      this.showSuccess('Article published successfully!');

      // Optionally redirect after successful publish
      setTimeout(() => {
        window.location.href = `/article/d/${encodeURIComponent(formData.slug)}`;
      }, 2000);

    } catch (error) {
      console.error('Publishing error:', error);
      this.showError(`Publishing failed: ${error.message}`);
    } finally {
      this.publishButtonTarget.disabled = false;
    }
  }

  // If a user provided a partial or custom event, make sure required keys exist and supplement from form
  applyEventDefaults(event, formData, options = {}) {
    const now = Math.floor(Date.now() / 1000);
    const corrected = { ...event };

    // Ensure tags/content/kind/created_at/pubkey exist; tags default includes d/title/summary/image/topics
    if (!Array.isArray(corrected.tags)) corrected.tags = [];

    // Supplement missing core fields from form or options
    // Kind: explicit option > formData.isDraft > event.kind
    if (typeof options.kind === 'number') {
      corrected.kind = options.kind;
    } else if (typeof corrected.kind !== 'number') {
      corrected.kind = formData.isDraft ? 30024 : 30023;
    }
    if (typeof corrected.created_at !== 'number') corrected.created_at = now;
    if (typeof corrected.content !== 'string') corrected.content = formData.content || '';

    // pubkey must be from the user's extension for signature to pass; attempt to fill
    if (!corrected.pubkey) corrected.pubkey = undefined; // will be filled by createNostrEvent path if used

    // Guarantee a d tag (slug)
    const tagsMap = new Map();
    for (const t of corrected.tags) {
      if (Array.isArray(t) && t.length > 0) tagsMap.set(t[0], t);
    }
    if (formData.slug) tagsMap.set('d', ['d', formData.slug]);
    if (formData.title) tagsMap.set('title', ['title', formData.title]);
    if (formData.summary) tagsMap.set('summary', ['summary', formData.summary]);
    if (formData.image) tagsMap.set('image', ['image', formData.image]);
    // Topics: allow multiple t tags
    if (formData.topics && Array.isArray(formData.topics)) {
      // Remove all existing t tags
      for (const key of Array.from(tagsMap.keys())) {
        if (key === 't') tagsMap.delete(key);
      }
      for (const topic of formData.topics) {
        tagsMap.set(`t:${topic.replace('#','')}`, ['t', topic.replace('#','')]);
      }
    }
    // Advanced tags from form, but don't duplicate existing tags by name
    if (formData.advancedMetadata) {
      const adv = buildAdvancedTags(formData.advancedMetadata);
      for (const tag of adv) {
        if (!tagsMap.has(tag[0])) tagsMap.set(tag[0], tag);
      }
    }
    // Rebuild tags array
    corrected.tags = Array.from(tagsMap.values());

    return corrected;
  }

  collectFormData() {
    // Find the actual form element in the editor (it's not within our hidden container)
    // Try multiple selectors to be robust
    const form = document.querySelector('.editor-center-content form')
              || document.querySelector('form[name="editor"]')
              || document.querySelector('.editor-main form');

    if (!form) {
      console.error('Could not find form element. Available forms:', document.querySelectorAll('form'));
      throw new Error('Form element not found');
    }

    const fd = new FormData(form);

    // Only use the Markdown field
    const content = fd.get('editor[content]') || '';

    const title = fd.get('editor[title]') || '';
    const summary = fd.get('editor[summary]') || '';
    const image = fd.get('editor[image]') || '';
    const topicsString = fd.get('editor[topics]') || '';
    const isDraft = fd.get('editor[isDraft]') === '1';
    const addClientTag = fd.get('editor[clientTag]') === '1';

    // Collect advanced metadata
    const advancedMetadata = this.collectAdvancedMetadata(fd);

    // Parse topics
    const topics = String(topicsString).split(',')
      .map(s => s.trim())
      .filter(Boolean)
      .map(t => t.startsWith('#') ? t : `#${t}`);

    // Reuse existing slug if provided on the container (editing), else generate from title
    const existingSlug = (this.element.dataset.slug || '').trim();
    const slug = existingSlug || this.generateSlug(String(title));

    return {
      title: String(title),
      summary: String(summary),
      content,
      image: String(image),
      topics,
      slug,
      isDraft,
      addClientTag,
      advancedMetadata,
    };
  }

  collectAdvancedMetadata(fd) {
    const metadata = {
      doNotRepublish: fd.get('editor[advancedMetadata][doNotRepublish]') === '1',
      license: fd.get('editor[advancedMetadata][license]') || '',
      customLicense: fd.get('editor[advancedMetadata][customLicense]') || '',
      contentWarning: fd.get('editor[advancedMetadata][contentWarning]') || '',
      isProtected: fd.get('editor[advancedMetadata][isProtected]') === '1',
      zapSplits: [],
    };

    // Parse expiration timestamp
    const expirationDate = fd.get('editor[advancedMetadata][expirationTimestamp]');
    if (expirationDate) {
      try {
        metadata.expirationTimestamp = Math.floor(new Date(expirationDate).getTime() / 1000);
      } catch (e) {
        console.warn('Invalid expiration date:', e);
      }
    }

    // Collect zap splits
    let index = 0;
    while (true) {
      const recipient = fd.get(`editor[advancedMetadata][zapSplits][${index}][recipient]`);
      if (!recipient) break;

      const relay = fd.get(`editor[advancedMetadata][zapSplits][${index}][relay]`) || '';
      const weightStr = fd.get(`editor[advancedMetadata][zapSplits][${index}][weight]`);
      const weight = weightStr ? parseInt(weightStr, 10) : undefined;

      metadata.zapSplits.push({
        recipient: String(recipient),
        relay: relay ? String(relay) : undefined,
        weight: weight !== undefined && !isNaN(weight) ? weight : undefined,
      });

      index++;
    }

    return metadata;
  }

  async createNostrEvent(formData) {
    // Get user's public key if available (preview can work without it)
    let pubkey = '';
    try {
      if (window.nostr && typeof window.nostr.getPublicKey === 'function') {
        pubkey = await window.nostr.getPublicKey();
      }
    } catch (_) {}

    // Validate advanced metadata if present
    if (formData.advancedMetadata && formData.advancedMetadata.zapSplits.length > 0) {
      const validation = validateAdvancedMetadata(formData.advancedMetadata);
      if (!validation.valid) {
        throw new Error('Invalid advanced metadata: ' + validation.errors.join(', '));
      }
    }

    // Create tags array
    const tags = [
      ['d', formData.slug],
      ['title', formData.title],
      ['published_at', Math.floor(Date.now() / 1000).toString()],
    ];

    let kind = 30023; // Default kind for long-form content
    if (formData.isDraft) {
      kind = 30024; // Draft kind
    }

    if (formData.summary) {
      tags.push(['summary', formData.summary]);
    }

    if (formData.image) {
      tags.push(['image', formData.image]);
    }

    // Add topic tags
    formData.topics.forEach(topic => {
      tags.push(['t', topic.replace('#', '')]);
    });

    if (formData.addClientTag) {
      tags.push(['client', 'Decent Newsroom']);
    }

    // Add advanced metadata tags
    if (formData.advancedMetadata) {
      const advancedTags = buildAdvancedTags(formData.advancedMetadata);
      tags.push(...advancedTags);
    }

    // Create the Nostr event (NIP-23 long-form content)
    return {
      kind: kind,
      created_at: Math.floor(Date.now() / 1000),
      tags: tags,
      content: formData.content,
      pubkey: pubkey
    };

  }

  async sendToBackend(signedEvent, formData) {
    const response = await fetch(this.publishUrlValue, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({
        event: signedEvent,
        formData: formData
      })
    });

    if (!response.ok) {
      const errorData = await response.json().catch(() => ({}));
      throw new Error(errorData.message || `HTTP ${response.status}: ${response.statusText}`);
    }

    return await response.json();
  }

  generateSlug(title) {
    // add a random seed at the end of the title to avoid collisions
    const randomSeed = Math.random().toString(36).substring(2, 8);
    title = `${title} ${randomSeed}`;
    return title
      .toLowerCase()
      .replace(/[^a-z0-9\s-]/g, '')
      .replace(/\s+/g, '-')
      .replace(/-+/g, '-')
      .replace(/^-|-$/g, '');
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
