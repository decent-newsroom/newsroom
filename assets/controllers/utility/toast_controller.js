import { Controller } from '@hotwired/stimulus';

/**
 * Central toast notification controller
 *
 * Usage from other controllers:
 *
 * // Get the toast controller instance
 * const toastController = this.application.getControllerForElementAndIdentifier(
 *   document.querySelector('[data-controller~="utility--toast"]'),
 *   'utility--toast'
 * );
 *
 * // Show a toast
 * toastController.show('Success!', 'success');
 * toastController.show('Error occurred', 'danger');
 * toastController.show('Processing...', 'info');
 * toastController.show('Warning!', 'warning');
 *
 * // Or use the global helper (recommended)
 * window.showToast('Success!', 'success');
 */
export default class extends Controller {
  static targets = ['container'];

  connect() {
    console.log('Toast controller connected');
    this.queue = [];
    this.currentToast = null;
    this.isProcessing = false;

    // Expose globally for easy access from any controller
    window.showToast = (message, type = 'info', duration = 4000) => {
      this.show(message, type, duration);
    };
  }

  disconnect() {
    // Clean up global reference
    if (window.showToast) {
      delete window.showToast;
    }
  }

  /**
   * Show a toast notification
   * @param {string} message - The message to display
   * @param {string} type - Type of toast: 'success', 'danger', 'warning', 'info'
   * @param {number} duration - How long to show the toast in milliseconds (default: 4000)
   */
  show(message, type = 'info', duration = 4000) {
    this.queue.push({ message, type, duration });
    this.processQueue();
  }

  /**
   * Process the toast queue one at a time
   */
  processQueue() {
    // If already showing a toast, wait
    if (this.isProcessing) {
      return;
    }

    // If queue is empty, nothing to do
    if (this.queue.length === 0) {
      return;
    }

    this.isProcessing = true;
    const { message, type, duration } = this.queue.shift();

    // Create toast element
    const toast = this.createToastElement(message, type);
    this.containerTarget.appendChild(toast);
    this.currentToast = toast;

    // Trigger animation after a small delay (for CSS transition)
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        toast.classList.add('toast--show');
      });
    });

    // Auto-dismiss after duration
    setTimeout(() => {
      this.dismissToast(toast);
    }, duration);
  }

  /**
   * Create a toast DOM element
   * @param {string} message
   * @param {string} type
   * @returns {HTMLElement}
   */
  createToastElement(message, type) {
    const toast = document.createElement('div');
    toast.className = `toast toast--${type}`;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'polite');
    toast.setAttribute('aria-atomic', 'true');

    const content = document.createElement('div');
    content.className = 'toast__content';
    content.textContent = message;

    const closeButton = document.createElement('button');
    closeButton.className = 'toast__close';
    closeButton.setAttribute('type', 'button');
    closeButton.setAttribute('aria-label', 'Close');
    closeButton.innerHTML = '&times;';
    closeButton.addEventListener('click', () => this.dismissToast(toast));

    toast.appendChild(content);
    toast.appendChild(closeButton);

    return toast;
  }

  /**
   * Dismiss a toast with animation
   * @param {HTMLElement} toast
   */
  dismissToast(toast) {
    if (!toast || !toast.parentNode) {
      this.isProcessing = false;
      this.processQueue();
      return;
    }

    // Start fade out animation
    toast.classList.remove('toast--show');
    toast.classList.add('toast--hide');

    // Remove from DOM after animation completes
    setTimeout(() => {
      if (toast.parentNode) {
        toast.remove();
      }
      this.currentToast = null;
      this.isProcessing = false;

      // Process next toast in queue
      this.processQueue();
    }, 300); // Match CSS transition duration
  }

  /**
   * Clear all toasts immediately
   */
  clearAll() {
    this.queue = [];
    const toasts = this.containerTarget.querySelectorAll('.toast');
    toasts.forEach(toast => {
      toast.remove();
    });
    this.currentToast = null;
    this.isProcessing = false;
  }
}

