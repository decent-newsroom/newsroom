import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        identifier: String,
        type: String,
        decoded: String,
        fullMatch: String
    }

    static targets = ['container']

    async connect() {
      await this.fetchPreview();
    }

    async fetchPreview() {
        try {
            this.containerTarget.innerHTML = '<div class="text-center my-2"><div class="spinner-border spinner-border-sm text-secondary" role="status"></div> Loading preview...</div>';
            if (this.typeValue === 'url' && this.fullMatchValue) {
                // Fetch OG preview for plain URLs
                fetch("/og-preview/", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json"
                    },
                    body: JSON.stringify({ url: this.fullMatchValue })
                })
                .then(res => {
                    if (!res.ok) {
                        throw new Error(`HTTP error! status: ${res.status}`);
                    }
                    return res.text();
                })
                .then(data => {
                    this.containerTarget.innerHTML = data;
                })
                .catch(error => {
                    console.error("Error:", error);
                    this.containerTarget.innerHTML = `<div class="alert alert-warning">Unable to load OG preview for ${this.fullMatchValue}</div>`;
                });
            } else {
                // Fallback to Nostr preview
                const data = {
                  identifier: this.identifierValue,
                  type: this.typeValue,
                  decoded: this.decodedValue
                };
                fetch("/preview/", {
                  method: "POST",
                  headers: {
                    "Content-Type": "application/json"
                  },
                  body: JSON.stringify(data)
                })
                  .then(res => {
                    if (!res.ok) {
                      throw new Error(`HTTP error! status: ${res.status}`);
                    }
                    return res.text();
                  })
                  .then(data => {
                    this.containerTarget.innerHTML = data;
                  })
                  .catch(error => {
                    console.error("Error:", error);
                  });
            }
        } catch (error) {
            console.error('Error fetching Nostr preview:', error);
            this.containerTarget.innerHTML = `<div class="alert alert-warning">Unable to load preview for ${this.fullMatchValue}</div>`;
        }
    }
}
