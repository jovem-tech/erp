const CACHE_VERSION = 'sistema-erp-chat-v1';
const PAGE_CACHE = `pages-${CACHE_VERSION}`;
const ASSET_CACHE = `assets-${CACHE_VERSION}`;

function isSameOrigin(requestUrl) {
  return requestUrl.origin === self.location.origin;
}

async function cacheFirst(request) {
  const cache = await caches.open(ASSET_CACHE);
  const cachedResponse = await cache.match(request);

  if (cachedResponse) {
    return cachedResponse;
  }

  const response = await fetch(request);
  if (response.ok) {
    cache.put(request, response.clone());
  }

  return response;
}

async function networkFirst(request) {
  const cache = await caches.open(PAGE_CACHE);

  try {
    const response = await fetch(request);
    if (response.ok) {
      cache.put(request, response.clone());
    }
    return response;
  } catch (error) {
    const cachedResponse = await cache.match(request);
    if (cachedResponse) {
      return cachedResponse;
    }

    const fallback = await cache.match('/');
    if (fallback) {
      return fallback;
    }

    throw error;
  }
}

self.addEventListener('install', (event) => {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const keepCaches = new Set([PAGE_CACHE, ASSET_CACHE]);
      const cacheNames = await caches.keys();

      await Promise.all(
        cacheNames.filter((cacheName) => !keepCaches.has(cacheName)).map((cacheName) => caches.delete(cacheName))
      );

      await self.clients.claim();
    })()
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;

  if (request.method !== 'GET') {
    return;
  }

  const requestUrl = new URL(request.url);

  // /api/ (backend central) e a conexao do Echo/Reverb nunca passam por cache do SW.
  if (!isSameOrigin(requestUrl) || requestUrl.pathname.startsWith('/api/')) {
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(networkFirst(request));
    return;
  }

  if (requestUrl.pathname.startsWith('/_next/')) {
    return;
  }

  if (
    request.destination === 'style' ||
    request.destination === 'script' ||
    request.destination === 'image' ||
    request.destination === 'font' ||
    requestUrl.pathname === '/icon' ||
    requestUrl.pathname === '/apple-icon' ||
    requestUrl.pathname === '/apple-touch-icon.png' ||
    requestUrl.pathname === '/manifest.webmanifest' ||
    requestUrl.pathname === '/favicon.ico' ||
    requestUrl.pathname === '/favicon.svg'
  ) {
    event.respondWith(cacheFirst(request));
  }
});
