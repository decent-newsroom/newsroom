import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['form', 'publishButton', 'status'];
    static values = {
        publishUrl: String,
        csrfToken: String
    };

    connect() {
        console.log('Nostr publish controller connected');
        // Helpful debug to verify values are wired from Twig
        try {
            console.debug('[nostr-publish] publishUrl:', this.publishUrlValue || '(none)');
            console.debug('[nostr-publish] has csrfToken:', Boolean(this.csrfTokenValue));
        } catch (_) {}

        // Provide a sensible fallback if not passed via values
        if (!this.hasPublishUrlValue || !this.publishUrlValue) {
            this.publishUrlValue = '/api/article/publish';
        }
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
                window.location.href = `/article/d/${formData.slug}`;
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
        if (!form) {
            throw new Error('Form element not found');
        }

        const formData = new FormData(form);

        // Get content from Quill editor if available
        const quillEditor = document.querySelector('.ql-editor');
        let content = formData.get('editor[content]') || '';

        // Convert HTML to markdown (basic conversion)
        content = this.htmlToMarkdown(content);

        const title = formData.get('editor[title]') || '';
        const summary = formData.get('editor[summary]') || '';
        const image = formData.get('editor[image]') || '';
        const topicsString = formData.get('editor[topics]') || '';

        // Parse topics
        const topics = topicsString.split(',')
            .map(topic => topic.trim())
            .filter(topic => topic.length > 0)
            .map(topic => topic.startsWith('#') ? topic : `#${topic}`);

        // Generate slug from title
        const slug = this.generateSlug(title);

        return {
            title,
            summary,
            content,
            image,
            topics,
            slug
        };
    }

    async createNostrEvent(formData) {
        // Get user's public key
        const pubkey = await window.nostr.getPublicKey();

        // Create tags array
        const tags = [
            ['d', formData.slug], // NIP-33 replaceable event identifier
            ['title', formData.title],
            ['published_at', Math.floor(Date.now() / 1000).toString()]
        ];

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

        // Create the Nostr event (NIP-23 long-form content)
        const event = {
            kind: 30023, // Long-form content kind
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
        // This is a simplified version - you might want to use a proper library
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
        markdown = markdown.replace(/<u[^>]*>(.*?)<\/u>/gi, '_$1_');

        // Convert links
        markdown = markdown.replace(/<a[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/gi, '[$2]($1)');

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
        markdown = markdown.replace(/<[^>]*>/g, ''); // Remove any remaining HTML tags

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
            .replace(/[^a-z0-9\s-]/g, '') // Remove special characters
            .replace(/\s+/g, '-') // Replace spaces with hyphens
            .replace(/-+/g, '-') // Replace multiple hyphens with single
            .replace(/^-|-$/g, ''); // Remove leading/trailing hyphens
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
