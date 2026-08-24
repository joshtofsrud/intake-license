#!/usr/bin/env python3
"""Tile dashboard — part 2: the view, its styles, edit mode, routes.

Still additive: a new blade, a new stylesheet, two new routes and one
line added to the existing dashboard so Overview can offer the toggle.
Nothing in the current dashboard's zones changes.

Full-colour tiles, per the choice to build one and judge it live. The
palette lives in .ia-tile--<tone> rules, so switching to the quieter
"dark tile, coloured icon" variant later is a change to those rules
only, not to the markup.
Run from repo root: python3 apply-tile-dashboard-view.py
"""
import os, sys

ROOT = os.getcwd()
def read(p):
    with open(os.path.join(ROOT, p)) as f: return f.read()
def write(p, s):
    os.makedirs(os.path.dirname(os.path.join(ROOT, p)), exist_ok=True)
    with open(os.path.join(ROOT, p), 'w') as f: f.write(s)
def sub(p, old, new, label):
    s = read(p)
    if new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    write(p, s.replace(old, new, 1))
    print(f"OK: {label}")
def newfile(p, content, label):
    if os.path.exists(os.path.join(ROOT, p)):
        print(f"SKIP (exists): {label}"); return
    write(p, content)
    print(f"OK: {label}")

if not os.path.exists(os.path.join(ROOT, 'app/Support/DashboardTiles.php')):
    print("FAIL: run apply-tile-dashboard-core.py first"); sys.exit(1)

# ============================================================
# 1) Routes
# ============================================================
sub('routes/web.php',
    """            Route::get('/dashboard/day.json', [TenantControllers\\DashboardController::class, 'dayJson'])->name('dashboard.day');""",
    """            Route::get('/dashboard/day.json', [TenantControllers\\DashboardController::class, 'dayJson'])->name('dashboard.day');
            // MARKER-TILES
            Route::post('/dashboard/view',        [TenantControllers\\DashboardController::class, 'setView'])->name('dashboard.view');
            Route::post('/dashboard/tiles',       [TenantControllers\\DashboardController::class, 'saveTiles'])->name('dashboard.tiles.save');
            Route::post('/dashboard/tiles/reset', [TenantControllers\\DashboardController::class, 'resetTiles'])->name('dashboard.tiles.reset');""",
    "routes")

