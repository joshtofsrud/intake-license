{{-- MARKER-SALES-ROUTE --}}
{{-- MARKER-SALES-TODAY — Route day became Today: agenda, verify list and the drive, one page. --}}
@php
    $agenda  = $this->agenda();
    $verify  = $this->verifyList();
    $vTotal  = $this->verifyTotal();
    $tally   = $this->tally();
    $stops   = $this->stops();
    $stopNo  = $stops->pluck('id')->flip()->map(fn ($i) => $i + 1);
    $unplaced = $this->unplaced();
    $pts = $stops->map(fn ($p) => ['lat' => (float) $p->lat, 'lng' => (float) $p->lng, 'shop' => $p->shop, 'done' => in_array($p->id, $done, true)])->values()->all();
    $todayStart = now()->startOfDay();
@endphp

<x-filament-panels::page>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
  .sx-root { --sx-line:rgba(255,255,255,.075); --sx-line-2:rgba(255,255,255,.14); --sx-dim:#a3a3ab; --sx-faint:#74747d;
    --sx-violet:#8b5cf6; --sx-vsoft:rgba(139,92,246,.17); --sx-lime:#BEF264; --sx-amber:#f5b942; --sx-red:#f47c7c; font-size:14px; }
  .sx-in { background:rgba(255,255,255,.04); border:1px solid var(--sx-line-2); border-radius:7px; padding:6px 10px; font-size:13px; color:inherit; }
  .sx-in option { background:#26272c; }
  .sx-btn { border:1px solid var(--sx-line-2); background:none; border-radius:7px; padding:5px 11px; font-weight:500; font-size:12.5px; cursor:pointer; white-space:nowrap; color:inherit; text-decoration:none; display:inline-block; }
  .sx-btn.p { background:var(--sx-violet); border-color:var(--sx-violet); color:#fff; }
  .sx-btn.q { border-color:transparent; color:var(--sx-dim); }
  .sx-btn.q:hover { color:#fff; border-color:var(--sx-line-2); }
  .sx-tog { display:inline-flex; align-items:center; gap:6px; font-size:13px; color:var(--sx-dim); cursor:pointer; }
  .sx-tog input { accent-color:var(--sx-violet); }
  .sx-top { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
  .sx-tally { display:flex; gap:30px; flex-wrap:wrap; margin-top:4px; font-size:13px; color:var(--sx-dim); }
  .sx-tally b { display:block; font-size:22px; font-weight:650; color:#fff; letter-spacing:-.02em; font-variant-numeric:tabular-nums; }
  .sx-tally b.amber { color:var(--sx-amber); } .sx-tally b.lime { color:var(--sx-lime); }
  .sx-lede { color:var(--sx-faint); font-size:13px; line-height:1.55; margin:12px 0 0; max-width:84ch; }
  .sx-grid { display:grid; grid-template-columns:minmax(380px,1fr) minmax(380px,1.05fr); gap:44px; margin-top:22px; }
  @media (max-width:1100px) { .sx-grid { grid-template-columns:1fr; } }
  .sx-h { display:flex; justify-content:space-between; align-items:baseline; font-weight:600; font-size:15px; margin:0 0 4px; }
  .sx-h span { font-size:12.5px; color:var(--sx-faint); font-weight:400; }
  .sx-ag { list-style:none; margin:0 0 28px; padding:0; }
  .sx-ag li { display:grid; grid-template-columns:64px 18px 1fr; gap:0 10px; padding:12px 0; border-top:1px solid var(--sx-line); }
  .sx-ag time { font-size:13px; color:var(--sx-dim); font-variant-numeric:tabular-nums; }
  .sx-ag time.late { color:var(--sx-amber); }
  .sx-ag .dot { width:18px; height:18px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:10.5px; font-weight:700; }
  .sx-ag .dot.stop { background:var(--sx-violet); color:#fff; }
  .sx-ag .dot.call { border:1.5px solid var(--sx-dim); }
  .sx-ag .dot.verify { border:1.5px dashed var(--sx-faint); }
  .sx-ag .k { font-size:12px; color:var(--sx-faint); }
  .sx-ag .k b { color:#ececee; font-weight:500; }
  .sx-ag .s { font-weight:550; font-size:14.5px; margin:1px 0; }
  .sx-ag .s a { color:inherit; text-decoration:none; }
  .sx-ag .w { font-size:13px; color:var(--sx-dim); }
  .sx-ag .acts { display:flex; gap:6px; margin-top:8px; flex-wrap:wrap; }
  .sx-ag li.done { opacity:.45; }
  .sx-empty { border-top:1px solid var(--sx-line); padding:14px 0; color:var(--sx-dim); font-size:13.5px; line-height:1.55; }
  .sx-mapcol { position:sticky; top:20px; align-self:start; }
  .srd-map { height:440px; border-radius:10px; overflow:hidden; background:#2a2b30; }
  .leaflet-container { font:inherit; }
  .sx-rb { display:flex; align-items:center; gap:10px; flex-wrap:wrap; padding:12px 0; border-bottom:1px solid var(--sx-line); font-size:13px; color:var(--sx-dim); }
  .sx-rb b { color:#fff; }
  .sx-rc { display:flex; flex-wrap:wrap; gap:10px 14px; align-items:center; padding:12px 0; border-bottom:1px solid var(--sx-line); }
  .sx-mini { width:56px; }
  .sx-un { font-size:12.5px; color:var(--sx-faint); padding:12px 0; line-height:1.6; }
</style>

<div class="sx-root">
  <div class="sx-top">
    <select class="sx-in" wire:model.live="repId" aria-label="Whose follow-ups">
      <option value="">Everyone's follow-ups</option><option value="none">House (no rep)</option>
      @foreach($this->reps() as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach
    </select>
    <select class="sx-in" wire:model.live="industryId" aria-label="Industry">
      <option value="">All industries</option>
      @foreach($this->industries() as $ind)<option value="{{ $ind->id }}">{{ $ind->name }}</option>@endforeach
    </select>
    <span style="margin-left:auto;color:var(--sx-faint);font-size:13px">{{ now()->format('l, F j') }}</span>
  </div>

  <div class="sx-tally" style="margin-top:16px">
    <div><b>{{ $tally['todo'] }}</b>follow-ups today</div>
    <div><b class="{{ $tally['overdue'] ? 'amber' : '' }}">{{ $tally['overdue'] }}</b>overdue</div>
    <div><b>{{ $vTotal }}</b>to verify</div>
    <div><b>{{ $stops->count() }}</b>stops{{ $stops->count() ? ', about ' . $this->totalMiles() . ' mi' : '' }}</div>
    <div><b class="lime">${{ number_format($tally['trials']) }}</b>a month in trials, quoted</div>
  </div>

  <p class="sx-lede">
    Follow-ups due today and overdue, oldest first. Done clears a follow-up and adds it to the shop's timeline; it doesn't set the next one, so open the shop for that.
    The route orders shops that have coordinates into a drive from your start point, nearest next, and the miles are an estimate. Shops without coordinates are listed under the map.
  </p>

  <div class="sx-grid">
    <div>
      <div class="sx-h">Follow-ups <span>Overdue first</span></div>
      @if($agenda->isEmpty())
        <div class="sx-empty" style="margin-bottom:28px">Nothing is due. Follow-ups appear here when a shop has a next action date; set one from the shop's timeline on Prospects.</div>
      @else
        <ol class="sx-ag">
          @foreach($agenda as $p)
            @php $late = $p->next_action_on->lt($todayStart); $n = $stopNo[$p->id] ?? null; $isDone = in_array($p->id, $done, true); @endphp
            <li class="{{ $isDone ? 'done' : '' }}" wire:key="a-{{ $p->id }}">
              <time class="{{ $late ? 'late' : '' }}">{{ $late ? $p->next_action_on->format('M j') : 'Today' }}</time>
              @if($n)<span class="dot stop">{{ $n }}</span>@else<span class="dot call"></span>@endif
              <div>
                <div class="k">{{ $n ? 'Visit, stop ' . $n : 'Follow-up' }}@if($p->phone), <b>{{ $p->phone }}</b>@endif</div>
                <div class="s"><a href="{{ $this->pipelineUrl($p->id) }}">{{ $p->shop }}</a></div>
                <div class="w">{{ $p->next_action ?: 'Follow up' }}, {{ $p->city }}{{ $p->state ? ', ' . $p->state : '' }}{{ $p->rep ? ' · ' . $p->rep->name : '' }}</div>
                <div class="acts">
                  @if($n)<button class="sx-btn" wire:click="logVisit('{{ $p->id }}')" @disabled($isDone)>Log visit</button>@endif
                  <button class="sx-btn {{ $n ? 'q' : '' }}" wire:click="markDone('{{ $p->id }}')">Done</button>
                  <button class="sx-btn q" wire:click="moveTomorrow('{{ $p->id }}')">Move to tomorrow</button>
                  <a class="sx-btn q" href="{{ $this->pipelineUrl($p->id) }}">Open</a>
                </div>
              </div>
            </li>
          @endforeach
        </ol>
      @endif

      @php $extra = $stops->reject(fn ($s) => $agenda->contains('id', $s->id)); @endphp
      @if($extra->isNotEmpty())
        <div class="sx-h">Also on the route <span>Priority A shops near your start</span></div>
        <ol class="sx-ag">
          @foreach($extra as $p)
            @php $isDone = in_array($p->id, $done, true); @endphp
            <li class="{{ $isDone ? 'done' : '' }}" wire:key="x-{{ $p->id }}">
              <time>Any time</time><span class="dot stop">{{ $stopNo[$p->id] }}</span>
              <div>
                <div class="k">Visit, stop {{ $stopNo[$p->id] }}@if($p->phone), <b>{{ $p->phone }}</b>@endif</div>
                <div class="s"><a href="{{ $this->pipelineUrl($p->id) }}">{{ $p->shop }}</a></div>
                <div class="w">{{ $p->city }}{{ $p->state ? ', ' . $p->state : '' }}</div>
                <div class="acts"><button class="sx-btn" wire:click="logVisit('{{ $p->id }}')" @disabled($isDone)>Log visit</button><a class="sx-btn q" href="{{ $this->pipelineUrl($p->id) }}">Open</a></div>
              </div>
            </li>
          @endforeach
        </ol>
      @endif

      <div class="sx-h">Needs verifying <span>{{ $vTotal > $verify->count() ? 'Top ' . $verify->count() . ' of ' . $vTotal . ' by lead score' : 'Nobody has confirmed these yet' }}</span></div>
      @if($verify->isEmpty())
        <div class="sx-empty">Every open shop is verified.</div>
      @else
        <ol class="sx-ag">
          @foreach($verify as $p)
            <li wire:key="v-{{ $p->id }}">
              <time>Any time</time><span class="dot verify"></span>
              <div>
                <div class="k">Verify{{ $p->phone ? ', ' : '' }}@if($p->phone)<b>{{ $p->phone }}</b>@else, no phone yet @endif</div>
                <div class="s"><a href="{{ $this->pipelineUrl($p->id) }}">{{ $p->shop }}</a></div>
                <div class="w">{{ $p->city }}{{ $p->state ? ', ' . $p->state : '' }}</div>
                <div class="acts">
                  <button class="sx-btn" wire:click="verifyResult('{{ $p->id }}', 'open')">Open and verified</button>
                  <button class="sx-btn q" wire:click="verifyResult('{{ $p->id }}', 'closed')">Closed</button>
                  @if($p->phone)<button class="sx-btn q" wire:click="verifyResult('{{ $p->id }}', 'wrong')">Wrong number</button>@endif
                </div>
              </div>
            </li>
          @endforeach
        </ol>
      @endif
    </div>

    <div class="sx-mapcol">
      <div wire:ignore class="srd-map" id="srd-map"></div>
      {{-- MARKER-SALES-JSONFIX — not @json: that directive splits on commas --}}
      <script type="application/json" id="srd-data">{!! json_encode(['start' => $startLat !== null ? ['lat' => $startLat, 'lng' => $startLng, 'label' => $startLabel] : null, 'stops' => $pts], JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>

      <div class="sx-rb">
        @if($stops->count())<span><b>{{ $stops->count() }} stops</b>, about {{ $this->totalMiles() }} miles</span>@else<span>No route built yet.</span>@endif
        <span style="margin-left:auto;display:flex;gap:6px">
          @if($this->mapsUrl())<a class="sx-btn" href="{{ $this->mapsUrl() }}" target="_blank" rel="noopener">Open in Google Maps</a>@endif
          <button class="sx-btn p" wire:click="build" wire:loading.attr="disabled" @disabled($startLat === null)>{{ $stops->count() ? 'Rebuild route' : 'Build route' }}</button>
        </span>
      </div>
      <div class="sx-rb" style="border-bottom:0;padding-bottom:4px">
        <span>Start from</span>
        <input type="text" class="sx-in" style="flex:1;min-width:200px" wire:model="startLabel" wire:keydown.enter="setStart" placeholder="City or address">
        <button class="sx-btn" wire:click="setStart" wire:loading.attr="disabled">Save</button>
      </div>
      @error('startLabel')<div style="color:var(--sx-red);font-size:12px">Enter a city or address for the start point.</div>@enderror
      <div style="font-size:12px;color:var(--sx-faint);padding-bottom:10px;border-bottom:1px solid var(--sx-line)">
        {{ $startLat !== null ? 'Saved. Used every day until you change it.' : 'No start point saved yet. Saving uses one Places lookup.' }}
      </div>
      <div class="sx-rc">
        <label class="sx-tog"><input type="checkbox" wire:model.live="includeOverdue"> Include overdue</label>
        <label class="sx-tog"><input type="checkbox" wire:model.live="includeNearbyA"> Priority A within <input type="number" min="1" max="100" class="sx-in sx-mini" wire:model.live.debounce.500ms="nearbyMiles"> mi</label>
        <label class="sx-tog">Max stops <input type="number" min="1" max="{{ \App\Filament\Pages\SalesRouteDay::MAX_STOPS }}" class="sx-in sx-mini" wire:model.live.debounce.500ms="maxStops"></label>
      </div>
      @if($unplaced->count())
        <div class="sx-un">
          {{ $unplaced->count() }} {{ $unplaced->count() === 1 ? 'shop is' : 'shops are' }} due but {{ $unplaced->count() === 1 ? 'has' : 'have' }} no coordinates, so {{ $unplaced->count() === 1 ? 'it isn\'t' : 'they aren\'t' }} on the map:
          @foreach($unplaced as $u)
            <span style="white-space:nowrap">{{ $u->shop }} <button class="sx-btn q" style="padding:1px 6px" wire:click="placeOne('{{ $u->id }}')" title="One Places lookup">Place</button></span>{{ ! $loop->last ? ',' : '' }}
          @endforeach
        </div>
      @endif
    </div>
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
        /* MARKER-ESRI-DARK-TILES */ var esri = 'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/';
        L.tileLayer(esri + 'World_Dark_Gray_Base/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, maxNativeZoom: 16, attribution: 'Tiles &copy; Esri &mdash; Esri, HERE, Garmin, &copy; OpenStreetMap contributors' }).addTo(map);
        L.tileLayer(esri + 'World_Dark_Gray_Reference/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, maxNativeZoom: 16 }).addTo(map);
      }
      if (layer) { layer.remove(); }
      layer = L.layerGroup().addTo(map);
      var pts = [];
      if (d.start) {
        L.circleMarker([d.start.lat, d.start.lng], { radius: 7, color: '#1f2024', weight: 1.5, fillColor: '#BEF264', fillOpacity: 1 }).bindTooltip('Start').addTo(layer);
        pts.push([d.start.lat, d.start.lng]);
      }
      (d.stops || []).forEach(function (s, i) {
        var m = L.marker([s.lat, s.lng], { icon: L.divIcon({ className: '', html: '<div style="width:24px;height:24px;border-radius:50%;background:' + (s.done ? '#34d399' : '#8b5cf6') + ';color:#fff;font:700 11px Inter,sans-serif;display:flex;align-items:center;justify-content:center;border:2px solid #1f2024">' + (s.done ? '✓' : (i + 1)) + '</div>', iconSize: [24, 24], iconAnchor: [12, 12] }) });
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
