{{-- MARKER-SALES-FIND --}}
@php
    $configured = $this->configured();
    $rows   = $this->visibleResults();
    $newN   = count(array_filter($this->results, fn ($r) => $r['status'] === 'new'));
    $mtd    = $this->monthToDateCents();
    $budget = \App\Models\SalesSetting::placesBudgetCents();
    $card   = 'border-radius:12px;padding:14px 16px;border:1px solid rgba(127,127,127,.22)';
    $label  = 'font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;opacity:.55;margin-bottom:8px';
    $muted  = 'font-size:12px;opacity:.65';
    $badge  = 'display:inline-block;border-radius:4px;padding:1px 7px;font-size:11px;font-weight:600;';
    $input  = 'rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm';
@endphp

<x-filament-panels::page>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
  .sfs-grid{display:grid;grid-template-columns:340px 1fr;gap:16px;align-items:start}
  @media (max-width:1100px){.sfs-grid{grid-template-columns:1fr}}
  .sfs-chip{display:inline-flex;align-items:center;border:1px solid rgba(127,127,127,.35);border-radius:999px;padding:3px 10px;font-size:12px;cursor:pointer;margin:0 6px 6px 0;opacity:.8}
  .sfs-chip.on{border-color:rgb(139,92,246);background:rgba(139,92,246,.16);opacity:1}
  .sfs-map{height:440px;border-radius:12px;border:1px solid rgba(127,127,127,.22);overflow:hidden;background:#101114}
  .sfs-row{display:grid;grid-template-columns:22px 1fr auto;gap:10px;padding:10px 12px;border-top:1px solid rgba(127,127,127,.15);align-items:start;cursor:pointer}
  .sfs-row:hover{background:rgba(127,127,127,.08)}
  .sfs-row.sel{background:rgba(139,92,246,.10)}
  .sfs-btn{border:1px solid rgba(127,127,127,.35);border-radius:6px;padding:6px 11px;font-size:13px;font-weight:500;cursor:pointer}
  .sfs-btn.p{background:rgb(139,92,246);border-color:rgb(139,92,246);color:#fff}
  .sfs-btn:disabled{opacity:.45;cursor:not-allowed}
  .leaflet-container{font:inherit}
  .sfs-legend{border-radius:12px;padding:10px 14px;border:1px solid rgba(139,92,246,.35);background:rgba(139,92,246,.08);font-size:13px;margin-bottom:16px}
</style>

<div class="sfs-legend">
  <b>What this page does.</b> Each search calls Google Places, then checks every result against prospects and tenants before you see it.
  Adding a shop creates the prospect with phone, hours, rating and coordinates already filled, and — when a territory rule matches — assigns it to that rep.
  Searches cost money (about {{ $this->money(\App\Models\SalesSetting::placesCostCents()) }} per page of 20); month to date <b>{{ $this->money($mtd) }}</b> of a {{ $this->money($budget) }} budget.
  Nothing on this page changes an existing prospect.
</div>

@unless($configured)
  <div style="{{ $card }};margin-bottom:16px;border-color:rgba(251,191,36,.5)">
    <div style="{{ $label }}">Setup — needed once</div>
    <p style="font-size:13px;opacity:.75;margin:0 0 10px">Paste a Google Cloud API key with <b>Places API (New)</b> enabled. It is stored encrypted and never shown again.</p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
      <div style="flex:1;min-width:280px"><div style="{{ $muted }}">API key</div><input type="password" wire:model="placesKey" class="{{ $input }}" style="width:100%" autocomplete="off"></div>
      <div><div style="{{ $muted }}">Monthly budget ($)</div><input type="number" wire:model="budgetDollars" class="{{ $input }}" style="width:120px"></div>
      <button class="sfs-btn p" wire:click="saveSetup">Save</button>
    </div>
  </div>
@endunless

<div class="sfs-grid">
  <div>
    <div style="{{ $card }}">
      <div style="{{ $label }}">Industry</div>
      <div>
        @foreach($this->industries() as $code => $ind)
          <span class="sfs-chip {{ $industry === $code ? 'on' : '' }}" wire:click="$set('industry', '{{ $code }}')">{{ $ind['label'] }}</span>
        @endforeach
      </div>
      @if($industry === 'custom')
        <input type="text" wire:model="customQuery" class="{{ $input }}" style="width:100%;margin-top:6px" placeholder='e.g. "paddle shop", "sewing machine repair"'>
      @endif

      <div style="{{ $label }};margin-top:16px">Where</div>
      <input type="text" wire:model="place" wire:keydown.enter="search" class="{{ $input }}" style="width:100%" placeholder="Spokane, WA">
      <div style="{{ $muted }};margin-top:10px">Radius <b>{{ $radius }}</b> mi <span style="opacity:.6">(Places caps a search at about 30)</span></div>
      <input type="range" min="5" max="30" step="5" wire:model.live="radius" style="width:100%">

      <div style="{{ $label }};margin-top:16px">On add</div>
      <label style="display:flex;gap:8px;font-size:13px;align-items:center"><input type="checkbox" wire:model="autoAssign" class="rounded"> Assign to the territory's rep</label>
      <label style="display:flex;gap:8px;font-size:13px;align-items:center;margin-top:6px"><input type="checkbox" wire:model.live="hideKnown" class="rounded"> Hide shops already known</label>

      <div style="{{ $muted }};margin-top:12px">This search: about <b>{{ $this->money($this->estimateCents()) }}</b>.</div>
      <button class="sfs-btn p" wire:click="search" wire:loading.attr="disabled" style="width:100%;margin-top:10px" @disabled(! $configured)>
        <span wire:loading.remove wire:target="search">Search Places</span>
        <span wire:loading wire:target="search">Searching…</span>
      </button>
      @if($error)<div style="color:#f87171;font-size:13px;margin-top:8px">{{ $error }}</div>@endif
    </div>

    <div style="{{ $card }};margin-top:16px">
      <div style="{{ $label }}">Recent searches</div>
      @forelse($this->recentSearches() as $s)
        <div style="font-size:12px;padding:4px 0;border-top:1px solid rgba(127,127,127,.12)">
          <b>{{ \App\Services\Sales\ShopFinder::INDUSTRIES[$s->industry]['label'] ?? $s->industry }}</b> near {{ $s->place }} · {{ $s->radius_miles }} mi
          <span style="opacity:.6">· {{ $s->found }} found, {{ $s->new_count }} new · {{ $this->money($s->cost_cents) }} · {{ $s->created_at->diffForHumans() }}</span>
        </div>
      @empty
        <div style="{{ $muted }}">None yet.</div>
      @endforelse
    </div>

    @if($configured)
      <details style="{{ $card }};margin-top:16px">
        <summary style="cursor:pointer;font-size:13px;font-weight:600">Setup</summary>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-top:10px">
          <div style="flex:1;min-width:200px"><div style="{{ $muted }}">Replace API key</div><input type="password" wire:model="placesKey" class="{{ $input }}" style="width:100%" autocomplete="off" placeholder="leave blank to keep"></div>
          <div><div style="{{ $muted }}">Budget ($/mo)</div><input type="number" wire:model="budgetDollars" class="{{ $input }}" style="width:110px"></div>
          <button class="sfs-btn" wire:click="saveSetup">Save</button>
          <button class="sfs-btn" wire:click="testKey">Test</button>
        </div>
      </details>
    @endif
  </div>

  <div>
    <div wire:ignore class="sfs-map" id="sfs-map"></div>
    <script type="application/json" id="sfs-data">@json(['rows' => $this->results, 'selected' => $this->selected, 'located' => $this->located])</script>

    <div style="{{ $card }};margin-top:16px;padding:0">
      <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;flex-wrap:wrap;gap:8px">
        <div>
          <div style="font-weight:600;font-size:14px">Results</div>
          <div style="{{ $muted }}">
            @if($this->results)
              {{ count($this->results) }} found near {{ $this->located }} · <b>{{ $newN }} new</b> · {{ count($this->results) - $newN }} already known · {{ $this->money($this->lastCostCents) }}
            @else
              Run a search to see shops here.
            @endif
          </div>
        </div>
        <div style="display:flex;gap:8px">
          <button class="sfs-btn" wire:click="selectAllNew" @disabled(! $newN)>Select all new</button>
          <button class="sfs-btn p" wire:click="addSelected" wire:loading.attr="disabled" @disabled(! count($selected))>
            {{ count($selected) ? 'Add ' . count($selected) . ' to prospects' : 'Add selected to prospects' }}
          </button>
        </div>
      </div>

      @forelse($rows as $r)
        @php $isSel = in_array($r['place_id'], $selected, true); @endphp
        <div class="sfs-row {{ $isSel ? 'sel' : '' }}" wire:key="r-{{ $r['place_id'] }}" wire:click="toggle('{{ $r['place_id'] }}')">
          <input type="checkbox" class="rounded" style="margin-top:3px" @checked($isSel) @disabled($r['status'] !== 'new') onclick="event.preventDefault()">
          <div>
            <div>
              <b>{{ $r['shop'] }}</b>
              @if($r['status'] === 'new')<span style="{{ $badge }}background:rgba(52,211,153,.15);color:#34d399">New</span>
              @elseif($r['status'] === 'tenant')<span style="{{ $badge }}background:rgba(190,242,100,.18);color:#BEF264">Tenant</span>
              @else<span style="{{ $badge }}background:rgba(154,154,163,.15);color:#9a9aa3">Prospect · {{ \App\Models\SalesProspect::STAGES[$r['stage']] ?? $r['stage'] }}</span>@endif
              @if(($r['gstatus'] ?? null) && $r['gstatus'] !== 'OPERATIONAL')<span style="{{ $badge }}background:rgba(248,113,113,.18);color:#f87171">Closed</span>@endif
            </div>
            <div style="{{ $muted }}">
              {{ $r['city'] }}{{ $r['state'] ? ', ' . $r['state'] : '' }}
              @if(isset($r['miles'])) · {{ $r['miles'] }} mi @endif
              @if($r['rating']) · ★ {{ $r['rating'] }} ({{ $r['rating_count'] }}) @endif
              · {{ $r['website'] ? parse_url($r['website'], PHP_URL_HOST) : 'no website' }}
              @if($r['phone']) · {{ $r['phone'] }} @endif
              @if($r['territory']) · would go to <b>{{ $r['territory_owner'] }}</b> @else · <span style="color:#fbbf24">no territory rule matches</span> @endif
            </div>
          </div>
          <div>
            @if($r['status'] === 'new')
              <button class="sfs-btn" wire:click.stop="addOne('{{ $r['place_id'] }}')">Add</button>
            @elseif($r['prospect_id'])
              <a class="sfs-btn" style="text-decoration:none" href="{{ \App\Filament\Resources\SalesProspectResource::getUrl('edit', ['record' => $r['prospect_id']]) }}" onclick="event.stopPropagation()">Open</a>
            @endif
          </div>
        </div>
      @empty
        @if($this->results)
          <div style="padding:24px;text-align:center;{{ $muted }}">Everything here is already known. Widen the radius or try the next town.</div>
        @endif
      @endforelse
    </div>
  </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
  // MARKER-SALES-FIND — map is wire:ignore'd; it redraws from #sfs-data whenever the component says so.
  (function () {
    var map = null, layer = null;
    function draw() {
      var el = document.getElementById('sfs-map'); if (!el || !window.L) return;
      var data; try { data = JSON.parse(document.getElementById('sfs-data').textContent); } catch (e) { return; }
      if (!map) {
        map = L.map(el, { zoomControl: true }).setView([47.66, -117.43], 9);
        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap &copy; CARTO' }).addTo(map);
      }
      if (layer) { layer.remove(); }
      layer = L.layerGroup().addTo(map);
      var pts = [];
      (data.rows || []).forEach(function (r) {
        if (r.lat == null) return;
        var col = r.status === 'new' ? '#34d399' : (r.status === 'tenant' ? '#BEF264' : '#7c7c86');
        var sel = (data.selected || []).indexOf(r.place_id) >= 0;
        var m = L.circleMarker([r.lat, r.lng], { radius: sel ? 9 : 7, color: sel ? '#fff' : '#0b0b0b', weight: sel ? 2.5 : 1.5, fillColor: col, fillOpacity: 1 });
        m.bindTooltip(r.shop + (r.rating ? ' · ★ ' + r.rating : ''), { direction: 'top' });
        if (r.status === 'new') { m.on('click', function () { var c = el.closest('[wire\\:id]'); if (c && window.Livewire) { Livewire.find(c.getAttribute('wire:id')).call('toggle', r.place_id); } }); }
        m.addTo(layer); pts.push([r.lat, r.lng]);
      });
      if (pts.length) { map.fitBounds(pts, { padding: [24, 24], maxZoom: 13 }); }
    }
    document.addEventListener('DOMContentLoaded', draw);
    document.addEventListener('livewire:initialized', function () { draw(); });
    window.addEventListener('places-updated', function () { setTimeout(draw, 30); });
    if (document.readyState !== 'loading') { setTimeout(draw, 0); }
  })();
</script>
</x-filament-panels::page>