# ============================================================
# 2) Stylesheet
# ============================================================
newfile('public/css/tenant/dashboard-simple.css', """/* MARKER-TILES — the simplified tile dashboard.
   Additive: nothing here overrides dashboard.css or dashboard-tiles.css,
   which continue to drive the Overview dashboard unchanged.

   Tone colours are isolated in .ia-tile--<tone>. To try the quieter
   variant (dark tile, coloured icon) later, change these rules only —
   the markup doesn't encode the palette. */

.ia-tiles-head {
  display: flex; align-items: flex-start; justify-content: space-between;
  gap: 16px; flex-wrap: wrap; margin-bottom: 20px;
}
.ia-tiles-greet { font-size: 24px; font-weight: 600; letter-spacing: -0.02em; }
.ia-tiles-sub   { font-size: 13px; color: var(--ia-text-dim); margin-top: 3px; }
.ia-tiles-sub b { color: #FBBF24; font-weight: 500; }
.ia-tiles-actions { display: flex; align-items: center; gap: 8px; }

/* Overview / Tiles switch */
.ia-viewseg {
  display: inline-flex; background: var(--ia-surface);
  border: 1px solid var(--ia-border); border-radius: 9px; padding: 3px;
}
.ia-viewseg button {
  padding: 6px 13px; font-size: 12px; font-weight: 600; border-radius: 6px;
  background: none; border: none; cursor: pointer; font-family: inherit;
  color: var(--ia-text-dim);
}
.ia-viewseg button.on { background: var(--ia-surface-2); color: var(--ia-text); }
.ia-viewseg form { margin: 0; display: inline; }

.ia-tiles-zone {
  font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.1em;
  color: var(--ia-text-muted); font-weight: 700; margin: 26px 2px 10px;
}

.ia-tiles-grid {
  display: grid; grid-template-columns: repeat(4, 1fr);
  grid-auto-rows: 118px; gap: 10px;
}

.ia-tile {
  position: relative; border-radius: 4px; padding: 14px 15px;
  display: flex; flex-direction: column; overflow: hidden;
  color: #fff; text-decoration: none; cursor: pointer;
  transition: filter 0.12s ease, transform 0.1s ease;
}
.ia-tile:hover  { filter: brightness(1.14); }
.ia-tile:active { transform: scale(0.975); }
.ia-tile:focus-visible { outline: 2px solid #fff; outline-offset: -4px; }
.ia-tile svg  { opacity: 0.92; }
.ia-tile .nm  { margin-top: auto; font-size: 13.5px; font-weight: 600; letter-spacing: -0.01em; }
.ia-tile .mt  { font-size: 11.5px; opacity: 0.72; margin-top: 2px; line-height: 1.35; }
.ia-tile .badge {
  position: absolute; top: 11px; right: 12px;
  background: rgba(0,0,0,0.28); border-radius: 99px;
  padding: 2px 8px; font-size: 11px; font-weight: 700;
}
.ia-tile .badge.alert { background: #DC2626; }
.ia-tile--wide { grid-column: span 2; }

/* Tones */
.ia-tile--green  { background: #3F6212; }
.ia-tile--teal   { background: #155E5E; }
.ia-tile--blue   { background: #1E3A8A; }
.ia-tile--indigo { background: #312E81; }
.ia-tile--plum   { background: #4C1D57; }
.ia-tile--rust   { background: #7C2D12; }
.ia-tile--amber  { background: #78350F; }
.ia-tile--slate  { background: #293548; }
.ia-tile--moss   { background: #2B3D16; }
.ia-tile--ink    { background: #1C1C1F; box-shadow: inset 0 0 0 1px var(--ia-border); }

/* ── Edit mode ───────────────────────────────────────────────────────── */
.ia-tiles-grid.is-editing .ia-tile { cursor: grab; }
.ia-tiles-grid.is-editing .ia-tile:active { cursor: grabbing; }
.ia-tile.dragging { opacity: 0.4; }
.ia-tile.drag-over { outline: 2px dashed rgba(255,255,255,0.7); outline-offset: -3px; }

.ia-tile-hide {
  position: absolute; top: 8px; right: 8px; z-index: 2;
  width: 22px; height: 22px; border-radius: 50%;
  display: none; align-items: center; justify-content: center;
  background: rgba(0,0,0,0.45); color: #fff;
  border: none; cursor: pointer; font-size: 14px; line-height: 1; font-family: inherit;
}
.ia-tiles-grid.is-editing .ia-tile-hide { display: flex; }
.ia-tile-hide:hover { background: #DC2626; }

.ia-tiles-tray {
  border: 1px dashed var(--ia-border-strong, rgba(255,255,255,0.2));
  border-radius: 10px; padding: 12px 14px; margin-top: 14px;
}
.ia-tiles-tray-label {
  font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.08em;
  color: var(--ia-text-muted); font-weight: 700; margin-bottom: 10px;
}
.ia-tiles-tray-items { display: flex; flex-wrap: wrap; gap: 8px; }
.ia-tray-chip {
  display: inline-flex; align-items: center; gap: 7px;
  background: var(--ia-surface-2); border: 1px solid var(--ia-border);
  border-radius: 99px; padding: 6px 12px;
  font-size: 12px; color: var(--ia-text); cursor: pointer; font-family: inherit;
}
.ia-tray-chip:hover { border-color: var(--ia-border-strong, rgba(255,255,255,0.25)); }
.ia-tray-chip .dot { width: 8px; height: 8px; border-radius: 50%; flex: none; }
.ia-tiles-tray-empty { font-size: 12px; color: var(--ia-text-muted); }

.ia-tiles-editbar {
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
  margin-top: 14px; font-size: 12px; color: var(--ia-text-dim);
}

@media (max-width: 820px) { .ia-tiles-grid { grid-template-columns: repeat(2, 1fr); } }
""", "dashboard-simple.css")

