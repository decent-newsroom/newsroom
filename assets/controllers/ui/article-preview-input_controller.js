import { Controller } from '@hotwired/stimulus';

/**
 * Fetches and displays article preview (title + author) when a coordinate/naddr is entered
 * Also supports static display elements with data-coordinate attribute
 */
export default class extends Controller {
  static targets = ['input', 'preview'];
  static values = {
    url: String,  // API endpoint for fetching article info
  };

  connect() {
    // Check initial value on connect for inputs
    this.inputTargets.forEach((input) => this.fetchPreview(input));

    // Also fetch previews for static display elements
    this.element.querySelectorAll('[data-coordinate]').forEach((el) => {
      this.fetchStaticPreview(el);
    });
  }

  inputTargetConnected(input) {
    // When a new input is dynamically added, check if it has a value
    this.fetchPreview(input);
  }

  async onInputChange(event) {
    const input = event.target;
    this.fetchPreview(input);
  }

  async fetchStaticPreview(element) {
    const coordinate = element.dataset.coordinate;
    if (!coordinate) return;

    element.innerHTML = '<small class="text-muted">Loading...</small>';

    try {
      const response = await fetch(this.urlValue, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        body: JSON.stringify({ coordinate }),
      });

      if (!response.ok) {
        throw new Error('Failed to fetch');
      }

      const data = await response.json();

      if (data.title) {
        const author = data.author || 'Unknown author';
        element.innerHTML = `<strong>${this.escapeHtml(data.title)}</strong> <span class="text-muted">by ${this.escapeHtml(author)}</span>`;
      } else if (data.error) {
        element.innerHTML = `<small class="text-warning">${this.escapeHtml(data.error)}</small>`;
      } else {
        element.innerHTML = '<small class="text-muted">Article not found locally</small>';
      }
    } catch (error) {
      element.innerHTML = '<small class="text-muted">Could not load preview</small>';
    }
  }

  async fetchPreview(input) {
    const value = input.value.trim();
    const previewEl = this.getPreviewElement(input);

    if (!value) {
      if (previewEl) {
        previewEl.innerHTML = '';
      }
      return;
    }

    // Show loading state
    if (previewEl) {
      previewEl.innerHTML = '<small class="text-muted">Loading...</small>';
    }

    try {
      const response = await fetch(this.urlValue, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        body: JSON.stringify({ coordinate: value }),
      });

      if (!response.ok) {
        throw new Error('Failed to fetch');
      }

      const data = await response.json();

      if (previewEl) {
        if (data.title) {
          const author = data.author || 'Unknown author';
          previewEl.innerHTML = `<small class="text-success"><strong>${this.escapeHtml(data.title)}</strong> by ${this.escapeHtml(author)}</small>`;
        } else if (data.error) {
          previewEl.innerHTML = `<small class="text-warning">${this.escapeHtml(data.error)}</small>`;
        } else {
          previewEl.innerHTML = '<small class="text-muted">Article not found locally</small>';
        }
      }
    } catch (error) {
      if (previewEl) {
        previewEl.innerHTML = '<small class="text-muted">Could not load preview</small>';
      }
    }
  }

  getPreviewElement(input) {
    // Find the preview container for this input (sibling element)
    const wrapper = input.closest('.article-input-wrapper') || input.parentElement;
    let preview = wrapper.querySelector('.article-preview-container');

    // Fallback: create one if it doesn't exist
    if (!preview) {
      preview = document.createElement('div');
      preview.className = 'article-preview-container mt-1';
      input.insertAdjacentElement('afterend', preview);
    }

    return preview;
  }

  escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }
}
