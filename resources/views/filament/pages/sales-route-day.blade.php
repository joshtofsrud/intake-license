{{-- one full-width list; the route is one line until it's built, then the map opens. --}}
@php
    $agenda   = $this->agenda();
    $verify   = $this->verifyList();
    $vTotal   = $this->verifyTotal();
    $tally    = $this->tally();
    $stops    = $this->stops();
    $stopNo   = $stops->pluck('id')->flip()->map(fn ($i) => $i + 1);
    $unplaced = $this->unplaced();
    $pts = $stops->map(fn ($p) => ['lat' => (float) $p->lat, 'lng' => (float) $p->lng, 'shop' => $p->shop, 'done' => in_array($p->id, $done, true)])->values()->all();
    $todayStart = now()->startOfDay();
    $extra = $stops->reject(fn ($s) => $agenda->contains('id', $s->id));
    $nothing = $agenda->isEmpty() && $verify->isEmpty() && $extra->isEmpty();
    $hasAny = \App\Models\SalesProspect::query()->exists();
@endphp

<x-filament-panels::page>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
  .sx-root { --sx-line:rgba(255,255,255,.075); --sx-line-2:rgba(255,255,255,.14); --sx-dim:#a3a3ab; --sx-faint:#74747d;
    --sx-violet:#8b5cf6; --sx-vsoft:rgba(139,92,246,.17); --sx-lime:#BEF264; --sx-amber:#f5b942; --sx-red:#f47c7c; font-size:14px; }
  .sx-in { background-color:rgba(255,255,255,.04); border:1px solid var(--sx-line-2); border-radius:7px; padding:6px 10px; font-size:13px; color:inherit; }
  select.sx-in { padding-right:32px; background-repeat:no-repeat; }
  .sx-in option { background:#18181b; }
  .sx-btn { border:1px solid var(--sx-line-2); background:none; border-radius:7px; padding:5px 11px; font-weight:500; font-size:12.5px; cursor:pointer; white-space:nowrap; color:inherit; text-decoration:none; display:inline-block; }
  .sx-btn.p { background:var(--sx-violet); border-color:var(--sx-violet); color:#fff; }
  .sx-btn.q { border-color:transparent; color:var(--sx-dim); }
  .sx-btn.q:hover { color:#fff; border-color:var(--sx-line-2); }
  .sx-btn:disabled { opacity:.45; cursor:default; }
  .sx-hrow { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
  .sx-tally { margin-left:auto; color:var(--sx-dim); font-size:13px; }
  .sx-tally b { color:#fff; font-weight:600; } .sx-tally .am { color:var(--sx-amber); } .sx-tally .li { color:var(--sx-lime); }
  .sx-legend { color:var(--sx-faint); font-size:12.5px; margin:10px 0 0; }
  .sx-route { margin-top:22px; border-top:1px solid var(--sx-line); border-bottom:1px solid var(--sx-line); }
  .sx-rline { display:flex; align-items:center; gap:10px; flex-wrap:wrap; padding:10px 0; font-size:13px; color:var(--sx-dim); }
  .sx-rline b { color:#fff; }
  .sx-addr { width:280px; }
  .sx-opts { display:flex; align-items:center; gap:12px; margin-left:auto; flex-wrap:wrap; }
  .sx-tog { display:inline-flex; align-items:center; gap:6px; color:var(--sx-dim); cursor:pointer; }
  .sx-tog input[type=checkbox] { accent-color:var(--sx-violet); }
  .sx-mini { width:56px; }
  .sx-mapwrap { display:grid; grid-template-columns:1fr 300px; gap:24px; padding:0 0 16px; }
  @media (max-width:1000px) { .sx-mapwrap { grid-template-columns:1fr; } }
  .srd-map { height:300px; border-radius:10px; overflow:hidden; background:#1c1c20; }
  .leaflet-container { font:inherit; }
  .sx-stops { list-style:none; margin:0; padding:0; font-size:13px; }
  .sx-stops li { display:flex; gap:10px; align-items:center; padding:8px 0; border-bottom:1px solid var(--sx-line); }
  .sx-n { width:20px; height:20px; border-radius:50%; background:var(--sx-violet); color:#fff; font-size:11px; font-weight:700; display:flex; align-items:center; justify-content:center; flex:none; }
  .sx-n.ok { background:#34d399; }
  .sx-un { font-size:12.5px; color:var(--sx-faint); padding:0 0 12px; line-height:1.6; }
  .sx-sec { margin-top:26px; }
  .sx-sh { display:flex; align-items:baseline; gap:10px; font-weight:600; font-size:15px; padding-bottom:8px; border-bottom:1px solid var(--sx-line); }
  .sx-sh span { font-weight:400; color:var(--sx-faint); font-size:12.5px; }
  .sx-row { display:grid; grid-template-columns:70px 22px 1fr auto; gap:0 12px; align-items:center; padding:11px 0; border-bottom:1px solid var(--sx-line); }
  .sx-row time { color:var(--sx-dim); font-size:13px; font-variant-numeric:tabular-nums; } .sx-row time.late { color:var(--sx-amber); }
  .sx-dot { width:18px; height:18px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:10.5px; font-weight:700; }
  .sx-dot.stop { background:var(--sx-violet); color:#fff; } .sx-dot.call { border:1.5px solid var(--sx-dim); } .sx-dot.ver { border:1.5px dashed var(--sx-faint); }
  .sx-w b { font-weight:550; font-size:14.5px; } .sx-w b a { color:inherit; text-decoration:none; }
  .sx-w div { color:var(--sx-dim); font-size:13px; margin-top:1px; } .sx-w .tel { color:#fff; }
  .sx-acts { display:flex; gap:4px; flex-wrap:wrap; justify-content:flex-end; }
  .sx-row.done { opacity:.4; }
  .sx-empty { padding:28px 0; border-bottom:1px solid var(--sx-line); display:flex; align-items:center; gap:16px; }
  .sx-empty p { margin:0; color:var(--sx-dim); flex:1; line-height:1.5; } .sx-empty p b { color:#fff; }
  .sx-more { padding:10px 0; color:var(--sx-faint); font-size:12.5px; }
  @media (max-width:700px) { .sx-row { grid-template-columns:56px 20px 1fr; } .sx-acts { grid-column:3; justify-content:flex-start; margin-top:6px; } }
</style>

<div class="sx-root">
  <div class="sx-hrow">
    <select class="sx-in" wire:model.live="repId" aria-label="Whose follow-ups">
      <option value="">Everyone's follow-ups</option><option value="none">House (no rep)</option>
      @foreach($this->reps() as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach
    </select>
    <select class="sx-in" wire:model.live="industryId" aria-label="Industry">
      <option value="">All industries</option>
      @foreach($this->industries() as $ind)<option value="{{ $ind->id }}">{{ $ind->name }}</option>@endforeach
    </select>
    <span class="sx-tally">
      @if($nothing)
        Nothing due · {{ now()->format('l, M j') }}
      @else
        <b>{{ $tally['todo'] }}</b> follow-ups · <b class="{{ $tally['overdue'] ? 'am' : '' }}">{{ $tally['overdue'] }}</b> overdue · <b>{{ $vTotal }}</b> to verify @if($tally['trials']) · <b class="li">${{ number_format($tally['trials']) }}</b>/mo in trials @endif
      @endif
    </span>
  </div>
  <p class="sx-legend">Done clears a follow-up and logs it on the shop; set the next one from the shop itself.</p>

  {{-- Route: one line until it's built. --}}
  <div class="sx-route">
    <div class="sx-rline">
      <span><b>Route</b> · {{ $stops->count() ? $stops->count() . ' stops, about ' . $this->totalMiles() . ' mi' : 'Not built' }}</span>
      <input type="text" class="sx-in sx-addr" wire:model="startLabel" wire:keydown.enter="setStart" placeholder="Start from: city or address" aria-label="Start from">
      <button class="sx-btn" wire:click="setStart" wire:loading.attr="disabled">Save</button>
      <span class="sx-opts">
        <label class="sx-tog"><input type="checkbox" wire:model.live="includeOverdue"> Overdue</label>
        <label class="sx-tog"><input type="checkbox" wire:model.live="includeNearbyA"> A shops within <input type="number" min="1" max="100" class="sx-in sx-mini" wire:model.live.debounce.500ms="nearbyMiles"> mi</label>
        <label class="sx-tog">Max <input type="number" min="1" max="{{ \App\Filament\Pages\SalesRouteDay::MAX_STOPS }}" class="sx-in sx-mini" wire:model.live.debounce.500ms="maxStops"></label>
        <button class="sx-btn p" wire:click="build" wire:loading.attr="disabled" @disabled($startLat === null) title="{{ $startLat === null ? 'Save a start point first' : '' }}">{{ $stops->count() ? 'Rebuild route' : 'Build route' }}</button>
      </span>
    </div>
    @error('startLabel')<div style="color:var(--sx-red);font-size:12px;padding-bottom:8px">Enter a city or address for the start point.</div>@enderror
    @if($startLat === null)<div style="color:var(--sx-faint);font-size:12px;padding-bottom:10px">No start point saved yet. Saving it uses one Places lookup and it's kept for every day after.</div>@endif

    <div class="sx-mapwrap" @if(! $showMap || ! $stops->count()) hidden @endif>
      <div wire:ignore class="srd-map" id="srd-map"></div>
      <div>
        <ol class="sx-stops">
          @foreach($stops as $s)
            <li><span class="sx-n {{ in_array($s->id, $done, true) ? 'ok' : '' }}">{{ in_array($s->id, $done, true) ? '✓' : $loop->iteration }}</span>{{ $s->shop }}, {{ $s->city }}</li>
          @endforeach
        </ol>
        <div style="display:flex;gap:6px;margin-top:10px">
          @if($this->mapsUrl())<a class="sx-btn" href="{{ $this->mapsUrl() }}" target="_blank" rel="noopener">Open in Google Maps</a>@endif
          <button class="sx-btn q" wire:click="$set('showMap', false)">Hide map</button>
        </div>
      </div>
    </div>
    @if($stops->count() && ! $showMap)
      <div style="padding:0 0 10px"><button class="sx-btn q" wire:click="$set('showMap', true)">Show map</button></div>
    @endif
    @if($unplaced->count())
      <div class="sx-un">
        {{ $unplaced->count() }} due {{ $unplaced->count() === 1 ? 'shop has' : 'shops have' }} no coordinates, so {{ $unplaced->count() === 1 ? 'it isn\'t' : 'they aren\'t' }} on the route:
        @foreach($unplaced as $u)<span style="white-space:nowrap">{{ $u->shop }} <button class="sx-btn q" style="padding:1px 6px" wire:click="placeOne('{{ $u->id }}')" title="One Places lookup">Place</button></span>{{ ! $loop->last ? ',' : '' }} @endforeach
      </div>
    @endif
    {{-- not @json: that directive splits on commas --}}
    <script type="application/json" id="srd-data">{!! json_encode(['start' => $startLat !== null ? ['lat' => $startLat, 'lng' => $startLng, 'label' => $startLabel] : null, 'stops' => $pts], JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
  </div>

  @if($nothing)
    <div class="sx-empty">
      @if($hasAny)
        <p><b>Nothing to do today.</b> Follow-ups show here when a shop has a next action date, and shops to verify once you've added some.</p>
        <a class="sx-btn" href="{{ \App\Filament\Pages\SalesPipeline::getUrl() }}">Open Prospects</a>
      @else
        <p><b>Nothing to do yet.</b> Follow-ups and shops to verify show up here once you have prospects.</p>
        <a class="sx-btn p" href="{{ \App\Filament\Pages\SalesFindShops::getUrl() }}">Find shops</a>
      @endif
    </div>
  @else
    @if($agenda->isNotEmpty() || $extra->isNotEmpty())
      <div class="sx-sec">
        <div class="sx-sh">Follow-ups <span>{{ $tally['todo'] }}{{ $tally['overdue'] ? ' · ' . $tally['overdue'] . ' overdue' : '' }}</span></div>
        @foreach($agenda as $p)
          @php $late = $p->next_action_on->lt($todayStart); $n = $stopNo[$p->id] ?? null; $isDone = in_array($p->id, $done, true); @endphp
          <div class="sx-row {{ $isDone ? 'done' : '' }}" wire:key="a-{{ $p->id }}">
            <time class="{{ $late ? 'late' : '' }}">{{ $late ? $p->next_action_on->format('M j') : 'Today' }}</time>
            @if($n)<span class="sx-dot stop">{{ $n }}</span>@else<span class="sx-dot call"></span>@endif
            <div class="sx-w"><b><a href="{{ $this->pipelineUrl($p->id) }}">{{ $p->shop }}</a></b>
              <div>{{ $n ? 'Visit, stop ' . $n . ' · ' : '' }}{{ $p->next_action ?: 'Follow up' }} · @if($p->phone)<span class="tel">{{ $p->phone }}</span>@else{{ $p->city }}@endif{{ $p->rep ? ' · ' . $p->rep->name : '' }}</div></div>
            <div class="sx-acts">
              @if($n)<button class="sx-btn" wire:click="logVisit('{{ $p->id }}')" @disabled($isDone)>Log visit</button>@else<button class="sx-btn" wire:click="markDone('{{ $p->id }}')">Done</button>@endif
              @unless($n)<button class="sx-btn q" wire:click="moveTomorrow('{{ $p->id }}')">Tomorrow</button>@endunless
              <a class="sx-btn q" href="{{ $this->pipelineUrl($p->id) }}">Open</a>
            </div>
          </div>
        @endforeach
        @foreach($extra as $p)
          @php $isDone = in_array($p->id, $done, true); @endphp
          <div class="sx-row {{ $isDone ? 'done' : '' }}" wire:key="x-{{ $p->id }}">
            <time>Any time</time><span class="sx-dot stop">{{ $stopNo[$p->id] }}</span>
            <div class="sx-w"><b><a href="{{ $this->pipelineUrl($p->id) }}">{{ $p->shop }}</a></b><div>Visit, stop {{ $stopNo[$p->id] }} · A shop near your start · {{ $p->city }}</div></div>
            <div class="sx-acts"><button class="sx-btn" wire:click="logVisit('{{ $p->id }}')" @disabled($isDone)>Log visit</button><a class="sx-btn q" href="{{ $this->pipelineUrl($p->id) }}">Open</a></div>
          </div>
        @endforeach
      </div>
    @endif
    @if($verify->isNotEmpty())
      <div class="sx-sec">
        <div class="sx-sh">Needs verifying <span>{{ $vTotal > $verify->count() ? 'Top ' . $verify->count() . ' of ' . $vTotal . ' by lead score' : $vTotal . ' to call' }}</span></div>
        @foreach($verify as $p)
          <div class="sx-row" wire:key="v-{{ $p->id }}">
            <time>Any time</time><span class="sx-dot ver"></span>
            <div class="sx-w"><b><a href="{{ $this->pipelineUrl($p->id) }}">{{ $p->shop }}</a></b><div>{{ $p->city }} · @if($p->phone)<span class="tel">{{ $p->phone }}</span>@else no phone yet @endif</div></div>
            <div class="sx-acts">
              <button class="sx-btn" wire:click="verifyResult('{{ $p->id }}', 'open')">Verified</button>
              <button class="sx-btn q" wire:click="verifyResult('{{ $p->id }}', 'closed')">Closed</button>
              @if($p->phone)<button class="sx-btn q" wire:click="verifyResult('{{ $p->id }}', 'wrong')">Wrong number</button>@endif
            </div>
          </div>
        @endforeach
        @if($vTotal > $verify->count())<div class="sx-more">{{ $vTotal - $verify->count() }} more on <a href="{{ \App\Filament\Pages\SalesPipeline::getUrl() }}" style="color:#a78bfa">Prospects</a>.</div>@endif
      </div>
    @endif
  @endif
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
  // the map is wire:ignore'd and redraws from #srd-data once it's visible.
  (function () {
    var map = null, layer = null;
    function draw() {
      var el = document.getElementById('srd-map'); if (!el || !window.L) return;
      if (el.closest('[hidden]')) return;
      var d; try { d = JSON.parse(document.getElementById('srd-data').textContent); } catch (e) { return; }
      if (!map) {
        map = L.map(el).setView([47.66, -117.43], 9);
         var esri = 'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/';
        L.tileLayer(esri + 'World_Dark_Gray_Base/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, maxNativeZoom: 16, attribution: 'Tiles &copy; Esri &mdash; Esri, HERE, Garmin, &copy; OpenStreetMap contributors' }).addTo(map);
        L.tileLayer(esri + 'World_Dark_Gray_Reference/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, maxNativeZoom: 16 }).addTo(map);
      }
      map.invalidateSize();
      if (layer) { layer.remove(); }
      layer = L.layerGroup().addTo(map);
      var pts = [];
      if (d.start) {
        L.circleMarker([d.start.lat, d.start.lng], { radius: 7, color: '#09090b', weight: 1.5, fillColor: '#BEF264', fillOpacity: 1 }).bindTooltip('Start').addTo(layer);
        pts.push([d.start.lat, d.start.lng]);
      }
      (d.stops || []).forEach(function (s, i) {
        var m = L.marker([s.lat, s.lng], { icon: L.divIcon({ className: '', html: '<div style="width:24px;height:24px;border-radius:50%;background:' + (s.done ? '#34d399' : '#8b5cf6') + ';color:#fff;font:700 11px Inter,sans-serif;display:flex;align-items:center;justify-content:center;border:2px solid #09090b">' + (s.done ? '✓' : (i + 1)) + '</div>', iconSize: [24, 24], iconAnchor: [12, 12] }) });
        m.bindTooltip(s.shop, { direction: 'top' }); m.addTo(layer); pts.push([s.lat, s.lng]);
      });
      if (pts.length > 1) { L.polyline(pts, { color: '#8b5cf6', weight: 2.5, dashArray: '6 4' }).addTo(layer); }
      if (pts.length) { map.fitBounds(pts, { padding: [28, 28], maxZoom: 13 }); }
    }
    function later() { setTimeout(draw, 40); }
    document.addEventListener('DOMContentLoaded', draw);
    document.addEventListener('livewire:initialized', function () {
      draw();
      if (window.Livewire && Livewire.hook) Livewire.hook('morph.updated', later);
    });
    window.addEventListener('route-updated', later);
    if (document.readyState !== 'loading') { later(); }
  })();
</script>
</x-filament-panels::page>
