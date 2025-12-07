import { Controller } from '@hotwired/stimulus';
import { getSigner } from '../nostr/signer_manager.js';

export default class extends Controller {
  static targets = ['dropdown', 'status', 'menu'];
  static values = {
    coordinate: String,
    lists: String,
    publishUrl: String,
    csrfToken: String
  };

  connect() {
    // Close dropdown when clicking outside
    this.boundCloseOnClickOutside = this.closeOnClickOutside.bind(this);
    document.addEventListener('click', this.boundCloseOnClickOutside);
  }

  disconnect() {
    document.removeEventListener('click', this.boundCloseOnClickOutside);
  }

  toggleDropdown(event) {
    event.preventDefault();
    event.stopPropagation();

    if (this.hasMenuTarget) {
      const isOpen = this.menuTarget.classList.contains('show');
      if (isOpen) {
        this.closeDropdown();
      } else {
        this.openDropdown();
      }
    }
  }

  openDropdown() {
    if (this.hasMenuTarget) {
      this.menuTarget.classList.add('show');
      if (this.hasDropdownTarget) {
        this.dropdownTarget.setAttribute('aria-expanded', 'true');
      }
    }
  }

  closeDropdown() {
    if (this.hasMenuTarget) {
      this.menuTarget.classList.remove('show');
      if (this.hasDropdownTarget) {
        this.dropdownTarget.setAttribute('aria-expanded', 'false');
      }
    }
  }

  closeOnClickOutside(event) {
    if (!this.element.contains(event.target)) {
      this.closeDropdown();
    }
  }

  async addToList(event) {
    event.preventDefault();
    event.stopPropagation();

    const slug = event.currentTarget.dataset.slug;
    const title = event.currentTarget.dataset.title;

    let signer;
    try {
      signer = await getSigner();
    } catch (e) {
      this.showError('No Nostr signer available. Please connect Amber or install a Nostr signer extension.');
      return;
    }

    try {
      this.showStatus(`Adding to "${title}"...`);

      // Build the event skeleton for the updated reading list
      const lists = JSON.parse(this.listsValue || '[]');
      const selectedList = lists.find(l => l.slug === slug);
      if (!selectedList) {
        this.showError('Reading list not found');
        return;
      }
      if (selectedList.articles && selectedList.articles.includes(this.coordinateValue)) {
        this.showSuccess(`Already in "${title}"`);
        setTimeout(() => {
          this.hideStatus();
          this.closeDropdown();
        }, 2000);
        return;
      }
      const eventSkeleton = await this.buildReadingListEvent(selectedList);

      // Sign the event
      this.showStatus(`Signing update to "${title}"...`);
      const signedEvent = await signer.signEvent(eventSkeleton);

      // Publish the event
      this.showStatus(`Publishing update...`);
      await this.publishEvent(signedEvent);

      this.showSuccess(`✓ Added to "${title}"`);

      // Close dropdown after success and reload to update the UI
      setTimeout(() => {
        this.hideStatus();
        this.closeDropdown();
        // Reload the page to show updated state
        window.location.reload();
      }, 1500);

    } catch (error) {
      console.error('Error adding to reading list:', error);
      this.showError(error.message || 'Failed to add article');
    }
  }

  async buildReadingListEvent(listData) {
    const pubkey = await window.nostr.getPublicKey();

    // Build tags array
    const tags = [];
    tags.push(['d', listData.slug]);
    tags.push(['title', listData.title]);

    if (listData.summary) {
      tags.push(['summary', listData.summary]);
    }

    // Find type tag and duplicate that (there should only be one)
    if (listData.eventJson) {
      const existingTags = JSON.parse(listData.eventJson);
      const typeTag = existingTags.find(t => t[0] === 'type');
      tags.push(typeTag || ['type', 'reading_list']);
    }

    // Add existing articles (avoid duplicates)
    const articleSet = new Set();

    // Add the new article first
    if (this.coordinateValue) {
      articleSet.add(this.coordinateValue);
    }

    if (listData.eventJson) {
      const existingTags = JSON.parse(listData.eventJson);
      const existingA = existingTags.filter(t => t[0] === 'a').map(t => t[1]);
      existingA.forEach(coord => {
        if (coord && typeof coord === 'string') {
          articleSet.add(coord);
        }
      });
    } else if (listData.articles && Array.isArray(listData.articles)) {
      listData.articles.forEach(coord => {
        if (coord && typeof coord === 'string') {
          articleSet.add(coord);
        }
      });
    }

    // Convert set to tags
    articleSet.forEach(coord => {
      tags.push(['a', coord]);
    });

    return {
      kind: 30040,
      created_at: Math.floor(Date.now() / 1000),
      tags: tags,
      content: '',
      pubkey: pubkey
    };
  }

  async publishEvent(signedEvent) {
    const response = await fetch(this.publishUrlValue, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': this.csrfTokenValue,
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({ event: signedEvent })
    });

    if (!response.ok) {
      const data = await response.json().catch(() => ({}));
      throw new Error(data.error || `HTTP ${response.status}`);
    }

    return response.json();
  }

  showStatus(message) {
    if (this.hasStatusTarget) {
      this.statusTarget.className = 'alert alert-info small mt-2 mb-0';
      this.statusTarget.textContent = message;
      this.statusTarget.style.display = 'block';
    }
  }

  showSuccess(message) {
    if (this.hasStatusTarget) {
      this.statusTarget.className = 'alert alert-success small mt-2 mb-0';
      this.statusTarget.textContent = message;
      this.statusTarget.style.display = 'block';
    }
  }

  showError(message) {
    if (this.hasStatusTarget) {
      this.statusTarget.className = 'alert alert-danger small mt-2 mb-0';
      this.statusTarget.textContent = message;
      this.statusTarget.style.display = 'block';
    }
  }

  hideStatus() {
    if (this.hasStatusTarget) {
      this.statusTarget.style.display = 'none';
    }
  }
}
