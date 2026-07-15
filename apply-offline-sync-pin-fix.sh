#!/bin/bash
# offline-sync-pin-fix — SECURITY: background offline-sync requests were
# refreshing last_pin_activity_at, so the server-side idle PIN lock never
# engaged while a tab was open; refresh dismissed the client overlay.
#   · offline-sync fetches send X-Intake-Background: 1
#   · EnsurePinFresh never counts flagged requests as human activity
#   · locked page renders get X-Pin-Locked; the SW refuses to cache them
set -e
cd "$(git rev-parse --show-toplevel)"
if grep -q "MARKER-OFFLINE-SYNC-PIN" app/Http/Middleware/EnsurePinFresh.php; then
  echo "pin-fix already applied — aborting."; exit 1
fi
if ! grep -q "v=stage6" resources/views/layouts/tenant/app.blade.php; then
  echo "stage 6 not applied — aborting."; exit 1
fi

cat > 'app/Http/Middleware/EnsurePinFresh.php' <<'PINFIX_0_EOF'
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsurePinFresh
 *
 * Layer 3 of the auth refactor. Enforces an idle-timeout re-PIN.
 *
 * Resolution:
 *   1. Tenant pin_tier_active is false -> pass through. (Starter and
 *      single-user Branded never see the lock.)
 *   2. Route is in the whitelist (heartbeat, unlock, switch, location
 *      picker, logout) -> pass through. These routes need to work even
 *      when the lock is active.
 *   3. Read session('last_pin_activity_at'). If null or older than the
 *      configured threshold:
 *        - For AJAX requests: respond with 423 Locked + JSON body.
 *          Client overlay catches this globally.
 *        - For page renders: set $pinLockPending in the view so the
 *          layout opens the overlay on render. Page state under the
 *          overlay stays intact.
 *   4. Else: touch the timestamp (rate-limited to once per minute to
 *      avoid hammering the session store).
 *
 * The server is the source of truth for staleness; the client-side
 * idle detector is just a UX accelerator that shows the overlay
 * locally before the next request would have shown it server-side.
 */
class EnsurePinFresh
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app('tenant') ?? null;

        if (! $tenant || ! $tenant->pin_tier_active) {
            return $next($request);
        }

        // Routes that must work even when the lock is pending.
        $routeName = $request->route()?->getName() ?? '';
        $whitelist = [
            'tenant.pin.heartbeat',
            'tenant.pin.unlock',
            'tenant.pin.setup',
            'tenant.switch',
            'tenant.pin.verify',
            'tenant.pin.set',
            'tenant.pin.reset-request',
            'tenant.logout',
            'tenant.select-location',
            'tenant.select-location.store',
            'tenant.switch-location',
        ];
        if (in_array($routeName, $whitelist, true)) {
            return $next($request);
        }

        $thresholdSec = \App\Services\TenantAuthPolicy::idleThresholdSec($tenant);
        $lastIso = $request->session()->get('last_pin_activity_at');

        $isStale = true;
        if ($lastIso) {
            try {
                $last = \Illuminate\Support\Carbon::parse($lastIso);
                $isStale = $last->lt(now()->subSeconds($thresholdSec));
            } catch (\Throwable $e) {
                $isStale = true;
            }
        }

        if ($isStale) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'ok'     => false,
                    'locked' => true,
                    'error'  => 'pin_stale',
                ], 423);
            }

            // Page render — flag the staleness; layout opens the overlay.
            view()->share('pinLockPending', true);
            $response = $next($request);
            // MARKER-OFFLINE-SYNC-PIN — mark locked renders so the offline
            // service worker never caches a page that required a PIN.
            $response->headers->set('X-Pin-Locked', '1');
            return $response;
        }

        // Fresh — touch the activity timestamp, but cap at once a minute
        // so we don't write the session on every single request.
        // MARKER-OFFLINE-SYNC-PIN — automated background requests (offline
        // snapshot refresh, queue replay) must NOT count as human activity,
        // or the idle lock never engages while a tab is open.
        $isBackground = $request->headers->get('X-Intake-Background') === '1';
        if ($lastIso && ! $isBackground) {
            try {
                $last = \Illuminate\Support\Carbon::parse($lastIso);
                if ($last->lt(now()->subMinute())) {
                    $request->session()->put('last_pin_activity_at', now()->toIso8601String());
                }
            } catch (\Throwable $e) {
                // If parse failed, set it fresh so subsequent requests work.
                $request->session()->put('last_pin_activity_at', now()->toIso8601String());
            }
        }

        view()->share('pinLockPending', false);
        return $next($request);
    }
}
PINFIX_0_EOF

