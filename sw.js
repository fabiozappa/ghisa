// sw.js — service worker. Gestisce i SOLI asset statici (CLAUDE.md punto 7).
// Mai toccare api.php né index.php: sono dinamici e dipendono dalla sessione.
//
// Strategia: RETE PER PRIMA, con ripiego sulla cache.
// La versione precedente era cache-first e serviva per sempre la copia
// salvata: dopo un deploy ci si ritrovava con la vecchia app finché non si
// reinstallava la PWA a mano. Così invece, online prendi sempre l'ultima
// versione (e la risalvi), offline continui a usare quella in cache.

const CACHE = 'ghisa-v2';

// Percorsi degli asset da gestire. Il confronto è sul solo pathname: app.js e
// style.css arrivano con un ?v=<data file>, quindi l'URL cambia a ogni upload.
const ASSET_PATHS = [
    'style.css',
    'app.js',
    'manifest.json',
    'icons/icon-192.png',
    'icons/icon-512.png',
];

// Install: si attiva subito, senza aspettare la chiusura delle altre schede.
self.addEventListener('install', (event) => {
    event.waitUntil(self.skipWaiting());
});

// Activate: butta via le cache delle versioni precedenti e prende il comando.
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;

    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    const isAsset = ASSET_PATHS.some((a) => url.pathname.endsWith('/' + a));
    if (!isAsset) return;

    event.respondWith(
        fetch(req)
            .then((res) => {
                // Copia in cache solo le risposte buone, per l'uso offline.
                if (res && res.ok) {
                    const copy = res.clone();
                    caches.open(CACHE).then((cache) => cache.put(req, copy));
                }
                return res;
            })
            .catch(() => caches.match(req))
    );
});