# ============================================================
# 3) The view
# ============================================================
newfile('resources/views/tenant/dashboard-tiles.blade.php', """@extends('layouts.tenant.app')
{{-- MARKER-TILES — the simplified dashboard. A second view alongside the
     Overview dashboard, which is unchanged and stays the default. --}}
@section('title', 'Dashboard')

@push('styles')
  <link rel="stylesheet" href="{{ asset('css/tenant/dashboard-simple.css') }}?v={{ filemtime(public_path('css/tenant/dashboard-simple.css')) }}">
@endpush

@section('content')
@php
  $L        = $launcher ?? [];
  $visible  = $tiles['visible'] ?? [];
  $hiddenT  = $tiles['hidden'] ?? [];
  $cards    = collect($attention['cards'] ?? [])->filter(fn ($c) => ($c['count'] ?? 0) > 0)->values();
@endphp

<div class="ia-tiles-head">
  <div>
    <div class="ia-tiles-greet">{{ $greeting['name'] ? 'Good ' . $greeting['time_of_day'] . ', ' . $greeting['name'] . '.' : 'Good ' . $greeting['time_of_day'] . '.' }}</div>
    <div class="ia-tiles-sub">
      {{ tlocal_date(tnow(), 'l, F j') }}
      @if($cards->count()) · <b>{{ $cards->count() }} {{ Str::plural('thing', $cards->count()) }} need you today</b>@endif
    </div>
  </div>
  <div class="ia-tiles-actions">
    <button type="button" class="ia-btn ia-btn--sm" id="ia-tiles-edit">Edit tiles</button>
    <div class="ia-viewseg">
      <form method="POST" action="{{ route('tenant.dashboard.view') }}">
        @csrf<input type="hidden" name="view" value="overview">
        <button type="submit">Overview</button>
      </form>
      <button type="button" class="on">Tiles</button>
    </div>
  </div>
</div>

{{-- Needs you today: kept deliberately. Simple shouldn't mean blind to a
     booking that's waiting. --}}
@if($cards->count())
  <div class="ia-tiles-zone">Needs you</div>
  <div class="ia-tiles-grid">
    @foreach($cards->take(4) as $card)
      <a class="ia-tile ia-tile--rust ia-tile--wide" href="{{ $card['link'] ?? '#' }}">
        <span class="badge alert">{{ $card['count'] }}</span>
        <div style="font-size:26px;font-weight:300;margin-top:auto;line-height:1">{{ $card['count'] }}</div>
        <div class="nm" style="margin-top:8px">{{ $card['title'] ?? '' }}</div>
        <div class="mt">{{ $card['desc'] ?? '' }}</div>
      </a>
    @endforeach
  </div>
@endif

<div class="ia-tiles-zone">Jump to</div>
<div class="ia-tiles-grid" id="ia-tiles-grid">
  @foreach($visible as $key => $def)
    <a class="ia-tile ia-tile--{{ $def['tone'] }}" href="{{ route($def['route']) }}"
       data-tile="{{ $key }}" data-tone="{{ $def['tone'] }}" data-label="{{ $def['label'] }}">
      <button type="button" class="ia-tile-hide" title="Hide {{ $def['label'] }}"
              aria-label="Hide {{ $def['label'] }}">&times;</button>
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        {!! $def['icon'] !!}
      </svg>
      <div class="nm">{{ $def['label'] }}</div>
      <div class="mt">{{ ($def['stat'])($L) }}</div>
    </a>
  @endforeach
</div>

<div id="ia-tiles-editui" hidden>
  <div class="ia-tiles-tray">
    <div class="ia-tiles-tray-label">Hidden tiles — click one to bring it back</div>
    <div class="ia-tiles-tray-items" id="ia-tiles-tray">
      @forelse($hiddenT as $key => $def)
        <button type="button" class="ia-tray-chip" data-restore="{{ $key }}"
                data-tone="{{ $def['tone'] }}" data-label="{{ $def['label'] }}">
          <span class="dot ia-tile--{{ $def['tone'] }}"></span>{{ $def['label'] }}
        </button>
      @empty
        <span class="ia-tiles-tray-empty" data-tray-empty>Nothing hidden.</span>
      @endforelse
    </div>
  </div>
  <div class="ia-tiles-editbar">
    <span>Drag tiles to reorder · &times; hides one</span>
    <button type="button" class="ia-btn ia-btn--primary ia-btn--sm" id="ia-tiles-done">Done</button>
    <form method="POST" action="{{ route('tenant.dashboard.tiles.reset') }}" style="margin:0">
      @csrf
      <button class="ia-btn ia-btn--sm">Reset to default</button>
    </form>
  </div>
</div>
@endsection

@push('scripts')
<script>
// MARKER-TILES — reorder, hide, restore. Saves on Done rather than on every
// drag, so a reorder is one request instead of a dozen.
(function () {
  var grid = document.getElementById('ia-tiles-grid');
  var editBtn = document.getElementById('ia-tiles-edit');
  if (!grid || !editBtn) return;

  var editUi = document.getElementById('ia-tiles-editui');
  var tray   = document.getElementById('ia-tiles-tray');
  var doneBtn = document.getElementById('ia-tiles-done');
  var saveUrl = @json(route('tenant.dashboard.tiles.save'));
  var csrf    = @json(csrf_token());
  var editing = false;
  var dragEl  = null;

  function setEditing(on) {
    editing = on;
    grid.classList.toggle('is-editing', on);
    editUi.hidden = !on;
    editBtn.hidden = on;
    grid.querySelectorAll('.ia-tile').forEach(function (t) { t.draggable = on; });
  }

  // In edit mode a tile is a handle, not a link.
  grid.addEventListener('click', function (e) {
    var hide = e.target.closest('.ia-tile-hide');
    if (hide) {
      e.preventDefault();
      hideTile(hide.closest('.ia-tile'));
      return;
    }
    if (editing) e.preventDefault();
  });

  function hideTile(tile) {
    if (!tile) return;
    var chip = document.createElement('button');
    chip.type = 'button';
    chip.className = 'ia-tray-chip';
    chip.setAttribute('data-restore', tile.dataset.tile);
    chip.dataset.tone = tile.dataset.tone;
    chip.dataset.label = tile.dataset.label;
    chip.innerHTML = '<span class="dot ia-tile--' + tile.dataset.tone + '"></span>';
    chip.appendChild(document.createTextNode(tile.dataset.label));
    tray.appendChild(chip);
    tile.remove();
    var empty = tray.querySelector('[data-tray-empty]');
    if (empty) empty.remove();
  }

  tray.addEventListener('click', function (e) {
    var chip = e.target.closest('[data-restore]');
    if (!chip) return;
    // Rebuilt on the next load with its icon and live sub-stat; showing a
    // placeholder now beats pretending we have those client-side.
    var t = document.createElement('a');
    t.className = 'ia-tile ia-tile--' + chip.dataset.tone;
    t.href = '#';
    t.dataset.tile = chip.getAttribute('data-restore');
    t.dataset.tone = chip.dataset.tone;
    t.dataset.label = chip.dataset.label;
    t.draggable = editing;
    t.innerHTML = '<button type="button" class="ia-tile-hide">&times;</button>'
                + '<div class="nm"></div><div class="mt">Saved — reloading…</div>';
    t.querySelector('.nm').textContent = chip.dataset.label;
    grid.appendChild(t);
    chip.remove();
    if (!tray.children.length) {
      var s = document.createElement('span');
      s.className = 'ia-tiles-tray-empty';
      s.setAttribute('data-tray-empty', '');
      s.textContent = 'Nothing hidden.';
      tray.appendChild(s);
    }
  });

  grid.addEventListener('dragstart', function (e) {
    var t = e.target.closest('.ia-tile');
    if (!editing || !t) return;
    dragEl = t;
    t.classList.add('dragging');
    try { e.dataTransfer.setData('text/plain', ''); } catch (err) {}
    e.dataTransfer.effectAllowed = 'move';
  });
  grid.addEventListener('dragend', function () {
    if (dragEl) dragEl.classList.remove('dragging');
    grid.querySelectorAll('.drag-over').forEach(function (x) { x.classList.remove('drag-over'); });
    dragEl = null;
  });
  grid.addEventListener('dragover', function (e) {
    if (!editing || !dragEl) return;
    e.preventDefault();
    var over = e.target.closest('.ia-tile');
    if (!over || over === dragEl) return;
    var r = over.getBoundingClientRect();
    grid.insertBefore(dragEl, (e.clientX < r.left + r.width / 2) ? over : over.nextSibling);
  });

  editBtn.addEventListener('click', function () { setEditing(true); });

  doneBtn.addEventListener('click', function () {
    var order  = Array.prototype.map.call(grid.querySelectorAll('.ia-tile'), function (t) { return t.dataset.tile; });
    var hidden = Array.prototype.map.call(tray.querySelectorAll('[data-restore]'), function (c) { return c.getAttribute('data-restore'); });

    doneBtn.disabled = true;
    fetch(saveUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify({ order: order, hidden: hidden })
    }).then(function (r) {
      // Reload so restored tiles come back with their real icon and count.
      if (r.ok) { window.location.reload(); return; }
      doneBtn.disabled = false;
      alert('Could not save your tiles — try again.');
    }).catch(function () {
      doneBtn.disabled = false;
      alert('Could not save your tiles — check your connection.');
    });
  });
})();
</script>
@endpush
""", "dashboard-tiles.blade.php")

