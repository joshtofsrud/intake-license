#!/bin/bash
# offline-sync pill position fix — mobile keeps the header-docked top-right
# spot; desktop moves to bottom-right, clear of dashboard action buttons.
set -e
cd "$(git rev-parse --show-toplevel)"
cat > public/js/offline-sync.js <<'OFS3B_EOF'
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
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CFG.csrf },
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
      const r = await fetch(CFG.catalogUrl + '?limit=' + limit, { headers: { 'Accept': 'application/json' } });
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

  // ------------------------------------------------------------ pill + popover UI
  function el(tag, css, html) {
    const e = document.createElement(tag);
    if (css) e.style.cssText = css;
    if (html !== undefined) e.innerHTML = html;
    return e;
  }
  let pill, panel;
  function renderPill() {
    if (!IO.enabled) return;
    if (!pill) {
      // Mobile: docks into the header's empty right side. Desktop: bottom-right,
      // clear of the dashboard action buttons and toasts stack above it.
      const wide = window.matchMedia('(min-width: 900px)').matches;
      pill = el('div',
        'position:fixed;' + (wide ? 'bottom:16px;right:16px;' : 'top:12px;right:14px;') + 'z-index:8900;display:flex;align-items:center;gap:8px;' +
        'background:var(--ia-panel,#141414);border:1px solid var(--ia-border,#2a2a2a);border-radius:100px;' +
        'padding:6px 6px 6px 13px;font:600 12px Inter,-apple-system,sans-serif;color:var(--ia-text,#ededed);' +
        'box-shadow:0 4px 18px rgba(0,0,0,.35)');
      pill.id = 'ioPill';
      pill.innerHTML =
        '<span id="ioDot" style="width:8px;height:8px;border-radius:50%;background:#7FD98F;flex:none"></span>' +
        '<span id="ioLbl">Online</span>' +
        '<span id="ioQ" style="display:none;background:rgba(245,197,107,.14);border:1px solid rgba(245,197,107,.4);color:#F5C56B;border-radius:100px;padding:2px 8px;font-size:11px"></span>' +
        '<button id="ioGear" title="Offline sync settings" style="border:none;background:var(--ia-bg,#0b0b0b);color:var(--ia-muted,#9c9c9c);border-radius:100px;width:26px;height:26px;cursor:pointer;font-size:13px;line-height:1">⚙</button>';
      document.body.appendChild(pill);
      pill.querySelector('#ioGear').addEventListener('click', togglePanel);
    }
    const dot = pill.querySelector('#ioDot'), lbl = pill.querySelector('#ioLbl'), q = pill.querySelector('#ioQ');
    if (IO.paused) { dot.style.background = '#6E6E6E'; lbl.textContent = 'Offline sync paused'; }
    else if (!IO.online) { dot.style.background = '#F5C56B'; dot.style.animation = 'ioPulse 1.6s infinite'; lbl.textContent = 'Offline'; }
    else if (IO.phase === 'syncing') { dot.style.background = '#BEF264'; dot.style.animation = ''; lbl.textContent = 'Syncing…'; }
    else { dot.style.background = '#7FD98F'; dot.style.animation = ''; lbl.textContent = 'Online'; }
    q.style.display = IO.queued ? 'inline-block' : 'none';
    q.textContent = IO.queued + ' queued';
    if (!document.getElementById('ioPulseKf')) {
      const st = document.createElement('style'); st.id = 'ioPulseKf';
      st.textContent = '@keyframes ioPulse{0%,100%{opacity:1}50%{opacity:.35}}';
      document.head.appendChild(st);
    }
  }
  function togglePanel() {
    if (panel && panel.parentNode) { panel.remove(); panel = null; return; }
    renderPanel(true);
  }
  function renderPanel(create) {
    if (!panel && !create) return;
    const info = IO.snapshotInfo();
    const limit = localStorage.getItem(LIMIT_KEY) || '500';
    const fresh = info ? new Date(info.at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '—';
    if (!panel) {
      const wideP = window.matchMedia('(min-width: 900px)').matches;
      panel = el('div',
        'position:fixed;' + (wideP ? 'bottom:58px;right:16px;' : 'top:52px;right:14px;') + 'z-index:8901;width:300px;background:var(--ia-panel,#141414);' +
        'border:1px solid var(--ia-border,#2a2a2a);border-radius:14px;padding:16px;' +
        'font:400 13px Inter,-apple-system,sans-serif;color:var(--ia-text,#ededed);box-shadow:0 10px 34px rgba(0,0,0,.5)');
      panel.id = 'ioPanel';
      document.body.appendChild(panel);
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
    if (panel && !panel.contains(e.target) && !(pill && pill.contains(e.target))) { panel.remove(); panel = null; }
  });

  // ------------------------------------------------------------ boot
  if (!IO.enabled) { syncServiceWorker(); return; }
  syncServiceWorker();
  renderPill();
  openDb().then(async db => {
    IO.db = db;
    await refreshCount();
    IO.refreshSnapshot(false);   // background — throttled to 10 min
    IO.replay();                 // background — drains any queue from any page
  }).catch(() => { IO.enabled = false; if (pill) pill.remove(); });
  window.addEventListener('online', () => { IO.online = true; emit(); IO.replay(); IO.refreshSnapshot(false); });
  window.addEventListener('offline', () => { IO.online = false; emit(); });
})();
OFS3B_EOF
echo "pill position fix applied"
