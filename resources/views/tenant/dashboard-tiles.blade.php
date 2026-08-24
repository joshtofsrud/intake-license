@extends('layouts.tenant.app')
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
