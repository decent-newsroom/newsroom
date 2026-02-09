import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ["dialog", "dropArea", "fileInput", "progress", "error", "provider"];

  // Unicode-safe base64 encoder
  base64Encode(str) {
      try {
          return btoa(unescape(encodeURIComponent(str)));
      } catch (_) {
          return btoa(str);
      }
  }

  openDialog() {
      this.dialogTarget.classList.add('active');
      this.clearError();
      this.hideProgress();
  }

  closeDialog() {
      this.dialogTarget.classList.remove('active');
      this.clearError();
      this.hideProgress();
  }

  connect() {
      this.dropAreaTarget.addEventListener('click', () => this.fileInputTarget.click());
      this.fileInputTarget.addEventListener('change', (e) => this.handleFile(e.target.files[0]));
      this.dropAreaTarget.addEventListener('dragover', (e) => {
          e.preventDefault();
          this.dropAreaTarget.classList.add('dragover');
      });
      this.dropAreaTarget.addEventListener('dragleave', (e) => {
          e.preventDefault();
          this.dropAreaTarget.classList.remove('dragover');
      });
      this.dropAreaTarget.addEventListener('drop', (e) => {
          e.preventDefault();
          this.dropAreaTarget.classList.remove('dragover');
          if (e.dataTransfer.files.length > 0) {
              this.handleFile(e.dataTransfer.files[0]);
          }
      });
      // Ensure initial visibility states
      this.hideProgress();
      this.clearError();
  }

  async handleFile(file) {
      if (!file) return;
      this.clearError();
      this.showProgress('Preparing upload...');
      try {
          // NIP98: get signed HTTP Auth event from window.nostr
          if (!window.nostr || !window.nostr.signEvent) {
              this.showError('Nostr extension not found.');
              return;
          }
          // Request pubkey first - required by some extensions (e.g., nos2x-fox) to determine active profile
          let pubkey;
          try {
              pubkey = await window.nostr.getPublicKey();
          } catch (e) {
              this.showError('Failed to get public key: ' + e.message);
              return;
          }
          // Determine provider
          const provider = this.providerTarget.value;

          // Map provider -> upstream endpoint used for signing the NIP-98 event
          const upstreamMap = {
              nostrbuild: 'https://nostr.build/nip96/upload',
              nostrcheck: 'https://nostrcheck.me/api/v2/media',
              sovbit: 'https://files.sovbit.host/api/v2/media',
          };
          const upstreamEndpoint = upstreamMap[provider] || upstreamMap['nostrcheck'];

          // Backend proxy endpoint to avoid third-party CORS
          const proxyEndpoint = `/api/image-upload/${provider}`;

          const event = {
              kind: 27235,
              created_at: Math.floor(Date.now() / 1000),
              pubkey: pubkey,
              tags: [
                  ["u", upstreamEndpoint],
                  ["method", "POST"]
              ],
              content: ""
          };
          const signed = await window.nostr.signEvent(event);
          const signedJson = JSON.stringify(signed);
          const authHeader = 'Nostr ' + this.base64Encode(signedJson);
          // Prepare form data
          const formData = new FormData();
          formData.append('uploadtype', 'media');
          formData.append('file', file);
          this.showProgress('Uploading...');
          // Upload to backend proxy
          const response = await fetch(proxyEndpoint, {
              method: 'POST',
              headers: {
                  'Authorization': authHeader
              },
              body: formData
          });
          const result = await response.json().catch(() => ({}));
          if (!response.ok || result.status !== 'success' || !result.url) {
              this.showError(result.message || `Upload failed (HTTP ${response.status})`);
              return;
          }
          this.setImageField(result.url);
          this.showProgress('Upload successful!');
          // clear file input so subsequent identical uploads work
          if (this.hasFileInputTarget) this.fileInputTarget.value = '';
          setTimeout(() => this.closeDialog(), 1000);
      } catch (e) {
          this.showError('Upload error: ' + (e.message || e));
      }
  }

  setImageField(url) {
      // Find the image input in the form and set its value
      const imageInput = document.querySelector('input[name$="[image]"]');
      if (imageInput) {
          imageInput.value = url;
          imageInput.dispatchEvent(new Event('input', { bubbles: true }));
      }
  }

  // Helpers to manage UI visibility and content
  showProgress(text = '') {
    if (this.hasProgressTarget) {
      this.progressTarget.style.display = 'block';
      this.progressTarget.textContent = text;
    }
  }

  hideProgress() {
    if (this.hasProgressTarget) {
      this.progressTarget.style.display = 'none';
      this.progressTarget.textContent = '';
    }
  }

  showError(message) {
    if (this.hasErrorTarget) {
      this.errorTarget.textContent = message;
      this.errorTarget.style.display = 'block';
      // make assistive tech aware
      this.errorTarget.setAttribute('role', 'alert');
      this.hideProgress();
      // clear file input so user can re-select the same file
      if (this.hasFileInputTarget) this.fileInputTarget.value = '';
    }
  }

  clearError() {
    if (this.hasErrorTarget) {
      this.errorTarget.textContent = '';
      this.errorTarget.style.display = 'none';
      this.errorTarget.removeAttribute('role');
    }
  }
}
