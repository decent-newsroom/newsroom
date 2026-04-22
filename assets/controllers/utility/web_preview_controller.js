import { Controller } from '@hotwired/stimulus';

/**
 * Opt-in web preview loader.
 *
 * Renders a placeholder card for an external URL referenced from a
 * NIP-22 comment's `I`/`i` tag. Only when the user clicks the
 * "Load preview" CTA does it fetch metadata from `/api/web-preview`
 * and swap in the rich card (og:image + title + description).
 *
 * Kept behind an explicit action so the comment page never contacts
 * arbitrary third-party URLs on the reader's behalf without consent.
 */
export default class extends Controller {
    static values = {
        url: String,
        endpoint: String,
    };
    static targets = ['trigger', 'status'];

    async load(event) {
        event.preventDefault();

        if (this.loading) {
            return;
        }
        this.loading = true;

        if (this.hasTriggerTarget) {
            this.triggerTarget.disabled = true;
        }
        this.setStatus('Loading preview…');

        try {
            const url = new URL(this.endpointValue, window.location.origin);
            url.searchParams.set('url', this.urlValue);

            const res = await fetch(url.toString(), {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            });

            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }
            const data = await res.json();
            this.render(data);
        } catch (err) {
            this.setStatus(`Could not load preview (${err.message || err}).`);
            if (this.hasTriggerTarget) {
                this.triggerTarget.disabled = false;
            }
        } finally {
            this.loading = false;
        }
    }

    render(data) {
        const root = this.element;
        root.classList.remove('web-preview--placeholder');
        root.classList.add('web-preview--rich');

        // Build rich card markup (mirrors server-side template).
        const body = document.createElement('div');
        body.className = 'web-preview__body';

        const hostEl = document.createElement('div');
        hostEl.className = 'web-preview__host';
        hostEl.textContent = data.siteName || data.host || this.urlValue;
        body.appendChild(hostEl);

        if (data.title) {
            const t = document.createElement('div');
            t.className = 'web-preview__title';
            t.textContent = data.title;
            body.appendChild(t);
        }
        if (data.description) {
            const d = document.createElement('div');
            d.className = 'web-preview__description';
            d.textContent = data.description;
            body.appendChild(d);
        }
        const urlEl = document.createElement('div');
        urlEl.className = 'web-preview__url';
        urlEl.textContent = this.urlValue;
        body.appendChild(urlEl);

        // Replace placeholder body with the rich card, wrapped in a link.
        const link = document.createElement('a');
        link.href = this.urlValue;
        link.target = '_blank';
        link.rel = 'noopener noreferrer nofollow';
        link.className = 'web-preview__link';

        if (data.image) {
            const img = document.createElement('div');
            img.className = 'web-preview__image';
            img.setAttribute('role', 'presentation');
            img.style.backgroundImage = `url('${data.image.replace(/'/g, '%27')}')`;
            link.appendChild(img);
        }
        link.appendChild(body);

        root.replaceChildren(link);
    }

    setStatus(msg) {
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = msg;
        }
    }
}

