#!/bin/bash
# offline-sync-stage-5 — quiet placements, per Josh's direction.
#   · desktop: borderless status footer row at the very bottom of the sidebar
#     nav (dim dot + Online + gear); goes amber with queue count only when
#     something needs attention
#   · mobile: online state shrinks to dot + gear (no box, no words) with
#     breathing room from the bell; expands to the amber pill only when
#     offline / syncing / queued
#   · cache-buster → stage5
set -e
cd "$(git rev-parse --show-toplevel)"
if grep -q "v=stage5" resources/views/layouts/tenant/app.blade.php; then
  echo "offline-sync-stage-5 already applied — aborting."; exit 1
fi
if ! grep -q "v=stage4" resources/views/layouts/tenant/app.blade.php; then
  echo "stage 4 not applied — aborting."; exit 1
fi

cat > 'public/js/offline-sync.js' <<'OFS5_0_EOF'
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
      '<div style="display:flex;align-items:center;gap:8px;padding:8px 16px 4px;' +
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
      // Online: just the dot and the gear — no box, no words.
      mount.innerHTML =
        '<span style="display:inline-flex;align-items:center;gap:8px">' +
          '<span title="Online — offline sync ready" style="width:8px;height:8px;border-radius:50%;background:' + b.dot + ';flex:none"></span>' +
          '<button id="ioGearMob" title="Offline sync settings" aria-label="Offline sync settings" ' +
            'style="border:none;background:transparent;color:var(--ia-dim,#6e6e6e);cursor:pointer;font-size:15px;line-height:1;padding:3px">⚙</button>' +
        '</span>';
    } else {
      mount.innerHTML =
        '<span style="display:inline-flex;align-items:center;gap:7px;border:1px solid ' + (b.off ? 'rgba(245,197,107,.45)' : 'rgba(190,242,100,.35)') + ';' +
        'background:' + (b.off ? 'rgba(245,197,107,.07)' : 'rgba(190,242,100,.06)') + ';border-radius:100px;' +
        'padding:4px 5px 4px 10px;font:600 11.5px Inter,-apple-system,sans-serif;color:' + (b.off ? '#F5C56B' : '#BEF264') + '">' +
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
OFS5_0_EOF

cat > 'resources/views/layouts/tenant/app.blade.php' <<'OFS5_1_EOF'
<!DOCTYPE html>
<html lang="en" class="ia-theme-{{ $adminTheme }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>{{ $pageTitle ?? 'Dashboard' }} — {{ $currentTenant->name }}</title>

  {{-- Fonts --}}
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

  {{-- Favicon --}}
  @if($currentTenant->favicon_url)
    <link rel="icon" href="{{ $currentTenant->favicon_url }}">
  @endif

  {{-- Base + theme CSS --}}
  <link rel="stylesheet" href="{{ asset('css/tenant/base.css') }}?v={{ filemtime(public_path('css/tenant/base.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/tenant/theme-' . $adminTheme . '.css') }}?v={{ filemtime(public_path('css/tenant/theme-' . $adminTheme . '.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/tenant/mobile-nav.css') }}?v={{ filemtime(public_path('css/tenant/mobile-nav.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/tenant/mobile-schedule.css') }}?v={{ filemtime(public_path('css/tenant/mobile-schedule.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/tenant/mobile-forms.css') }}?v={{ filemtime(public_path('css/tenant/mobile-forms.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/tenant/dashboard.css') }}?v={{ filemtime(public_path('css/tenant/dashboard.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/tenant/toast.css') }}?v={{ filemtime(public_path('css/tenant/toast.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/tenant/confirm.css') }}?v={{ filemtime(public_path('css/tenant/confirm.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/tenant/toggle.css') }}?v={{ filemtime(public_path('css/tenant/toggle.css')) }}">

  {{-- Master-admin theme overrides (theme_settings table) --}}
  {!! \App\Support\ThemeOverrideHelper::styleTag() !!}

  {{-- Tenant accent color injected at runtime --}}
  <style>
    body {
      --ia-accent: {{ $currentTenant->accent_color ?? '#3B5A78' }};
      --ia-accent-text: {{ \App\Support\ColorHelper::accentTextColor($currentTenant->accent_color ?? '#3B5A78') }};
      --ia-accent-soft: {{ \App\Support\ColorHelper::accentSoft($currentTenant->accent_color ?? '#3B5A78') }};
    }
  </style>

  @stack('styles')
</head>

<body class="ia-theme-{{ $adminTheme }}">
{{-- MARKER-PATCH-498 — invite setup success: check draws, circle wraps it, dashboard fades in --}}
@if(session('setup_complete'))
<div id="ia-setup-success" aria-hidden="true">
  <svg viewBox="0 0 72 72" width="88" height="88">
    <circle cx="36" cy="36" r="32" fill="none" stroke="var(--ia-accent)" stroke-width="3"
            stroke-linecap="round" stroke-dasharray="202" stroke-dashoffset="202"
            transform="rotate(-90 36 36)" class="iss-circle"/>
    <path d="M23 37l9 9 17-19" fill="none" stroke="var(--ia-accent)" stroke-width="4"
          stroke-linecap="round" stroke-linejoin="round"
          stroke-dasharray="40" stroke-dashoffset="40" class="iss-check"/>
  </svg>
  <div class="iss-label">You're in</div>
</div>
<style>
#ia-setup-success{position:fixed;inset:0;z-index:9999;background:var(--ia-bg);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;animation:iss-fade .5s ease 1.6s forwards}
#ia-setup-success .iss-check{animation:iss-draw .35s ease-out .15s forwards}
#ia-setup-success .iss-circle{animation:iss-draw .55s ease-in-out .45s forwards}
#ia-setup-success .iss-label{font-size:14px;font-weight:600;color:var(--ia-text);opacity:0;animation:iss-in .3s ease .9s forwards}
@keyframes iss-draw{to{stroke-dashoffset:0}}
@keyframes iss-in{to{opacity:1}}
@keyframes iss-fade{to{opacity:0;visibility:hidden}}
</style>
<script>setTimeout(function(){var el=document.getElementById('ia-setup-success');if(el)el.remove();},2300);</script>
@endif

@include('layouts.tenant._mobile-header')

<div class="ia-shell">

  {{-- ================================================================
       Sidebar — rendered on both themes (B = Light Premium, C = Dark Premium).
       Both share the same sidebar layout; theme CSS handles the palette.
       ================================================================ --}}
  @include('layouts.tenant._sidebar')

  {{-- ================================================================
       Main area
       ================================================================ --}}
  <div class="ia-main">

    {{-- Impersonation banner --}}
    @if(session('impersonating_from'))
      <div style="background:#854F0B;color:#fff;padding:8px 20px;font-size:13px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:200">
        <span>⚠ You are impersonating this tenant as an admin.</span>
        <a href="{{ config('app.url') }}/admin/impersonate/stop" style="color:#FCD34D;font-weight:600">Stop impersonating →</a>
      </div>
    @endif

    {{-- Page content --}}
    <main class="ia-content">

      {{-- Impersonation banner --}}
      @if(session('impersonating_tenant_name') || session()->has('impersonating_from'))
        <div style="background:#854F0B;color:#FAEEDA;padding:10px 16px;border-radius:var(--ia-r-md);margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;font-size:13px">
          <span>
            👤 You are impersonating <strong>{{ session('impersonating_tenant_name', 'this tenant') }}</strong>.
            All actions you take are real.
          </span>
          <a href="{{ config('app.url') }}/admin/impersonate/stop"
             style="background:rgba(0,0,0,.2);color:#FAEEDA;padding:5px 14px;border-radius:6px;font-weight:600;font-size:12px">
            Stop impersonating →
          </a>
        </div>
      @endif

      {{-- Flash messages.
           Success → inline green banner (non-blocking, just confirms an action).
           Error   → IntakeConfirm.alert() modal (blocks until acknowledged so
           it can't be missed when the page is long, e.g. class session list). --}}
      @if(session('success'))
        <div class="ia-flash ia-flash--success">{{ session('success') }}</div>
      @endif

      {{-- MARKER-PATCH-613 — clock-in prompt. Off-the-clock staff get a gentle,
           dismissible nudge (dismissal is per page-load, not persisted — it
           reappears next visit so a forgotten clock-in gets caught). --}}
      @if(!empty($authUser) && empty($pinLockPending))
        @php
          $tcOpen = \App\Models\Tenant\TenantTimePunch::openFor($currentTenant->id, $authUser->id);
        @endphp
        @if(!$tcOpen)
          <div class="ia-flash" id="tc-clockin-nudge"
               style="display:flex;align-items:center;gap:12px;background:color-mix(in srgb,var(--ia-accent) 10%,transparent);border:0.5px solid var(--ia-accent);color:var(--ia-text)">
            <span style="flex:1">You're not clocked in.</span>
            <form method="POST" action="{{ route('tenant.timeclock.in') }}" style="margin:0">
              @csrf<input type="hidden" name="source" value="lock_screen">
              <button type="submit" style="background:var(--ia-accent);color:var(--ia-accent-text);border:none;border-radius:6px;padding:6px 14px;font-size:12.5px;font-weight:600;cursor:pointer">Clock in</button>
            </form>
            <button type="button" onclick="sessionStorage.setItem('tc_nudge_dismissed','1');document.getElementById('tc-clockin-nudge').remove()"
                    style="background:none;border:none;color:var(--ia-text-muted);cursor:pointer;font-size:16px;line-height:1">×</button>
          </div>
          <script>if(sessionStorage.getItem('tc_nudge_dismissed')){var n=document.getElementById('tc-clockin-nudge');if(n)n.remove();}</script>
        @endif
      @endif
      {{-- MARKER-PATCH-445 — single global flash; per-page success/error banners removed across tenant views --}}
      @if(session('error'))
        @push('scripts')
        <script>
          (function () {
            function pop() {
              if (window.IntakeConfirm && typeof window.IntakeConfirm.alert === 'function') {
                window.IntakeConfirm.alert({
                  title:   'Couldn\'t do that',
                  message: @json(session('error')),
                });
              } else {
                // Fallback if confirm.js hasn't loaded for some reason. Same
                // visual pattern as the inline banner — never silently swallow.
                var d = document.createElement('div');
                d.className = 'ia-flash ia-flash--error';
                d.textContent = @json(session('error'));
                document.body.insertBefore(d, document.body.firstChild);
              }
            }
            if (document.readyState === 'loading') {
              document.addEventListener('DOMContentLoaded', pop);
            } else {
              pop();
            }
          })();
        </script>
        @endpush
      @endif

      @include('layouts.tenant._staff-broadcast-banner')
      @yield('content')

    </main>
  </div>
</div>

{{-- ================================================================
     Mobile-only nav (bottom tab bar + drawer)
     Hidden on desktop via CSS; always rendered in markup.
     ================================================================ --}}
@include('layouts.tenant._mobile-nav')
@include('layouts.tenant._more-drawer')
@include('layouts.tenant._mobile-fab')

{{-- Detail modal (appointments, customers) --}}
@include('tenant._detail_modal')

{{-- Global JS --}}
<script>
  window.IntakeAdmin = {
    tenantId:   '{{ $currentTenant->id }}',
    csrfToken:  '{{ csrf_token() }}',
    theme:      '{{ $adminTheme }}',
    currency:   '{{ $currentTenant->currency_symbol ?? "$" }}',
    ajaxUrl:    '{{ url("/admin/ajax") }}',
    pinIdleThresholdSec:    {{ (int) config('intake.auth.pin_idle_threshold_sec', 120) }},
    pinHeartbeatIntervalSec:{{ (int) config('intake.auth.pin_heartbeat_interval_sec', 60) }},
  };
</script>

<script src="{{ asset('js/tenant/toast.js') }}?v={{ filemtime(public_path('js/tenant/toast.js')) }}" defer></script>
<script src="{{ asset('js/tenant/confirm.js') }}?v={{ filemtime(public_path('js/tenant/confirm.js')) }}" defer></script>
<script src="{{ asset('js/tenant/admin.js') }}?v={{ filemtime(public_path('js/tenant/admin.js')) }}" defer></script>
<script src="{{ asset('js/tenant/mobile-nav.js') }}?v={{ filemtime(public_path('js/tenant/mobile-nav.js')) }}" defer></script>
<script src="{{ asset('js/tenant/location-switcher.js') }}?v={{ filemtime(public_path('js/tenant/location-switcher.js')) }}" defer></script>
<script src="{{ asset('js/tenant/idle-lock.js') }}?v={{ filemtime(public_path('js/tenant/idle-lock.js')) }}" defer></script>

@include('layouts.tenant._lock-overlay')
@include('layouts.tenant._action-gate-modal')
@include('layouts.tenant._location-welcome')

{{-- MARKER-OFFLINE-SYNC stage 3 — global module: SW install, background
     snapshot refresh, queue replay, and the status pill live on EVERY admin
     page. No arming ritual. --}}
@php
  $ioEnabled = app()->bound('tenant')
      ? app(\App\Services\FeatureAccessService::class)->hasAddon(app('tenant'), 'offline_sync')
      : false;
@endphp
<script>
window.IntakeOfflineConfig = {
  enabled: {{ $ioEnabled ? 'true' : 'false' }},
  catalogUrl: @json(route('tenant.register.offline_catalog')),
  storeSaleUrl: @json(route('tenant.register.sales.store')),
  csrf: document.querySelector('meta[name=csrf-token]')?.content || '',
};
</script>
<script src="{{ asset('js/offline-sync.js') }}?v=stage5"></script>

{{-- MARKER-OFFLINE-SYNC stage 2 — when a SW-cached page is shown offline,
     stamp it so nobody trusts stale data blindly. --}}
<script>
(function () {
  function osStamp() {
    if (navigator.onLine || document.getElementById('osPageStamp')) return;
    var el = document.createElement('div');
    el.id = 'osPageStamp';
    el.style.cssText = 'position:fixed;bottom:16px;left:50%;transform:translateX(-50%);z-index:9999;'
      + 'background:rgba(245,197,107,.12);border:1px solid rgba(245,197,107,.4);color:#F5C56B;'
      + 'font:600 12.5px Inter,-apple-system,sans-serif;border-radius:100px;padding:8px 16px;backdrop-filter:blur(6px)';
    el.textContent = 'Offline — showing your last-loaded view. Changes are paused until you reconnect.';
    document.body.appendChild(el);
  }
  function osUnstamp() {
    var el = document.getElementById('osPageStamp');
    if (el) el.remove();
  }
  window.addEventListener('offline', osStamp);
  window.addEventListener('online', osUnstamp);
  if (!navigator.onLine) osStamp();
})();
</script>
@stack('scripts')

@include('tenant._onboarding_modal')

  <script defer src="{{ asset('js/tenant/cl-subnav-hint.js') }}"></script>
@include('tenant.print._composer') {{-- MARKER-PATCH-337 --}}
</body>
</html>

OFS5_1_EOF

cat > 'resources/views/layouts/tenant/_sidebar.blade.php' <<'OFS5_2_EOF'
@php
  $sidebarBg = ($adminTheme === 'c') ? '#0c0c0c' : (($adminTheme === 'a') ? '#0f0f0f' : '#1E2A3A');
  $sidebarLogo = \App\Support\ColorHelper::pickLogo($currentTenant, $sidebarBg);

  // Logo height in pixels. Clamp defensively in case bad data sneaks in.
  $adminLogoHeight = (int) ($currentTenant->logo_size_admin ?? 26);
  $adminLogoHeight = max(16, min(80, $adminLogoHeight));
@endphp

<aside class="ia-sidebar">

  {{-- Logo (image only when uploaded, fallback to letter + name when not) --}}
  <div class="ia-sidebar-logo">
    @if($sidebarLogo)
      <img src="{{ $sidebarLogo }}" alt="{{ $currentTenant->name }}" style="height:{{ $adminLogoHeight }}px;width:auto;border-radius:4px;max-width:160px;object-fit:contain">
    @else
      <div class="ia-sidebar-logo-mark">{{ strtoupper(substr($currentTenant->name, 0, 1)) }}</div>
      <span class="ia-sidebar-logo-name">{{ $currentTenant->name }}</span>
    @endif
  </div>

  {{-- ================================================================
       Identity block — top of sidebar, below logo, above nav.
       User row (always visible) + location row (only when 2+ locations).
       ================================================================ --}}
  <div class="ia-sidebar-identity" data-identity-block>
    @include('layouts.tenant._attention-row')
    {{-- User row: click opens a menu with Sign out (and later Switch staff). --}}
    <details class="ia-sb-user-details" data-loc-switcher="root">
      <summary class="ia-sb-user-row" aria-haspopup="menu" aria-label="Account menu">
        <div class="ia-sb-user-avatar">{{ strtoupper(substr($authUser->name, 0, 2)) }}</div>
        <div class="ia-sb-user-text">
          <div class="ia-sb-user-name">{{ $authUser->name }}</div>
          <div class="ia-sb-user-role">{{ ucfirst($authUser->role) }}</div>
        </div>
        <svg class="ia-sb-user-caret" width="12" height="12" viewBox="0 0 24 24"
             fill="none" stroke="currentColor" stroke-width="2.5"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <polyline points="6 9 12 15 18 9"/>
        </svg>
      </summary>
      <div class="ia-sb-user-menu" role="menu">
        {{-- MARKER-PATCH-150-POLISH-B — theme toggle (light/dark) --}}
        <form method="POST" action="{{ route('tenant.settings.update') }}" id="theme-toggle-form" style="margin:0">
          @csrf @method('PATCH')
          <input type="hidden" name="tab" value="appearance">
          <input type="hidden" name="admin_theme" id="theme-toggle-value" value="{{ $adminTheme === 'c' ? 'b' : 'c' }}">
          <button type="submit" class="ia-sb-user-menu-item" role="menuitem">
            @if($adminTheme === 'c')
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="4"/>
                <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>
              </svg>
              <span>Switch to light theme</span>
            @else
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
              </svg>
              <span>Switch to dark theme</span>
            @endif
          </button>
        </form>

        {{-- MARKER-PATCH-496 — switch user (PIN tier only) --}}
        @if($currentTenant->pin_tier_active)
        <a href="{{ route('tenant.switch') }}" class="ia-sb-user-menu-item" role="menuitem">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round"
               stroke-linejoin="round" aria-hidden="true">
            <path d="M16 3h5v5"/><path d="M21 3l-7 7"/>
            <path d="M8 21H3v-5"/><path d="M3 21l7-7"/>
          </svg>
          <span>Switch user</span>
        </a>
        @endif

        <button type="button" class="ia-sb-user-menu-item"
                onclick="document.getElementById('logout-form').submit()"
                role="menuitem">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round"
               stroke-linejoin="round" aria-hidden="true">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
            <polyline points="16 17 21 12 16 7"/>
            <line x1="21" y1="12" x2="9" y2="12"/>
          </svg>
          <span>Sign out</span>
        </button>
      </div>
    </details>

    {{-- Location row: rendered as the same partial used elsewhere, but
         styled as a sidebar row instead of a floating pill. The partial
         checks $userLocations->count() >= 2 before rendering anything. --}}
    <div class="ia-sb-location-wrap">
      @include('layouts.tenant._location-switcher')
    </div>
  </div>

  {{-- Primary nav --}}
  @include('layouts.tenant._nav-items')

  {{-- MARKER-OFFLINE-SYNC stage 5 — quiet status footer row (filled by /js/offline-sync.js) --}}
  <div id="ioMountSidebar"></div>

  {{-- Bottom: brand footer (respects show_intake_branding) --}}
  <div class="ia-sidebar-bottom">
    @include('layouts.tenant._brand-footer')
  </div>

  {{-- Logout form (referenced by the user menu) --}}
  <form id="logout-form" method="POST" action="{{ route('tenant.logout') }}" style="display:none">
    @csrf
  </form>

</aside>
OFS5_2_EOF

cat > 'resources/views/layouts/tenant/_mobile-header.blade.php' <<'OFS5_3_EOF'
{{-- ================================================================
     Mobile admin header (≤1023px) — MOBILE-BACK v1 + MOBILE-HEADER-LOGO-PICK v1
     Shows ‹ Back chevron when @section('mobile-back', 'Label|/url') is set,
     otherwise shows tenant logo or wordmark.
     Uses ColorHelper::pickLogo so dark themes get the light logo variant
     (matching the desktop sidebar's behavior).
     Desktop hides this entirely via CSS.
     ================================================================ --}}
@php
  $mobileBackRaw = trim(View::yieldContent('mobile-back', ''));
  $mobileBackLabel = null;
  $mobileBackUrl = null;
  if ($mobileBackRaw !== '') {
    $parts = explode('|', $mobileBackRaw, 2);
    if (count($parts) === 2) {
      $mobileBackLabel = trim($parts[0]);
      $mobileBackUrl   = trim($parts[1]);
    }
  }

  // Pick the right logo variant for the mobile header surface.
  // Background matches the CSS rule in mobile-nav.css: dark themes #0c0c0c,
  // light theme (b) #ffffff. Match those exact values so pickLogo's
  // dark-detection lines up with the painted surface.
  $mhdrBg = ($adminTheme === 'b') ? '#ffffff' : '#0c0c0c';
  $mhdrLogo = \App\Support\ColorHelper::pickLogo($currentTenant, $mhdrBg);
  $mhdrLogoHeight = (int) ($currentTenant->logo_size_admin ?? 26);
  $mhdrLogoHeight = max(16, min(40, $mhdrLogoHeight)); // clamp to mobile-friendly size

  // MARKER-PATCH-363 — unread alerts count for the mobile header bell
  // (per-user, mirrors StaffAlertController::feed). Server-rendered; refreshes
  // on each page load, like the inbox badge in the attention row.
  $mhdrAlertsUnread = 0;
  if (auth('tenant')->check()) {
      $mhdrAlertsUnread = (int) \App\Models\Tenant\TenantStaffAlert::where('tenant_id', tenant()->id)
          ->where('user_id', auth('tenant')->id())
          ->whereNull('read_at')
          ->count();
  }
@endphp
<header class="ia-mobile-header" role="banner">
  <div class="ia-mobile-header-inner">
    @if($mobileBackLabel && $mobileBackUrl)
      <a href="{{ $mobileBackUrl }}" class="ia-mobile-header-back" aria-label="Back to {{ $mobileBackLabel }}">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <polyline points="15 18 9 12 15 6"/>
        </svg>
        <span>{{ $mobileBackLabel }}</span>
      </a>
    @elseif($mhdrLogo)
      <a href="{{ route('tenant.dashboard') }}" class="ia-mobile-header-brand" aria-label="{{ $currentTenant->name }} — Dashboard">
        <img src="{{ $mhdrLogo }}" alt="{{ $currentTenant->name }}" class="ia-mobile-header-logo" style="height:{{ $mhdrLogoHeight }}px">
      </a>
    @else
      <a href="{{ route('tenant.dashboard') }}" class="ia-mobile-header-brand ia-mobile-header-brand-text" aria-label="{{ $currentTenant->name }} — Dashboard">
        <span class="ia-mobile-header-mark">{{ strtoupper(substr($currentTenant->name, 0, 1)) }}</span>
        <span class="ia-mobile-header-name">{{ $currentTenant->name }}</span>
      </a>
    @endif
    @include('layouts.tenant._location-switcher')

    {{-- MARKER-OFFLINE-SYNC stage 4 — status pill mount, in the header flow --}}
    <span id="ioMountMobile" style="display:inline-flex;align-items:center;margin-left:auto;margin-right:14px"></span>
    {{-- MARKER-PATCH-363 — alerts bell -> full notifications page, with unread badge --}}
    <a href="{{ route('tenant.notifications') }}" class="ia-mobile-header-bell"
       aria-label="Notifications{{ $mhdrAlertsUnread > 0 ? ' — '.$mhdrAlertsUnread.' unread' : '' }}">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>
      </svg>
      @if($mhdrAlertsUnread > 0)<span class="ia-mobile-header-bell-badge">{{ $mhdrAlertsUnread > 99 ? '99+' : $mhdrAlertsUnread }}</span>@endif
    </a>
  </div>
</header>
OFS5_3_EOF

echo "offline-sync-stage-5 applied — server needs view:clear, then hard-refresh"
