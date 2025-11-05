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
  static targets = ['form', 'publishButton', 'status'];
  static values = {
    publishUrl: String,
    csrfToken: String
  };

  connect() {
    console.log('Nostr publish controller connected');
    try {
      console.debug('[nostr-publish] publishUrl:', this.publishUrlValue || '(none)');
      console.debug('[nostr-publish] has csrfToken:', Boolean(this.csrfTokenValue));
      console.debug('[nostr-publish] existing slug:', (this.element.dataset.slug || '(none)'));
    } catch (_) {}
  }

  async publish(event) {
    event.preventDefault();

    if (!this.publishUrlValue) {
      this.showError('Publish URL is not configured');
      return;
    }
    if (!this.csrfTokenValue) {
      this.showError('Missing CSRF token');
      return;
    }

    if (!window.nostr) {
      this.showError('Nostr extension not found');
      return;
    }

    this.publishButtonTarget.disabled = true;
    this.showStatus('Preparing article for signing...');

    try {
      // Collect form data
      const formData = this.collectFormData();

      // Validate required fields
      if (!formData.title || !formData.content) {
        throw new Error('Title and content are required');
      }

      // Create Nostr event
      const nostrEvent = await this.createNostrEvent(formData);

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

  collectFormData() {
    // Find the actual form element within our target
    const form = this.formTarget.querySelector('form');
    if (!form) throw new Error('Form element not found');

    const fd = new FormData(form);

    // Prefer the Markdown field populated by the Quill controller
    const md = fd.get('editor[content_md]');
    let html = fd.get('editor[content]') || fd.get('content') || '';

    // Final content: use MD if present, otherwise convert HTML -> MD
    const content = (typeof md === 'string' && md.length > 0)
      ? md
      : this.htmlToMarkdown(String(html));

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
    // Get user's public key
    const pubkey = await window.nostr.getPublicKey();

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
    const event = {
      kind: kind,
      created_at: Math.floor(Date.now() / 1000),
      tags: tags,
      content: formData.content,
      pubkey: pubkey
    };

    return event;
  }

  async sendToBackend(signedEvent, formData) {
    const response = await fetch(this.publishUrlValue, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': this.csrfTokenValue
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

  htmlToMarkdown(html) {
    // Basic HTML to Markdown conversion
    let markdown = html;

    // Convert headers
    markdown = markdown.replace(/<h1[^>]*>(.*?)<\/h1>/gi, '# $1\n\n');
    markdown = markdown.replace(/<h2[^>]*>(.*?)<\/h2>/gi, '## $1\n\n');
    markdown = markdown.replace(/<h3[^>]*>(.*?)<\/h3>/gi, '### $1\n\n');

    // Convert formatting
    markdown = markdown.replace(/<strong[^>]*>(.*?)<\/strong>/gi, '**$1**');
    markdown = markdown.replace(/<b[^>]*>(.*?)<\/b>/gi, '**$1**');
    markdown = markdown.replace(/<em[^>]*>(.*?)<\/em>/gi, '*$1*');
    markdown = markdown.replace(/<i[^>]*>(.*?)<\/i>/gi, '*$1*');

    // Convert links
    markdown = markdown.replace(/<a[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/gi, '[$2]($1)');

    // Convert images
    markdown = markdown.replace(/<img\b[^>]*>/gi, (imgTag) => {
      const srcMatch = imgTag.match(/src=["']([^"']+)["']/i);
      const altMatch = imgTag.match(/alt=["']([^"']*)["']/i);
      const src = srcMatch ? srcMatch[1] : '';
      const alt = altMatch ? altMatch[1] : '';
      return src ? `![${alt}](${src})` : '';
    });

    // Convert lists
    markdown = markdown.replace(/<ul[^>]*>(.*?)<\/ul>/gis, '$1\n');
    markdown = markdown.replace(/<ol[^>]*>(.*?)<\/ol>/gis, '$1\n');
    markdown = markdown.replace(/<li[^>]*>(.*?)<\/li>/gi, '- $1\n');

    // Convert paragraphs
    markdown = markdown.replace(/<p[^>]*>(.*?)<\/p>/gi, '$1\n\n');

    // Convert line breaks
    markdown = markdown.replace(/<br[^>]*>/gi, '\n');

    // Convert blockquotes
    markdown = markdown.replace(/<blockquote[^>]*>(.*?)<\/blockquote>/gis, '> $1\n\n');

    // Convert code blocks
    markdown = markdown.replace(/<pre[^>]*><code[^>]*>(.*?)<\/code><\/pre>/gis, '```\n$1\n```\n\n');
    markdown = markdown.replace(/<code[^>]*>(.*?)<\/code>/gi, '`$1`');

    // Clean up HTML entities and remaining tags
    markdown = markdown.replace(/&nbsp;/g, ' ');
    markdown = markdown.replace(/&amp;/g, '&');
    markdown = markdown.replace(/&lt;/g, '<');
    markdown = markdown.replace(/&gt;/g, '>');
    markdown = markdown.replace(/&quot;/g, '"');
    markdown = markdown.replace(/<[^>]*>/g, '');

    // Clean up extra whitespace
    markdown = markdown.replace(/\n{3,}/g, '\n\n').trim();

    return markdown;
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

