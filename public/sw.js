/*
 * Service worker for the installed app.
 *
 * It caches one thing: the offline page. Nothing else is served from cache,
 * deliberately. This panel is a cash book, a meter log and an order list —
 * every figure on every screen is a number somebody will act on, and a cached
 * page carries no indication that its balance is an hour old. A stale badge is
 * survivable; a stale saldo is a wrong decision made confidently. Livewire
 * would not survive it either: the CSRF token is baked into the markup, so a
 * page replayed from cache is a page whose next click fails validation.
 *
 * What it is for, then, is not offline use. Chrome requires a service worker
 * with a fetch handler before it will offer to install a site, and a network
 * failure inside a standalone window has no address bar to explain itself —
 * the browser's own error page is chrome the installed app does not have. This
 * answers both with the smallest thing that does.
 *
 * Bump CACHE whenever offline.html changes; activate drops every other cache,
 * so the old copy goes with it.
 */

const CACHE = 'internalweb-offline-v1';
const OFFLINE_URL = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE)
            // cache: 'reload' so a redeploy's offline page is fetched from the
            // network rather than from the HTTP cache that may still hold the
            // previous one.
            .then((cache) => cache.add(new Request(OFFLINE_URL, { cache: 'reload' })))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    // Navigations only. Everything else — Livewire's POSTs, the polling that
    // fetches notifications, images, fonts — goes straight to the network with
    // no worker in the path at all.
    if (request.mode !== 'navigate') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    // Signed download links are navigations too: a report opens in a new tab.
    // Letting them through untouched keeps Content-Disposition and the range
    // requests a PDF viewer makes out of a code path that has no reason to
    // touch them, and avoids answering a failed download with a page that
    // looks like the app.
    if (url.pathname.startsWith('/storage/')) {
        return;
    }

    event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));
});
