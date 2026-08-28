// sw.js — service worker. Cache dei SOLI asset statici (CLAUDE.md punto 7).
// Mai cachare api.php né index.php (dinamici, dipendono dalla sessione).
// Il nome cache è versionato: cambialo per invalidare tutto.

const CACHE = 'ghisa-v1';

// Percorsi relativi allo scope del service worker.
const ASSETS = [
    'style.css',
    'app.js',
    'manifest.json',
    'icons/icon-192.png',
    'icons/icon-512.png',
];

// Install: pre-carica gli asset e attiva subito la nuova versione.
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE)
            .then((cache) => cache.addAll(ASSETS))
            .then(() => self.skipWaiting())
    );
});

// Activate: rimuove le cache vecchie.
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))
            ))
            .then(() => self.clients.claim())
    );
});

// Fetch: cache-first solo per i nostri asset statici. Tutto il resto
// (index.php, api.php, POST) passa in rete senza essere intercettato.
self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;

    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    const isAsset = ASSETS.some((a) => url.pathname.endsWith('/' + a));
    if (!isAsset) return;

    event.respondWith(
        caches.match(req).then((cached) => cached || fetch(req))
    );
});
