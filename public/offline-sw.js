/* MARKER-OFFLINE-SYNC — stage 2 service worker.
 * Network-first HTML caching for a whitelist of admin pages so an already-
 * visited register / calendar / time clock still opens during an outage,
 * plus a branded fallback for every other admin navigation.
 * Registered only when the offline_sync add-on is active; the register page
 * unregisters it (and clears caches) when the add-on is off.
 */
const VERSION   = 'ia-offline-v1';
const PAGE_CACHE  = VERSION + '-pages';
const ASSET_CACHE = VERSION + '-assets';
const FALLBACK    = '/offline-fallback';

// Admin pages worth serving stale during an outage.
const PAGE_WHITELIST = [
  '/admin/register',
  '/admin/calendar',
  '/admin/timeclock',
];

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(PAGE_CACHE)
      .then(c => c.add(new Request(FALLBACK, { credentials: 'same-origin' })))
      .catch(() => {}) // fallback precache is best-effort
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => !k.startsWith(VERSION)).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

function isWhitelistedPage(url) {
  return PAGE_WHITELIST.some(p => url.pathname === p || url.pathname.startsWith(p + '/'))
      && !url.pathname.endsWith('.json');
}

function isStaticAsset(url) {
  return /\.(css|js|woff2?|png|svg|jpe?g|webp|ico)$/.test(url.pathname);
}

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // Admin page navigations: network-first, cache good copies, fall back.
  if (req.mode === 'navigate' && url.pathname.startsWith('/admin')) {
    e.respondWith((async () => {
      try {
        const fresh = await fetch(req);
        if (fresh.ok && isWhitelistedPage(url)) {
          const c = await caches.open(PAGE_CACHE);
          c.put(req, fresh.clone());
        }
        return fresh;
      } catch (err) {
        const cached = await caches.match(req);
        if (cached) return cached;
        const fb = await caches.match(FALLBACK);
        if (fb) return fb;
        throw err;
      }
    })());
    return;
  }

  // Static assets: stale-while-revalidate.
  if (isStaticAsset(url)) {
    e.respondWith((async () => {
      const c = await caches.open(ASSET_CACHE);
      const cached = await c.match(req);
      const network = fetch(req).then(r => { if (r.ok) c.put(req, r.clone()); return r; }).catch(() => null);
      return cached || (await network) || Response.error();
    })());
  }
});
