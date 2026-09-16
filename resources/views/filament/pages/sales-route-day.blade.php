{{-- MARKER-SALES-ROUTE --}}
@php
    $stops    = $this->stops();
    $unplaced = $this->unplaced();
    $cands    = $this->candidates();
    $card  = 'border-radius:12px;padding:14px 16px;border:1px solid rgba(127,127,127,.22)';
    $label = 'font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;opacity:.55;margin-bottom:8px';
    $muted = 'font-size:12px;opacity:.65';
    $input = 'rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm';
    $pts = $stops->map(fn ($p) => ['id' => $p->id, 'shop' => $p->shop, 'lat' => (float) $p->lat, 'lng' => (float) $p->lng, 'done' => in_array($p->id, $done, true)])->values()->all();
@endphp

<x-filament-panels::page>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
  .srd-grid{display:grid;grid-template-columns:380px 1fr;gap:16px;align-items:start}
  @media (max-width:1100px){.srd-grid{grid-template-columns:1fr}}
  .srd-map{height:520px;border-radius:12px;border:1px solid rgba(127,127,127,.22);overflow:hidden;background:#101114}
  .srd-btn{border:1px solid rgba(127,127,127,.35);border-radius:6px;padding:6px 11px;font-size:13px;font-weight:500;cursor:pointer}
  .srd-btn.p{background:rgb(139,92,246);border-color:rgb(139,92,246);color:#fff}
  .srd-btn.sm{padding:4px 9px;font-size:12px}
  .srd-btn:disabled{opacity:.45;cursor:not-allowed}
  .srd-stop{display:grid;grid-template-columns:28px 1fr auto;gap:10px;align-items:center;border:1px solid rgba(127,127,127,.25);border-radius:8px;padding:8px 10px;margin-top:8px}
  .srd-stop .n{width:24px;height:24px;border-radius:50%;background:rgb(139,92,246);color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700}
  .srd-stop.done{opacity:.5}.srd-stop.done .n{background:#34d399;color:#0a0a0a}
  .srd-legend{border-radius:12px;padding:10px 14px;border:1px solid rgba(139,92,246,.35);background:rgba(139,92,246,.08);font-size:13px;margin-bottom:16px}
  .srd-chip{display:inline-flex;align-items:center;gap:6px;border:1px solid rgba(127,127,127,.35);border-radius:999px;padding:3px 10px;font-size:12px;cursor:pointer;opacity:.8}
  .srd-chip.on{border-color:rgb(139,92,246);background:rgba(139,92,246,.16);opacity:1}
  .leaflet-container{font:inherit}
</style>

<div class="srd-legend">
  <b>What this is.</b> Prospects with a follow-up due today, ordered into a drive from your start point (nearest-next; mileage is an estimate). Only shops with coordinates can be placed — shops found through Find shops or a Pull details have them, older imports may not, and those are listed below the map rather than dropped.
  Log visit writes a timeline entry and clears the due date; it does not set the next one.
</div>

<div class="srd-grid">
  <div>
    <div style="{{ $card }}">
      <div style="{{ $label }}">Start from</div>
      <div style="display:flex;gap:8px"><input type="text" class="{{ $input }}" style="flex:1" wire:model="startLabel" wire:keydown.enter="setStart" placeholder="2935 W Dean Ave, Spokane"><button class="srd-btn" wire:click="setStart">Set</button></div>
      <div style="{{ $muted }};margin-top:4px">{{ $startLat !== null ? 'Placed · ' . round($startLat, 4) . ', ' . round($startLng, 4) : 'Not placed yet — uses one Places lookup, then remembered.' }}</div>

      <div style="{{ $label }};margin-top:16px">Include</div>
      <select class="{{ $input }}" style="width:100%" wire:model.live="repId"><option value="">Anyone's follow-ups</option>@foreach($this->reps() as $r)<option value="{{ $r->id }}">{{ $r->name }} · {{ $r->agency?->name }}</option>@endforeach</select>
      <div style="margin-top:8px">
        <span class="srd-chip on">Due today</span>
        <span class="srd-chip {{ $includeOverdue ? 'on' : '' }}" wire:click="$toggle('includeOverdue')">Overdue</span>
        <span class="srd-chip {{ $includeNearbyA ? 'on' : '' }}" wire:click="$toggle('includeNearbyA')">Priority A within <b>{{ $nearbyMiles }}</b> mi</span>
      </div>
      @if($includeNearbyA)<input type="range" min="5" max="60" step="5" wire:model.live="nearbyMiles" style="width:100%;margin-top:6px">@endif
      <div style="{{ $muted }};margin-top:10px">Max stops <b>{{ $maxStops }}</b></div>
      <input type="range" min="2" max="{{ \App\Filament\Pages\SalesRouteDay::MAX_STOPS }}" wire:model.live="maxStops" style="width:100%">
      <div style="{{ $muted }};margin-top:6px">{{ $cands->count() }} candidates · {{ $cands->count() - $unplaced->count() }} with coordinates</div>

      <div style="display:flex;gap:8px;margin-top:12px">
        <button class="srd-btn p" wire:click="build" wire:loading.attr="disabled" @disabled($startLat === null)>{{ $route ? 'Rebuild route' : 'Build route' }}</button>
        @if($this->mapsUrl())<a class="srd-btn" style="text-decoration:none" href="{{ $this->mapsUrl() }}" target="_blank" rel="noopener">Open in Google Maps</a>@endif
      </div>
    </div>

    <div style="{{ $card }};margin-top:16px">
      <div style="{{ $label }}">Stops{{ $stops->count() ? ' · ' . $stops->count() . ' · ~' . $this->totalMiles() . ' mi' : '' }}</div>
      @forelse($stops as $i => $p)
        @php $isDone = in_array($p->id, $done, true); @endphp
        <div class="srd-stop {{ $isDone ? 'done' : '' }}" wire:key="s-{{ $p->id }}">
          <div class="n">{{ $isDone ? '✓' : $i + 1 }}</div>
          <div>
            <b>{{ $p->shop }}</b> <span style="{{ $muted }}">{{ $p->city }}</span>
            <div style="{{ $muted }}">{{ $p->next_action ?: 'Priority A nearby' }}@if($p->hours) · {{ Str::limit($p->hours, 40) }}@endif @if($p->phone) · {{ $p->phone }}@endif</div>
          </div>
          <div style="display:flex;gap:6px">
            @unless($isDone)<button class="srd-btn sm" wire:click="logVisit('{{ $p->id }}')">Log visit</button>@endunless
            <a class="srd-btn sm" style="text-decoration:none" href="{{ $this->pipelineUrl($p->id) }}">Open</a>
          </div>
        </div>
      @empty
        <div style="{{ $muted }}">Build a route to see today's stops in driving order.</div>
      @endforelse
    </div>

    @if($unplaced->count())
      <div style="{{ $card }};margin-top:16px;border-color:rgba(251,191,36,.45)">
        <div style="{{ $label }}">Due but not on the map · {{ $unplaced->count() }}</div>
        <div style="{{ $muted }};margin-bottom:6px">No coordinates yet. Pull details places them (one Places call each).</div>
        @foreach($unplaced->take(15) as $p)
          <div style="display:flex;justify-content:space-between;align-items:center;font-size:13px;padding:5px 0;border-top:1px solid rgba(127,127,127,.12)">
            <span><b>{{ $p->shop }}</b> <span style="{{ $muted }}">{{ $p->city }}{{ $p->state ? ', ' . $p->state : '' }} · {{ $p->next_action }}</span></span>
            <button class="srd-btn sm" wire:click="placeOne('{{ $p->id }}')" wire:loading.attr="disabled">Pull details</button>
          </div>
        @endforeach
      </div>
    @endif
  </div>

  <div>
    <div wire:ignore class="srd-map" id="srd-map"></div>
    <script type="application/json" id="srd-data">@json(['start' => $startLat !== null ? ['lat' => $startLat, 'lng' => $startLng, 'label' => $startLabel] : null, 'stops' => $pts])</script>
  </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
  // MARKER-SALES-ROUTE — the map is wire:ignore'd and redraws from #srd-data.
  (function () {
    var map = null, layer = null;
    function draw() {
      var el = document.getElementById('srd-map'); if (!el || !window.L) return;
      var d; try { d = JSON.parse(document.getElementById('srd-data').textContent); } catch (e) { return; }
      if (!map) {
        map = L.map(el).setView([47.66, -117.43], 9);
        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap &copy; CARTO' }).addTo(map);
      }
      if (layer) { layer.remove(); }
      layer = L.layerGroup().addTo(map);
      var pts = [];
      if (d.start) {
        L.circleMarker([d.start.lat, d.start.lng], { radius: 7, color: '#0b0b0b', weight: 1.5, fillColor: '#BEF264', fillOpacity: 1 }).bindTooltip('Start').addTo(layer);
        pts.push([d.start.lat, d.start.lng]);
      }
      (d.stops || []).forEach(function (s, i) {
        var m = L.marker([s.lat, s.lng], { icon: L.divIcon({ className: '', html: '<div style="width:24px;height:24px;border-radius:50%;background:' + (s.done ? '#34d399' : '#8b5cf6') + ';color:#fff;font:700 11px Inter,sans-serif;display:flex;align-items:center;justify-content:center;border:2px solid #fff">' + (s.done ? '✓' : (i + 1)) + '</div>', iconSize: [24, 24], iconAnchor: [12, 12] }) });
        m.bindTooltip(s.shop, { direction: 'top' }); m.addTo(layer); pts.push([s.lat, s.lng]);
      });
      if (pts.length > 1) { L.polyline(pts, { color: '#8b5cf6', weight: 2.5, dashArray: '6 4' }).addTo(layer); }
      if (pts.length) { map.fitBounds(pts, { padding: [28, 28], maxZoom: 13 }); }
    }
    document.addEventListener('DOMContentLoaded', draw);
    document.addEventListener('livewire:initialized', function () { draw(); });
    window.addEventListener('route-updated', function () { setTimeout(draw, 30); });
    if (document.readyState !== 'loading') { setTimeout(draw, 0); }
  })();
</script>
</x-filament-panels::page>
