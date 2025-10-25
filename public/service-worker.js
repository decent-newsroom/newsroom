// Define cache names and versions
const CACHE_NAME = 'newsroom-v1';
const STATIC_CACHE = 'newsroom-static-v1';
const ASSETS_CACHE = 'newsroom-assets-v1';
const RUNTIME_CACHE = 'newsroom-runtime-v1';

// Assets to cache immediately on install
const PRECACHE_ASSETS = [
  '/',
  '/assets/app.js',
  '/assets/bootstrap.js',
  '/assets/icons/favicon.ico',
  '/assets/icons/web-app-manifest-192x192.png',
  '/assets/icons/web-app-manifest-512x512.png'
];

// Define what should be cached with different strategies
const CACHE_STRATEGIES = {
  // CSS files - cache first (they change infrequently)
  css: {
    pattern: /\.css$/,
    strategy: 'cacheFirst',
    cacheName: ASSETS_CACHE,
    maxAge: 30 * 24 * 60 * 60 * 1000 // 30 days
  },
  // JS files - cache first with network fallback
  js: {
    pattern: /\.js$/,
    strategy: 'cacheFirst',
    cacheName: ASSETS_CACHE,
    maxAge: 30 * 24 * 60 * 60 * 1000 // 30 days
  },
  // Fonts - cache first (rarely change)
  fonts: {
    pattern: /\.(woff2?|ttf|eot)$/,
    strategy: 'cacheFirst',
    cacheName: ASSETS_CACHE,
    maxAge: 365 * 24 * 60 * 60 * 1000 // 1 year
  },
  // Images and icons - cache first
  images: {
    pattern: /\.(png|jpg|jpeg|gif|svg|ico)$/,
    strategy: 'cacheFirst',
    cacheName: ASSETS_CACHE,
    maxAge: 30 * 24 * 60 * 60 * 1000 // 30 days
  },
  // Nostr event pages - cache first with background update
  nostrEvents: {
    pattern: /^https?.*\/e\/(note|nevent)1.*/,
    strategy: 'staleWhileRevalidate',
    cacheName: RUNTIME_CACHE,
    maxAge: 10 * 60 * 1000 // 10 minutes
  },
  // Nostr articles, profiles - cache first with background update
  nostrArticles: {
    pattern: /^https?.*\/(article|p)\/*/,
    strategy: 'staleWhileRevalidate',
    cacheName: RUNTIME_CACHE,
    maxAge: 10 * 60 * 1000 // 10 minutes
  },
  // Static pages
  pages: {
    pattern: /^https?.*\/(about|roadmap|tos|landing|unfold)$/,
    strategy: 'staleWhileRevalidate',
    cacheName: STATIC_CACHE,
    maxAge: 24 * 60 * 60 * 1000 // 1 day
  },
  // API calls - network first
  api: {
    pattern: /\/api\//,
    strategy: 'networkFirst',
    cacheName: RUNTIME_CACHE,
    maxAge: 5 * 60 * 1000 // 5 minutes
  }
};

self.addEventListener('install', async (event) => {
  console.log('Service Worker installing...');

  event.waitUntil(
    (async () => {
      try {
        // Cache core assets immediately
        const cache = await caches.open(ASSETS_CACHE);
        console.log('Precaching core assets...');

        // Get the current asset manifest to cache the actual versioned files
        const manifestResponse = await fetch('/assets/manifest.json');
        if (manifestResponse.ok) {
          const manifest = await manifestResponse.json();
          const versionedAssets = PRECACHE_ASSETS.map(asset => {
            const logicalPath = asset.replace('/assets/', '');
            return manifest[logicalPath] || asset;
          });

          await cache.addAll(versionedAssets);
          console.log('Precached assets:', versionedAssets);
        } else {
          // Fallback to original assets if manifest not available
          await cache.addAll(PRECACHE_ASSETS);
        }

        // Activate immediately
        await self.skipWaiting();
      } catch (error) {
        console.error('Precaching failed:', error);
      }
    })()
  );
});

self.addEventListener('activate', (event) => {
  console.log('Service Worker activating...');

  event.waitUntil(
    (async () => {
      // Clean up old caches
      const cacheNames = await caches.keys();
      const oldCaches = cacheNames.filter(name =>
        name.startsWith('newsroom-') &&
        ![CACHE_NAME, STATIC_CACHE, ASSETS_CACHE, RUNTIME_CACHE].includes(name)
      );

      await Promise.all(oldCaches.map(name => caches.delete(name)));
      console.log('Cleaned up old caches:', oldCaches);

      // Take control of all clients
      await self.clients.claim();
    })()
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  // Skip non-GET requests
  if (request.method !== 'GET') return;

  // Skip chrome-extension and other non-http requests
  if (!url.protocol.startsWith('http')) return;

  // Find matching cache strategy
  const strategy = findCacheStrategy(request.url);

  if (strategy) {
    event.respondWith(handleRequest(request, strategy));
  }
});

self.addEventListener('message', (event) => {
  const { type, data } = event.data || {};

  switch (type) {
    case 'SKIP_WAITING':
      self.skipWaiting();
      break;

    case 'REFRESH_CACHE':
      handleCacheRefresh();
      break;

    case 'GET_CACHE_STATUS':
      handleCacheStatus(event);
      break;
  }
});

function findCacheStrategy(url) {
  for (const [name, config] of Object.entries(CACHE_STRATEGIES)) {
    if (config.pattern.test(url)) {
      return config;
    }
  }
  return null;
}

async function handleRequest(request, strategy) {
  const cache = await caches.open(strategy.cacheName);

  switch (strategy.strategy) {
    case 'cacheFirst':
      return cacheFirst(request, cache, strategy);
    case 'networkFirst':
      return networkFirst(request, cache, strategy);
    case 'staleWhileRevalidate':
      return staleWhileRevalidate(request, cache, strategy);
    default:
      return fetch(request);
  }
}

async function cacheFirst(request, cache, strategy) {
  try {
    // Check cache first
    const cachedResponse = await cache.match(request);
    if (cachedResponse && !isExpired(cachedResponse, strategy.maxAge)) {
      return cachedResponse;
    }

    // Fetch from network
    const networkResponse = await fetch(request);

    if (networkResponse.ok) {
      // Clone and cache the response
      const responseToCache = networkResponse.clone();
      await cache.put(request, responseToCache);
    }

    return networkResponse;
  } catch (error) {
    console.error('Cache first strategy failed:', error);
    // Return cached version even if expired as fallback
    const cachedResponse = await cache.match(request);
    if (cachedResponse) {
      return cachedResponse;
    }
    throw error;
  }
}

async function networkFirst(request, cache, strategy) {
  try {
    // Try network first
    const networkResponse = await fetch(request);

    if (networkResponse.ok) {
      // Cache successful responses
      const responseToCache = networkResponse.clone();
      await cache.put(request, responseToCache);
    }

    return networkResponse;
  } catch (error) {
    console.log('Network failed, trying cache:', error.message);
    // Fallback to cache
    const cachedResponse = await cache.match(request);
    if (cachedResponse) {
      return cachedResponse;
    }
    throw error;
  }
}

async function staleWhileRevalidate(request, cache, strategy) {
  const cachedResponse = await cache.match(request);

  // Always fetch in background to update cache
  const fetchPromise = fetch(request).then(networkResponse => {
    if (networkResponse.ok) {
      cache.put(request, networkResponse.clone());
    }
    return networkResponse;
  }).catch(() => {
    // Ignore background fetch failures
  });

  // Return cached version immediately if available
  if (cachedResponse && !isExpired(cachedResponse, strategy.maxAge)) {
    return cachedResponse;
  }

  // Wait for network if no cache or expired
  return fetchPromise;
}

function isExpired(response, maxAge) {
  if (!maxAge) return false;

  const dateHeader = response.headers.get('date');
  if (!dateHeader) return false;

  const responseTime = new Date(dateHeader).getTime();
  return (Date.now() - responseTime) > maxAge;
}

async function handleCacheRefresh() {
  try {
    // Clear all caches
    const cacheNames = await caches.keys();
    await Promise.all(cacheNames.map(name => caches.delete(name)));

    // Reinstall with fresh cache
    const cache = await caches.open(ASSETS_CACHE);
    await cache.addAll(PRECACHE_ASSETS);

    // Notify all clients
    const clients = await self.clients.matchAll();
    clients.forEach(client => {
      client.postMessage({ type: 'CACHE_UPDATED' });
    });

    console.log('Cache refreshed successfully');
  } catch (error) {
    console.error('Cache refresh failed:', error);
    // Notify clients of error
    const clients = await self.clients.matchAll();
    clients.forEach(client => {
      client.postMessage({ type: 'CACHE_ERROR', error: error.message });
    });
  }
}

async function handleCacheStatus(event) {
  try {
    const cacheNames = await caches.keys();
    const status = {};

    for (const cacheName of cacheNames) {
      const cache = await caches.open(cacheName);
      const keys = await cache.keys();
      status[cacheName] = keys.length;
    }

    event.ports[0].postMessage({ type: 'CACHE_STATUS', status });
  } catch (error) {
    event.ports[0].postMessage({ type: 'CACHE_STATUS_ERROR', error: error.message });
  }
}
