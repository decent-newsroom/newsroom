import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
  static targets = ['status']
  static values = {
    staticUrls: Array,
    cacheName: String
  }

  connect() {
    // Check if we've shown an update prompt recently (within 1 hour)
    const lastPrompt = localStorage.getItem('sw-update-prompt-shown');
    const oneHourAgo = Date.now() - (60 * 60 * 1000);

    if (lastPrompt && parseInt(lastPrompt) > oneHourAgo) {
      this.updatePromptShown = true;
    } else {
      this.updatePromptShown = false;
    }

    if ('serviceWorker' in navigator) {
      this.loadStaticRoutes().then(() => {
        this.registerServiceWorker();
        this.setupServiceWorkerEventListeners();
      });
    } else {
      this.updateStatus('Service Worker not supported');
    }
  }

  async loadStaticRoutes() {
    try {
      const response = await fetch('/api/static-routes');
      if (response.ok) {
        const data = await response.json();
        this.staticUrlsValue = data.routes;
        this.cacheNameValue = data.cacheName;
        console.log('Loaded static routes for controller:', this.staticUrlsValue);
      } else {
        console.warn('Failed to load static routes from API');
      }
    } catch (error) {
      console.error('Error loading static routes:', error);
    }
  }

  async registerServiceWorker() {
    try {
      const registration = await navigator.serviceWorker.register('/service-worker.js');
      console.log('SW registered:', registration);

      // Check if there's actually a waiting service worker with new content
      if (registration.waiting && !this.updatePromptShown) {
        this.showUpdateAvailable(registration);
      }

      // Listen for service worker updates, but be more selective
      registration.addEventListener('updatefound', () => {
        const newWorker = registration.installing;

        newWorker.addEventListener('statechange', () => {
          // Only show update if:
          // 1. The worker is installed
          // 2. We have an active controller (not first install)
          // 3. Haven't shown prompt recently
          if (newWorker.state === 'installed' &&
              navigator.serviceWorker.controller &&
              !this.updatePromptShown) {

            // Double-check we haven't prompted recently
            const lastPrompt = localStorage.getItem('sw-update-prompt-shown');
            const thirtyMinutesAgo = Date.now() - (30 * 60 * 1000);

            if (!lastPrompt || parseInt(lastPrompt) < thirtyMinutesAgo) {
              // Add a delay to avoid immediate prompts during development
              setTimeout(() => {
                if (!this.updatePromptShown && registration.waiting) {
                  this.showUpdateAvailable(registration);
                }
              }, 3000);
            }
          }
        });
      });

      this.updateStatus('Service Worker registered successfully');
      await this.checkCacheStatus();
    } catch (error) {
      console.error('SW failed:', error);
      this.updateStatus('Service Worker registration failed');
    }
  }

  setupServiceWorkerEventListeners() {
    // Listen for messages from the service worker
    navigator.serviceWorker.addEventListener('message', (event) => {
      if (event.data && event.data.type) {
        switch (event.data.type) {
          case 'CACHE_UPDATED':
            this.updateStatus('Cache updated successfully');
            break;
          case 'CACHE_ERROR':
            this.updateStatus('Cache update failed');
            break;
        }
      }
    });
  }

  async checkCacheStatus() {
    if ('caches' in window) {
      try {
        const cacheNames = await caches.keys();
        const cacheName = this.cacheNameValue || 'newsroom-static';
        const hasStaticCache = cacheNames.some(name => name.includes(cacheName.split('-')[0] + '-' + cacheName.split('-')[1]));

        if (hasStaticCache) {
          const cache = await caches.open(cacheName);
          const cachedRequests = await cache.keys();
          this.updateStatus(`${cachedRequests.length} static pages cached`);
        } else {
          this.updateStatus('Static pages not yet cached');
        }
      } catch (error) {
        console.error('Cache status check failed:', error);
      }
    }
  }

  showUpdateAvailable(registration) {
    // Prevent multiple prompts
    if (this.updatePromptShown) {
      return;
    }

    this.updatePromptShown = true;

    // Store the timestamp to avoid showing again too soon
    localStorage.setItem('sw-update-prompt-shown', Date.now().toString());

    // Use a more user-friendly notification
    const shouldUpdate = confirm('A new version of the app is available. Would you like to update now?');

    if (shouldUpdate) {
      if (registration.waiting) {
        registration.waiting.postMessage({ type: 'SKIP_WAITING' });
        registration.waiting.addEventListener('statechange', (e) => {
          if (e.target.state === 'activated') {
            window.location.reload();
          }
        });
      }
    } else {
      // If user declines, don't ask again for 2 hours
      localStorage.setItem('sw-update-prompt-shown', (Date.now() + (2 * 60 * 60 * 1000)).toString());

      // Reset the flag after 2 hours
      setTimeout(() => {
        this.updatePromptShown = false;
      }, 2 * 60 * 60 * 1000);
    }
  }

  async clearCache() {
    try {
      const cacheNames = await caches.keys();
      await Promise.all(
        cacheNames.map(cacheName => caches.delete(cacheName))
      );
      this.updateStatus('All caches cleared');
      console.log('All caches cleared');
    } catch (error) {
      console.error('Failed to clear caches:', error);
      this.updateStatus('Failed to clear caches');
    }
  }

  async refreshCache() {
    if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
      navigator.serviceWorker.controller.postMessage({
        type: 'REFRESH_CACHE'
      });
      this.updateStatus('Cache refresh requested');
    }
  }

  async getCacheStatus() {
    if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
      return new Promise((resolve) => {
        const messageChannel = new MessageChannel();
        messageChannel.port1.onmessage = (event) => {
          if (event.data.type === 'CACHE_STATUS') {
            resolve(event.data.status);
          } else {
            resolve(null);
          }
        };

        navigator.serviceWorker.controller.postMessage(
          { type: 'GET_CACHE_STATUS' },
          [messageChannel.port2]
        );
      });
    }
    return null;
  }

  async displayCacheInfo() {
    const status = await this.getCacheStatus();
    if (status) {
      console.log('Cache Status:', status);
      let message = 'Cache Status:\n';
      for (const [cacheName, count] of Object.entries(status)) {
        message += `${cacheName}: ${count} items\n`;
      }
      this.updateStatus(message.trim());
    }
  }

  async preloadCriticalAssets() {
    if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
      // Get the manifest to find critical assets
      try {
        const manifestResponse = await fetch('/assets/manifest.json');
        if (manifestResponse.ok) {
          const manifest = await manifestResponse.json();

          // Critical assets that should be preloaded
          const criticalAssets = [
            'app.js',
            'bootstrap.js',
            'styles/app.css',
            'styles/theme.css',
            'styles/layout.css',
            'styles/fonts.css'
          ];

          const assetsToPreload = criticalAssets
            .map(asset => manifest[asset])
            .filter(Boolean);

          // Trigger preloading by making requests
          const preloadPromises = assetsToPreload.map(assetUrl =>
            fetch(assetUrl, { mode: 'no-cors' }).catch(() => {
              console.warn('Failed to preload:', assetUrl);
            })
          );

          await Promise.all(preloadPromises);
          this.updateStatus(`Preloaded ${assetsToPreload.length} critical assets`);
          console.log('Preloaded assets:', assetsToPreload);
        }
      } catch (error) {
        console.error('Failed to preload critical assets:', error);
      }
    }
  }

  // Enhanced cache management methods
  async clearAssetsCache() {
    if ('caches' in window) {
      try {
        const deleted = await caches.delete('newsroom-assets-v1');
        if (deleted) {
          this.updateStatus('Assets cache cleared');
          console.log('Assets cache cleared');
        } else {
          this.updateStatus('Assets cache not found');
        }
      } catch (error) {
        console.error('Failed to clear assets cache:', error);
        this.updateStatus('Failed to clear assets cache');
      }
    }
  }

  async clearStaticCache() {
    if ('caches' in window) {
      try {
        const deleted = await caches.delete('newsroom-static-v1');
        if (deleted) {
          this.updateStatus('Static cache cleared');
          console.log('Static cache cleared');
        } else {
          this.updateStatus('Static cache not found');
        }
      } catch (error) {
        console.error('Failed to clear static cache:', error);
        this.updateStatus('Failed to clear static cache');
      }
    }
  }

  updateStatus(message) {
    if (this.hasStatusTarget) {
      this.statusTarget.textContent = message;
      console.log('SW Status:', message);
    }
  }

  // Action methods that can be called from the template
  clearCacheAction() {
    this.clearCache();
  }

  refreshCacheAction() {
    this.refreshCache();
  }

  // Enhanced action methods
  clearAssetsCacheAction() {
    this.clearAssetsCache();
  }

  clearStaticCacheAction() {
    this.clearStaticCache();
  }

  displayCacheInfoAction() {
    this.displayCacheInfo();
  }

  preloadCriticalAssetsAction() {
    this.preloadCriticalAssets();
  }
}
