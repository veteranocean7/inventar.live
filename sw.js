/**
 * Service Worker pentru Inventar.live
 * Faza 3: Offline Write + PWA Install Assistant
 * Versiune: 2.1.0
 */

const CACHE_NAME = 'inventar-cache-v2.1';
const STATIC_CACHE_NAME = 'inventar-static-v2.1';
const API_CACHE_NAME = 'inventar-api-v2.1';
const IMAGES_CACHE_NAME = 'inventar-images-v2.1';

// Limite cache
const MAX_IMAGES_CACHE = 100; // Maximum imagini în cache
const MAX_IMAGE_SIZE = 5 * 1024 * 1024; // 5MB per imagine

// Resurse statice pentru precache
const STATIC_ASSETS = [
  '/',
  '/index.php',
  '/login.php',
  '/css/style.css',
  '/css/style-telefon.css',
  '/css/notifications.css',
  '/js/notifications.js',
  '/js/idb-manager.js',
  '/js/offline-sync.js',
  '/js/offline-operations.js',
  '/js/pending-operations-ui.js',
  '/js/pwa-install-assistant.js',
  '/manifest.json',
  '/offline.html',
  '/placeholder..png'
];

// API endpoints pentru cache
const API_ENDPOINTS = [
  '/api_inventar.php'
];

// Instalare Service Worker
self.addEventListener('install', (event) => {
  console.log('[SW] Instalare Service Worker v2.1.0');

  event.waitUntil(
    caches.open(STATIC_CACHE_NAME)
      .then((cache) => {
        console.log('[SW] Precaching static assets');
        // Încercăm să adăugăm fiecare resursă individual pentru a evita eșecul complet
        return Promise.allSettled(
          STATIC_ASSETS.map(url =>
            cache.add(url).catch(err => {
              console.warn(`[SW] Nu s-a putut cache: ${url}`, err);
            })
          )
        );
      })
      .then(() => {
        console.log('[SW] Precaching complet');
        return self.skipWaiting();
      })
  );
});

// Activare Service Worker
self.addEventListener('activate', (event) => {
  console.log('[SW] Activare Service Worker');

  event.waitUntil(
    caches.keys()
      .then((cacheNames) => {
        return Promise.all(
          cacheNames.map((cacheName) => {
            // Șterge cache-uri vechi
            if (cacheName !== CACHE_NAME && cacheName !== STATIC_CACHE_NAME) {
              console.log('[SW] Ștergere cache vechi:', cacheName);
              return caches.delete(cacheName);
            }
          })
        );
      })
      .then(() => {
        console.log('[SW] Preluare control clienți');
        return self.clients.claim();
      })
  );
});

// Interceptare cereri (Fetch)
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Ignoră cererile non-GET
  if (request.method !== 'GET') {
    return;
  }

  // Ignoră cererile către alte domenii
  if (url.origin !== location.origin) {
    return;
  }

  // Strategii diferite pentru diferite tipuri de resurse

  // 1. CSS, JS, imagini statice - Cache First
  if (isStaticAsset(url.pathname)) {
    event.respondWith(cacheFirst(request));
    return;
  }

  // 2. Pagini PHP - Network First (cu fallback offline)
  if (url.pathname.endsWith('.php') || url.pathname === '/') {
    event.respondWith(networkFirst(request));
    return;
  }

  // 3. Imagini din inventar - Cache on demand
  if (isInventoryImage(url.pathname)) {
    event.respondWith(cacheFirst(request));
    return;
  }

  // Default: Network First
  event.respondWith(networkFirst(request));
});

/**
 * Verifică dacă e resursă statică (CSS, JS, fonturi)
 */
function isStaticAsset(pathname) {
  return /\.(css|js|woff|woff2|ttf|eot)$/i.test(pathname);
}

/**
 * Verifică dacă e imagine din inventar
 */
function isInventoryImage(pathname) {
  return pathname.includes('/imagini_obiecte/') ||
         pathname.includes('/imagini_decupate/') ||
         /\.(png|jpg|jpeg|gif|webp|svg)$/i.test(pathname);
}

/**
 * Strategie: Cache First
 * Caută în cache, dacă nu există, ia de pe rețea și salvează în cache
 */
async function cacheFirst(request) {
  const cachedResponse = await caches.match(request);

  if (cachedResponse) {
    console.log('[SW] Cache hit:', request.url);
    return cachedResponse;
  }

  try {
    const networkResponse = await fetch(request);

    // Salvează în cache doar răspunsuri valide
    if (networkResponse && networkResponse.status === 200) {
      const cache = await caches.open(CACHE_NAME);
      cache.put(request, networkResponse.clone());
    }

    return networkResponse;
  } catch (error) {
    console.warn('[SW] Fetch failed:', request.url, error);

    // Returnează placeholder pentru imagini
    if (isInventoryImage(request.url)) {
      return caches.match('/placeholder..png');
    }

    return new Response('Offline', { status: 503 });
  }
}

/**
 * Strategie: Network First
 * Încearcă rețeaua, dacă eșuează, caută în cache
 */
async function networkFirst(request) {
  try {
    const networkResponse = await fetch(request);

    // Salvează în cache pentru offline
    if (networkResponse && networkResponse.status === 200) {
      const cache = await caches.open(CACHE_NAME);
      cache.put(request, networkResponse.clone());
    }

    return networkResponse;
  } catch (error) {
    console.log('[SW] Network failed, trying cache:', request.url);

    const cachedResponse = await caches.match(request);

    if (cachedResponse) {
      return cachedResponse;
    }

    // Returnează pagina offline dacă nu avem nimic în cache
    const offlinePage = await caches.match('/offline.html');
    if (offlinePage) {
      return offlinePage;
    }

    return new Response('Offline - Nu există conexiune la internet', {
      status: 503,
      headers: { 'Content-Type': 'text/html; charset=utf-8' }
    });
  }
}

// Ascultă mesaje de la pagină
self.addEventListener('message', (event) => {
  console.log('[SW] Mesaj primit:', event.data);

  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }

  if (event.data && event.data.type === 'CLEAR_CACHE') {
    caches.keys().then(names => {
      names.forEach(name => caches.delete(name));
    });
  }

  // Procesare queue de operații (Faza 3)
  if (event.data && event.data.type === 'PROCESS_QUEUE') {
    processOfflineQueue();
  }
});

/**
 * Background Sync - Faza 3
 * Se declanșează când revine conexiunea
 */
self.addEventListener('sync', (event) => {
  console.log('[SW] Background Sync triggered:', event.tag);

  if (event.tag === 'inventar-sync') {
    event.waitUntil(processOfflineQueue());
  }
});

/**
 * Procesează operațiile din queue (comunicare cu pagina)
 */
async function processOfflineQueue() {
  console.log('[SW] Procesare queue offline...');

  // Notifică toate paginile deschise să proceseze queue-ul
  const clients = await self.clients.matchAll({ type: 'window' });

  for (const client of clients) {
    client.postMessage({
      type: 'SYNC_REQUESTED',
      timestamp: Date.now()
    });
  }

  return true;
}

/**
 * Periodic Sync (pentru browsere care suportă)
 */
self.addEventListener('periodicsync', (event) => {
  if (event.tag === 'inventar-periodic-sync') {
    console.log('[SW] Periodic sync triggered');
    event.waitUntil(processOfflineQueue());
  }
});

console.log('[SW] Service Worker încărcat - Inventar.live v2.1.0 (Offline Write)');