# ============================================================
# 4) One line on the existing dashboard — the way INTO tiles
# ============================================================
sub('resources/views/tenant/dashboard.blade.php',
    "@include('tenant.dashboard._zone_triage_tiles')",
    """{{-- MARKER-TILES — the only change to this view: a way to reach the
     simplified dashboard. Every zone below is untouched. --}}
<div style="display:flex;justify-content:flex-end;margin-bottom:10px">
  <div class="ia-viewseg" style="display:inline-flex;background:var(--ia-surface);border:1px solid var(--ia-border);border-radius:9px;padding:3px">
    <button type="button" class="on" style="padding:6px 13px;font-size:12px;font-weight:600;border-radius:6px;background:var(--ia-surface-2);color:var(--ia-text);border:none;cursor:pointer;font-family:inherit">Overview</button>
    <form method="POST" action="{{ route('tenant.dashboard.view') }}" style="margin:0;display:inline">
      @csrf<input type="hidden" name="view" value="tiles">
      <button type="submit" style="padding:6px 13px;font-size:12px;font-weight:600;border-radius:6px;background:none;color:var(--ia-text-dim);border:none;cursor:pointer;font-family:inherit">Tiles</button>
    </form>
  </div>
</div>

@include('tenant.dashboard._zone_triage_tiles')""",
    "dashboard: view switch")

print("\\nDone. Post-deploy: php artisan migrate --force && php artisan view:clear")