cat > 'public/js/offline-sync.js' <<'PINFIX_1_EOF'
/* MARKER-OFFLINE-SYNC stage 3 — global offline module.
 * Loaded on EVERY tenant admin page when the offline_sync add-on is active.
 * No arming ritual: the service worker installs, the catalog snapshot
 * refreshes, and any queued work replays in the background from whatever
 * page loads first. A fixed status pill (online/offline + queue count) with
 * a gear popover gives per-device control everywhere.
 *
 * Config injected by the layout:
 *   window.IntakeOfflineConfig = { enabled, catalogUrl, storeSaleUrl, csrf }
 * Page modules (register) call window.IntakeOffline directly and listen for
 * the 'intake-offline-status' CustomEvent: {online, queued, phase}.
 */
(function () {
  const CFG = window.IntakeOfflineConfig || {};
  const SNAP_KEY = 'ia_offline_catalog';
  const LIMIT_KEY = 'ia_off_snap_limit';
  const PAUSE_KEY = 'ia_off_paused';
  const SNAP_TTL_MS = 10 * 60 * 1000; // background refresh at most every 10 min

  const IO = window.IntakeOffline = {
    enabled: CFG.enabled === true,
    paused: localStorage.getItem(PAUSE_KEY) === '1',
    online: navigator.onLine,
    db: null,
    queued: 0,
    phase: 'idle', // idle | syncing
  };
  const active = () => IO.enabled && !IO.paused;

  // ------------------------------------------------------------ utils
  IO.uuid = function () {
    return (crypto.randomUUID) ? crypto.randomUUID() :
      'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
        const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
      });
  };
  function emit() {
    document.dispatchEvent(new CustomEvent('intake-offline-status', {
      detail: { online: IO.online, queued: IO.queued, phase: IO.phase, paused: IO.paused },
    }));
    renderPill();
  }

  // ------------------------------------------------------------ outbox
  function openDb() {
    return new Promise((res, rej) => {
      const rq = indexedDB.open('intake-offline', 1);
      rq.onupgradeneeded = () => rq.result.createObjectStore('outbox', { keyPath: 'uuid' });
      rq.onsuccess = () => res(rq.result);
      rq.onerror = () => rej(rq.error);
    });
  }
  IO.all = function () {
    return new Promise((res, rej) => {
      if (!IO.db) return res([]);
      const rq = IO.db.transaction('outbox').objectStore('outbox').getAll();
      rq.onsuccess = () => res(rq.result || []); rq.onerror = () => rej(rq.error);
    });
  };
  IO.remove = function (uuid) {
    return new Promise((res) => {
      const tx = IO.db.transaction('outbox', 'readwrite');
      tx.objectStore('outbox').delete(uuid);
      tx.oncomplete = res; tx.onerror = res;
    });
  };
  IO.queueSale = async function (payload) {
    await new Promise((res, rej) => {
      const tx = IO.db.transaction('outbox', 'readwrite');
      tx.objectStore('outbox').put({ uuid: payload.client_uuid, payload, created_at: Date.now() });
      tx.oncomplete = res; tx.onerror = () => rej(tx.error);
    });
    await refreshCount();
  };
  async function refreshCount() {
    IO.queued = IO.db ? (await IO.all()).length : 0;
    emit();
  }

  // ------------------------------------------------------------ replay
  let replayTimer = null;
  IO.replay = async function () {
    if (!active() || !IO.db || !navigator.onLine) return;
    const all = await IO.all();
    if (!all.length) { IO.phase = 'idle'; emit(); return; }
    IO.phase = 'syncing'; emit();
    for (const rec of all.sort((a, b) => a.created_at - b.created_at)) {
      try {
        const res = await fetch(CFG.storeSaleUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CFG.csrf, 'X-Intake-Background': '1' },
          body: JSON.stringify(rec.payload),
        });
        const data = await res.json();
        if (data.ok) await IO.remove(rec.uuid);
        else if (res.status === 422) await IO.remove(rec.uuid); // permanently invalid — drop
      } catch (e) { break; } // still offline — retry on next tick
    }
    IO.phase = 'idle';
    await refreshCount();
    scheduleRetry();
  };
  function scheduleRetry() {
    clearTimeout(replayTimer);
    if (IO.queued > 0) replayTimer = setTimeout(() => IO.replay(), 30000);
  }

  // ------------------------------------------------------------ snapshot
  IO.snapshotInfo = function () {
    try { const s = JSON.parse(localStorage.getItem(SNAP_KEY) || 'null');
      return s ? { at: s.captured_at, products: (s.products || []).length, services: (s.services || []).length } : null;
    } catch (e) { return null; }
  };
  IO.refreshSnapshot = async function (force) {
    if (!active() || !navigator.onLine || !CFG.catalogUrl) return;
    const info = IO.snapshotInfo();
    if (!force && info && (Date.now() - Date.parse(info.at)) < SNAP_TTL_MS) return;
    try {
      const limit = localStorage.getItem(LIMIT_KEY) || '500';
      const r = await fetch(CFG.catalogUrl + '?limit=' + limit, { headers: { 'Accept': 'application/json', 'X-Intake-Background': '1' } });
      const d = await r.json();
      if (d.ok) { localStorage.setItem(SNAP_KEY, JSON.stringify(d)); renderPanel(); }
    } catch (e) { /* best-effort */ }
  };
  IO.snapshotSearch = function (q) {
    try {
      const snap = JSON.parse(localStorage.getItem(SNAP_KEY) || 'null');
      if (!snap) return null;
      const needle = q.toLowerCase();
      const hit = t => (t || '').toLowerCase().includes(needle);
      return {
        products: (snap.products || []).filter(p => hit(p.name) || hit(p.sku)).slice(0, 15),
        services: (snap.services || []).filter(sv => hit(sv.name)).slice(0, 15),
        _snapshot_at: snap.captured_at,
      };
    } catch (e) { return null; }
  };

  // ------------------------------------------------------------ service worker
  function syncServiceWorker() {
    if (!('serviceWorker' in navigator)) return;
    if (active()) {
      navigator.serviceWorker.register('/offline-sw.js', { scope: '/' }).catch(() => {});
    } else {
      navigator.serviceWorker.getRegistrations().then(rs => rs.forEach(r => r.unregister()));
      if (window.caches) caches.keys().then(ks => ks.forEach(k => { if (k.startsWith('ia-offline')) caches.delete(k); }));
    }
  }

  // ------------------------------------------------------------ status UI (stage 4)
  // No floating elements. Status renders into mounts that live in the page
  // flow: #ioMountSidebar (desktop nav, above the profile block) and
  // #ioMountMobile (mobile header). The gear opens the settings popover
  // anchored to whichever gear was tapped.
  let panel = null;
  function renderPill() { renderMounts(); }
  function renderMounts() {
    if (!IO.enabled) return;
    renderSidebarBlock(document.getElementById('ioMountSidebar'));
    renderMobilePill(document.getElementById('ioMountMobile'));
  }
  function stateBits() {
    if (IO.paused) return { dot: '#6E6E6E', label: 'Paused', off: false };
    if (!IO.online) return { dot: '#F5C56B', label: 'Offline', off: true };
    if (IO.phase === 'syncing') return { dot: '#BEF264', label: 'Syncing…', off: false };
    return { dot: '#7FD98F', label: 'Online', off: false };
  }
  function renderSidebarBlock(mount) {
    if (!mount) return;
    const b = stateBits();
    const quiet = !b.off && IO.phase !== 'syncing' && !IO.queued;
    mount.innerHTML =
      '<div style="display:flex;align-items:center;gap:8px;padding:6px 16px 10px" ' +
      'font:600 11.5px Inter,-apple-system,sans-serif;color:' + (quiet ? 'var(--ia-dim,#6e6e6e)' : (b.off ? '#F5C56B' : 'var(--ia-muted,#9c9c9c)')) + '">' +
        '<span style="width:7px;height:7px;border-radius:50%;background:' + b.dot + ';flex:none;' + (b.off ? 'animation:ioPulse 1.6s infinite' : '') + '"></span>' +
        '<span style="line-height:1.35">' + b.label +
          (IO.queued ? '<br><span style="font-size:10.5px;font-weight:700;color:#F5C56B">' + IO.queued + ' sale' + (IO.queued > 1 ? 's' : '') + ' queued</span>' : '') +
        '</span>' +
        '<button id="ioGearSide" title="Offline sync settings" aria-label="Offline sync settings" ' +
          'style="margin-left:auto;border:none;background:transparent;color:var(--ia-dim,#6e6e6e);cursor:pointer;font-size:13px;line-height:1;padding:2px">⚙</button>' +
      '</div>';
    const g = mount.querySelector('#ioGearSide');
    if (g) g.addEventListener('click', e => togglePanel(e.currentTarget));
    ensureKeyframes();
  }
  function renderMobilePill(mount) {
    if (!mount) return;
    const b = stateBits();
    const quiet = !b.off && IO.phase !== 'syncing' && !IO.queued;
    if (quiet) {
      // Online: dot + gear sized and centered to match the header bell.
      mount.innerHTML =
        '<span style="display:inline-flex;align-items:center;gap:12px;height:40px">' +
          '<span title="Online — offline sync ready" style="width:8px;height:8px;border-radius:50%;background:' + b.dot + ';flex:none;display:block"></span>' +
          '<button id="ioGearMob" title="Offline sync settings" aria-label="Offline sync settings" ' +
            'style="border:none;background:transparent;color:var(--ia-muted,#9c9c9c);cursor:pointer;padding:0;width:24px;height:40px;display:inline-flex;align-items:center;justify-content:center">' +
            '<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
              '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>' +
            '</svg></button>' +
        '</span>';
    } else {
      mount.innerHTML =
        '<span style="display:inline-flex;align-items:center;gap:7px;border:1px solid ' + (b.off ? 'rgba(245,197,107,.45)' : 'rgba(190,242,100,.35)') + ';' +
        'background:' + (b.off ? 'rgba(245,197,107,.07)' : 'rgba(190,242,100,.06)') + ';border-radius:100px;' +
        'padding:4px 5px 4px 10px;height:28px;font:600 11.5px Inter,-apple-system,sans-serif;color:' + (b.off ? '#F5C56B' : '#BEF264') + '">' +
          '<span style="width:7px;height:7px;border-radius:50%;background:' + b.dot + ';flex:none;' + (b.off ? 'animation:ioPulse 1.6s infinite' : '') + '"></span>' +
          b.label + (IO.queued ? ' · ' + IO.queued : '') +
          '<button id="ioGearMob" title="Offline sync settings" aria-label="Offline sync settings" ' +
            'style="border:none;background:rgba(0,0,0,.35);color:inherit;border-radius:100px;width:22px;height:22px;cursor:pointer;font-size:12px;line-height:1;flex:none">⚙</button>' +
        '</span>';
    }
    const g = mount.querySelector('#ioGearMob');
    if (g) g.addEventListener('click', e => togglePanel(e.currentTarget));
    ensureKeyframes();
  }
  function ensureKeyframes() {
    if (document.getElementById('ioPulseKf')) return;
    const st = document.createElement('style'); st.id = 'ioPulseKf';
    st.textContent = '@keyframes ioPulse{0%,100%{opacity:1}50%{opacity:.35}}';
    document.head.appendChild(st);
  }
  function togglePanel(anchor) {
    if (panel && panel.parentNode) { panel.remove(); panel = null; return; }
    renderPanel(true, anchor);
  }
  function renderPanel(create, anchor) {
    if (!panel && !create) return;
    const info = IO.snapshotInfo();
    const limit = localStorage.getItem(LIMIT_KEY) || '500';
    const fresh = info ? new Date(info.at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '—';
    if (!panel) {
      panel = el('div',
        'position:fixed;z-index:9500;width:300px;background:var(--ia-panel,#141414);' +
        'border:1px solid var(--ia-border,#2a2a2a);border-radius:14px;padding:16px;' +
        'font:400 13px Inter,-apple-system,sans-serif;color:var(--ia-text,#ededed);box-shadow:0 10px 34px rgba(0,0,0,.5)');
      panel.id = 'ioPanel';
      document.body.appendChild(panel);
      // anchor beside the gear that was tapped, clamped to the viewport
      const r = anchor.getBoundingClientRect();
      const vw = window.innerWidth, vh = window.innerHeight, pw = 300, ph = 250;
      let left = Math.min(Math.max(10, r.left - pw + r.width), vw - pw - 10);
      let top = (r.top > vh / 2) ? Math.max(10, r.top - ph - 10) : Math.min(vh - ph - 10, r.bottom + 10);
      panel.style.left = left + 'px';
      panel.style.top = top + 'px';
    }
    panel.innerHTML =
      '<div style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--ia-muted,#9c9c9c);margin-bottom:10px">Offline sync — this device</div>' +
      '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">' +
        '<span>Pause on this device</span>' +
        '<button id="ioPause" style="border:1px solid var(--ia-border,#2a2a2a);background:' + (IO.paused ? '#2a2a2a' : '#BEF264') + ';color:' + (IO.paused ? '#ededed' : '#0b0b0b') + ';border-radius:100px;padding:5px 12px;font-weight:700;font-size:12px;cursor:pointer">' + (IO.paused ? 'Paused' : 'Active') + '</button>' +
      '</div>' +
      '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">' +
        '<span>Catalog snapshot</span>' +
        '<select id="ioLimit" style="background:var(--ia-bg,#0b0b0b);color:var(--ia-text,#ededed);border:1px solid var(--ia-border,#2a2a2a);border-radius:8px;padding:5px 8px;font-family:inherit;font-size:12px">' +
          ['250', '500', '1000'].map(v => '<option value="' + v + '"' + (v === limit ? ' selected' : '') + '>Top ' + v + '</option>').join('') +
        '</select>' +
      '</div>' +
      '<div style="font-size:12px;color:var(--ia-muted,#9c9c9c);margin-bottom:12px">' +
        (info ? 'Snapshot: ' + info.products + ' products · ' + info.services + ' services · updated ' + fresh : 'No snapshot on this device yet.') +
        (IO.queued ? '<br><b style="color:#F5C56B">' + IO.queued + ' queued sale' + (IO.queued > 1 ? 's' : '') + '</b> waiting to sync.' : '') +
      '</div>' +
      '<div style="display:flex;gap:8px">' +
        '<button id="ioRefresh" style="flex:1;border:1px solid var(--ia-border,#2a2a2a);background:transparent;color:var(--ia-text,#ededed);border-radius:9px;padding:8px;font-weight:600;font-size:12px;cursor:pointer">Refresh snapshot</button>' +
        '<button id="ioClear" style="flex:1;border:1px solid var(--ia-border,#2a2a2a);background:transparent;color:var(--ia-muted,#9c9c9c);border-radius:9px;padding:8px;font-weight:600;font-size:12px;cursor:pointer">Clear snapshot</button>' +
      '</div>' +
      '<div style="font-size:11px;color:var(--ia-dim,#6e6e6e);margin-top:10px">Queued sales are never cleared from here — they sync automatically.</div>';
    panel.querySelector('#ioPause').addEventListener('click', () => {
      IO.paused = !IO.paused;
      localStorage.setItem(PAUSE_KEY, IO.paused ? '1' : '0');
      syncServiceWorker(); emit(); renderPanel();
    });
    panel.querySelector('#ioLimit').addEventListener('change', e => {
      localStorage.setItem(LIMIT_KEY, e.target.value);
      IO.refreshSnapshot(true);
    });
    panel.querySelector('#ioRefresh').addEventListener('click', () => IO.refreshSnapshot(true));
    panel.querySelector('#ioClear').addEventListener('click', () => {
      localStorage.removeItem(SNAP_KEY); renderPanel();
    });
  }
  document.addEventListener('click', e => {
    if (panel && !panel.contains(e.target) && !e.target.closest('#ioMountSidebar') && !e.target.closest('#ioMountMobile')) {
      panel.remove(); panel = null;
    }
  });

  // ------------------------------------------------------------ boot
  if (!IO.enabled) { syncServiceWorker(); return; }
  syncServiceWorker();
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', renderMounts);
  else renderMounts();
  openDb().then(async db => {
    IO.db = db;
    await refreshCount();
    IO.refreshSnapshot(false);   // background — throttled to 10 min
    IO.replay();                 // background — drains any queue from any page
  }).catch(() => { IO.enabled = false; });
  window.addEventListener('online', () => { IO.online = true; emit(); IO.replay(); IO.refreshSnapshot(false); });
  window.addEventListener('offline', () => { IO.online = false; emit(); });
})();
PINFIX_1_EOF

cat > 'public/offline-sw.js' <<'PINFIX_2_EOF'
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
        // MARKER-OFFLINE-SYNC-PIN — never cache a page rendered behind the PIN lock.
        if (fresh.ok && isWhitelistedPage(url) && fresh.headers.get('X-Pin-Locked') !== '1') {
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
PINFIX_2_EOF

# bust the JS caches so every device picks up the flagged fetches
sed -i '' 's/?v=stage6/?v=stage6p/' resources/views/layouts/tenant/app.blade.php 2>/dev/null || sed -i 's/?v=stage6/?v=stage6p/' resources/views/layouts/tenant/app.blade.php
echo "pin-fix applied — server needs view:clear; hard-refresh devices"
