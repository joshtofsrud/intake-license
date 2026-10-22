{{-- MARKER-COVERAGE: Sales > Coverage. Same look as Prospects (sx- styles); no cards. --}}
@php
    $d      = $this->data();
    $inds   = $this->industries();
    $allN   = array_sum(array_column($inds, 1));
    $stages = \App\Filament\Pages\SalesCoverage::STAGE_LABELS;
    $colors = \App\Filament\Pages\SalesCoverage::STAGE_COLORS;
    $fr     = $d['focusRow'];
    $mapData = [
        'metric' => $metric, 'focus' => $focus, 'values' => $d['values'], 'points' => $d['points'], 'circles' => $d['circles'],
        'colors' => $colors, 'labels' => $stages, 'metricLabel' => \App\Filament\Pages\SalesCoverage::METRICS[$metric],
        'open' => \App\Filament\Pages\SalesPipeline::getUrl() . '?open=',
        'geo' => asset('vendor/us-atlas/states-10m.json'),
    ];
@endphp
<x-filament-panels::page>
<style>
  .sx-root { --sx-line:rgba(255,255,255,.075); --sx-line-2:rgba(255,255,255,.14); --sx-dim:#a3a3ab; --sx-faint:#74747d;
    --sx-violet:#8b5cf6; --sx-vsoft:rgba(139,92,246,.17); --sx-lime:#BEF264; font-size:14px; }
  html:not(.dark) .sx-root { --sx-line:rgba(0,0,0,.08); --sx-line-2:rgba(0,0,0,.14); --sx-dim:#52525b; --sx-faint:#71717a; }
  .sx-inds { display:flex; gap:18px; border-bottom:1px solid var(--sx-line); overflow-x:auto; }
  .sx-inds button { background:none; border:0; border-bottom:2px solid transparent; padding:9px 0; color:var(--sx-dim); cursor:pointer; white-space:nowrap; font-weight:500; }
  .sx-inds button.on { color:inherit; border-bottom-color:var(--sx-violet); }
  .sx-inds button span { color:var(--sx-faint); font-weight:400; margin-left:4px; }
  .sx-bar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; padding:12px 0; border-bottom:1px solid var(--sx-line); }
  .sx-seg { display:inline-flex; flex-wrap:wrap; border:1px solid var(--sx-line-2); border-radius:8px; padding:2px; }
  .sx-seg button { background:none; border:0; padding:5px 12px; border-radius:6px; font-weight:500; font-size:13px; color:var(--sx-dim); cursor:pointer; }
  .sx-seg button.on { background:var(--sx-vsoft); color:inherit; }
  .sx-tally { display:flex; gap:30px; flex-wrap:wrap; margin-top:16px; font-size:13px; color:var(--sx-dim); }
  .sx-tally b { display:block; font-size:22px; font-weight:650; letter-spacing:-.02em; color:var(--cov-strong, #fff); }
  html:not(.dark) .sx-tally b { --cov-strong:#18181b; }
  .sx-lede { color:var(--sx-faint); font-size:13px; line-height:1.55; margin:10px 0 0; max-width:90ch; }
  .cov-wrap { display:grid; grid-template-columns:1fr 280px; gap:24px; margin-top:14px; }
  .cov-wrap.us { grid-template-columns:1fr; }
  @media (max-width:900px) { .cov-wrap { grid-template-columns:1fr; } }
  .cov-mapbox { position:relative; }
  .cov-map svg { width:100%; height:auto; display:block; }
  .cov-map .st { cursor:pointer; transition:opacity .15s; }
  .cov-map .st:hover { opacity:.8; }
  .cov-back { position:absolute; left:0; top:0; z-index:2; background:none; border:1px solid var(--sx-line-2); border-radius:7px; padding:5px 11px; font-size:13px; cursor:pointer; }
  .cov-tip { position:absolute; pointer-events:none; background:#18181b; color:#f4f4f5; border:1px solid rgba(255,255,255,.14); border-radius:7px; padding:6px 9px; font-size:12.5px; display:none; white-space:nowrap; z-index:3; }
  .cov-key { display:flex; align-items:center; gap:8px; font-size:12px; color:var(--sx-faint); margin-top:6px; flex-wrap:wrap; }
  .cov-key i.g { display:block; width:120px; height:8px; border-radius:4px; }
  .cov-key i.d { display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:5px; }
  .cov-side { border-left:1px solid var(--sx-line); padding-left:20px; }
  .cov-side h2 { font-size:20px; margin:0 0 2px; font-weight:650; }
  .cov-row { display:grid; grid-template-columns:96px 1fr 44px; gap:8px; align-items:center; font-size:13px; color:var(--sx-dim); padding:5px 0; }
  .cov-row .b { height:6px; border-radius:3px; background:rgba(127,127,127,.15); overflow:hidden; }
  .cov-row .b i { display:block; height:100%; }
  .cov-row .n { text-align:right; font-variant-numeric:tabular-nums; }
  .cov-side a { display:block; margin-top:12px; color:#a78bfa; font-size:13px; }
  .cov-t { width:100%; border-collapse:collapse; margin-top:28px; font-size:13px; }
  .cov-t th { font-weight:500; color:var(--sx-faint); text-align:right; padding:8px 6px; border-bottom:1px solid var(--sx-line); cursor:pointer; white-space:nowrap; user-select:none; }
  .cov-t th.on { color:inherit; }
  .cov-t th:first-child, .cov-t td:first-child { text-align:left; }
  .cov-t td { padding:8px 6px; border-bottom:1px solid var(--sx-line); text-align:right; font-variant-numeric:tabular-nums; }
  .cov-t tr.cl:hover td { background:rgba(127,127,127,.06); cursor:pointer; }
  .cov-t .z { color:var(--sx-faint); }
  .cov-scroll { overflow-x:auto; }
</style>

<div class="sx-root">
  <div class="sx-inds" role="tablist" aria-label="Industry">
    <button class="{{ ! $inds || ! $this->inds ? 'on' : '' }}" wire:click="toggleInd('')">All<span>{{ number_format($allN) }}</span></button>
    @foreach($inds as $id => [$iname, $in])
      <button class="{{ in_array((string) $id, $this->inds, true) ? 'on' : '' }}" wire:click="toggleInd('{{ $id }}')">{{ $iname }}<span>{{ number_format($in) }}</span></button>
    @endforeach
  </div>

  <div class="sx-bar">
    <span style="font-size:13px;color:var(--sx-dim)">Map shows</span>
    <div class="sx-seg">
      @foreach(\App\Filament\Pages\SalesCoverage::METRICS as $mk => $ml)
        <button type="button" class="{{ $metric === $mk ? 'on' : '' }}" wire:click="setMetric('{{ $mk }}')">{{ $ml }}</button>
      @endforeach
    </div>
    <span style="font-size:12px;color:var(--sx-faint)">Tap more than one industry to add them together.</span>
  </div>

  <div class="sx-tally">
    <div><b>{{ number_format($d['tot']['shops']) }}</b>shops</div>
    <div><b>{{ number_format($d['tot']['contacted']) }}</b>contacted</div>
    <div><b>{{ number_format($d['tot']['demo']) }}</b>reached a demo</div>
    <div><b>{{ number_format($d['tot']['trial']) }}</b>reached a trial</div>
    <div><b>{{ number_format($d['tot']['won']) }}</b>won</div>
  </div>
  <p class="sx-lede">Shading is the count in each state for the industries picked; darker means more. Contacted, Demo and Trial count shops that reached that stage or went further. Towns searched is the share of a state's towns (over 1,000 people) inside a Find shops search circle for those industries; a search that came back full doesn't count. Lost shops are left out. Shops without a map location are counted in the table but aren't dots.</p>

  <div class="cov-wrap {{ $focus ? '' : 'us' }}">
    <div class="cov-mapbox">
      @if($focus)<button type="button" class="cov-back" wire:click="clearFocus">‹ United States</button>@endif
      <div wire:ignore class="cov-map" id="cov-map"></div>
      <div class="cov-tip" id="cov-tip"></div>
      @if($focus)
        <div class="cov-key">@foreach($stages as $sk => $sl)<span><i class="d" style="background:{{ $colors[$sk] }}"></i>{{ $sl }}</span>@endforeach<span>· tap a dot to open the shop</span></div>
      @else
        <div class="cov-key"><span>0</span><i class="g" id="cov-grad"></i><span id="cov-max"></span></div>
      @endif
    </div>
    @if($focus)
      <div class="cov-side">
        <h2>{{ \App\Services\Sales\TownCoverage::STATES[$focus] ?? $focus }}</h2>
        <div style="font-size:12.5px;color:var(--sx-faint);margin-bottom:12px">{{ $this->inds ? implode(', ', array_map(fn ($i) => $inds[$i][0] ?? '', $this->inds)) : 'All industries' }} · where shops are now</div>
        @php $fmax = max(1, $fr['shops'] ?? 0); @endphp
        @foreach($stages as $sk => $sl)
          <div class="cov-row"><span>{{ $sl }}</span><span class="b"><i style="width:{{ $fr ? round($fr[$sk] * 100 / $fmax) : 0 }}%;background:{{ $colors[$sk] }}"></i></span><span class="n">{{ number_format($fr[$sk] ?? 0) }}</span></div>
        @endforeach
        <div class="cov-row" style="margin-top:8px"><span>Towns searched</span><span class="b"><i style="width:{{ $fr['towns'] ?? 0 }}%;background:#8b5cf6"></i></span><span class="n">{{ $fr['towns'] ?? 0 }}%</span></div>
        @if(count($d['points']) >= 4000)<div style="font-size:12px;color:var(--sx-faint);margin-top:6px">Showing the 4,000 furthest along.</div>@endif
        <a href="{{ $this->prospectsUrl($focus) }}">Open {{ \App\Services\Sales\TownCoverage::STATES[$focus] ?? $focus }} in Prospects ›</a>
        <a href="{{ $this->findUrl($focus) }}">Find more shops in {{ \App\Services\Sales\TownCoverage::STATES[$focus] ?? $focus }} ›</a>
      </div>
    @endif
  </div>

  <script type="application/json" id="cov-data">{!! json_encode($mapData, JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>

  <div class="cov-scroll">
  <table class="cov-t">
    <thead><tr>
      @php $cols = ['name' => 'State', 'shops' => 'Shops'] + $stages + ['towns' => 'Towns searched']; @endphp
      @foreach($cols as $ck => $cl)
        <th class="{{ $sort === $ck ? 'on' : '' }}" wire:click="sortBy('{{ $ck }}')">{{ $cl }}{{ $sort === $ck ? ($dir === 'desc' ? ' ↓' : ' ↑') : '' }}</th>
      @endforeach
    </tr></thead>
    <tbody>
      @forelse($d['rows'] as $r)
        <tr class="{{ $r['st'] !== '??' ? 'cl' : '' }}" wire:key="cov-{{ $r['st'] }}" @if($r['st'] !== '??') wire:click="focusState('{{ $r['st'] }}')" @endif>
          @foreach($cols as $ck => $cl)
            @if($ck === 'name')<td>{{ $r['name'] }}</td>
            @elseif($ck === 'towns')<td>{!! $r['towns'] === null ? '<span class="z">-</span>' : ($r['towns'] ? $r['towns'] . '%' : '<span class="z">0%</span>') !!}</td>
            @else<td>{!! $r[$ck] ? number_format($r[$ck]) : '<span class="z">0</span>' !!}</td>@endif
          @endforeach
        </tr>
      @empty
        <tr><td colspan="10" style="color:var(--sx-faint)">No shops for these industries yet. Find shops or load a list to fill the map.</td></tr>
      @endforelse
    </tbody>
  </table>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/d3@7.9.0/dist/d3.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/topojson-client@3.1.0/dist/topojson-client.min.js"></script>
<script>
  // the map is wire:ignore'd; it redraws from #cov-data whenever the component says so
  (function () {
    var FIPS = {"01":"AL","02":"AK","04":"AZ","05":"AR","06":"CA","08":"CO","09":"CT","10":"DE","11":"DC","12":"FL","13":"GA","15":"HI","16":"ID","17":"IL","18":"IN","19":"IA","20":"KS","21":"KY","22":"LA","23":"ME","24":"MD","25":"MA","26":"MI","27":"MN","28":"MS","29":"MO","30":"MT","31":"NE","32":"NV","33":"NH","34":"NJ","35":"NM","36":"NY","37":"NC","38":"ND","39":"OH","40":"OK","41":"OR","42":"PA","44":"RI","45":"SC","46":"SD","47":"TN","48":"TX","49":"UT","50":"VT","51":"VA","53":"WA","54":"WV","55":"WI","56":"WY"};
    var feats = null, svg = null, g = null, dots = null, circ = null, proj = null, path = null, lastFocus = null, loading = false;
    function wire() { var el = document.getElementById('cov-map'); var c = el && el.closest('[wire\\:id]'); return c && window.Livewire ? Livewire.find(c.getAttribute('wire:id')) : null; }
    function data() { try { return JSON.parse(document.getElementById('cov-data').textContent); } catch (e) { return null; } }
    function dark() { return document.documentElement.classList.contains('dark'); }
    function setup(d) {
      var el = document.getElementById('cov-map'); if (!el || !window.d3 || !window.topojson) return;
      if (feats) return draw();
      if (loading) return; loading = true;
      d3.json(d.geo).then(function (us) {
        feats = topojson.feature(us, us.objects.states).features.filter(function (f) { return FIPS[f.id]; });
        feats.forEach(function (f) { f.st = FIPS[f.id]; });
        proj = d3.geoAlbersUsa().scale(1300).translate([487.5, 305]); path = d3.geoPath(proj);
        svg = d3.select(el).append('svg').attr('viewBox', '0 0 975 610');
        g = svg.append('g'); circ = svg.append('g'); dots = svg.append('g');
        var tip = document.getElementById('cov-tip');
        g.selectAll('path').data(feats).join('path').attr('class', 'st').attr('d', path)
          .on('mousemove', function (e, f) { var dd = data(); if (!dd) return; var r = el.getBoundingClientRect();
            tip.style.display = 'block'; tip.style.left = (e.clientX - r.left + 12) + 'px'; tip.style.top = (e.clientY - r.top + 12) + 'px';
            var v = dd.values[f.st] || 0; tip.textContent = f.properties.name + ' · ' + v.toLocaleString() + (dd.metric === 'towns' ? '% of towns searched' : ' ' + dd.metricLabel.toLowerCase()); })
          .on('mouseleave', function () { tip.style.display = 'none'; })
          .on('click', function (e, f) { var w = wire(); if (w) w.call('focusState', f.st); });
        draw();
      });
    }
    function draw() {
      var d = data(); if (!d || !feats) return;
      var dk = dark(), vals = d.values, max = 1;
      Object.keys(vals).forEach(function (k) { if (vals[k] > max) max = vals[k]; });
      var hi = d.metric === 'won' ? '#84cc16' : '#8b5cf6', lo = dk ? '#1c1a26' : '#efeafe';
      var col = function (v) { return d3.interpolateRgb(lo, hi)(Math.pow(v / max, .6)); };
      var grad = document.getElementById('cov-grad'); if (grad) grad.style.background = 'linear-gradient(90deg,' + lo + ',' + hi + ')';
      var mx = document.getElementById('cov-max'); if (mx) mx.textContent = max.toLocaleString() + (d.metric === 'towns' ? '%' : '');
      var focusF = d.focus ? feats.find(function (f) { return f.st === d.focus; }) : null;
      g.selectAll('path')
        .attr('fill', function (f) { if (focusF === f) return dk ? '#1a1824' : '#f4f2fb'; var v = vals[f.st] || 0; return v ? col(v) : (dk ? '#141418' : '#f4f4f5'); })
        .attr('stroke', dk ? '#09090b' : '#ffffff')
        .attr('opacity', function (f) { return focusF && f !== focusF ? .3 : 1; });
      var t = '', k = 1;
      if (focusF) {
        var b = path.bounds(focusF), x0 = b[0][0], y0 = b[0][1], x1 = b[1][0], y1 = b[1][1];
        k = Math.min(10, .85 / Math.max((x1 - x0) / 975, (y1 - y0) / 610));
        t = 'translate(' + (487.5 - k * (x0 + x1) / 2) + ',' + (305 - k * (y0 + y1) / 2) + ') scale(' + k + ')';
      }
      var moved = lastFocus !== d.focus; lastFocus = d.focus;
      [g, circ, dots].forEach(function (s) { (moved ? s.transition().duration(550) : s).attr('transform', t); });
      g.selectAll('path').attr('stroke-width', .7 / k);
      circ.selectAll('path').remove();
      (d.circles || []).forEach(function (c) {
        circ.append('path').datum(d3.geoCircle().center([c[1], c[0]]).radius(c[2] / 69.05)())
          .attr('d', path).attr('fill', c[3] ? 'none' : 'rgba(139,92,246,.06)').attr('stroke', c[3] ? '#f59e0b' : 'rgba(139,92,246,.6)')
          .attr('stroke-width', .8 / k).attr('stroke-dasharray', c[3] ? (3 / k) + ' ' + (3 / k) : null);
      });
      var pts = (d.points || []).map(function (p) { var xy = proj([p[5], p[4]]); return xy ? { p: p, x: xy[0], y: xy[1] } : null; }).filter(Boolean).reverse();
      dots.selectAll('circle').data(pts, function (q) { return q.p[0]; }).join('circle')
        .attr('cx', function (q) { return q.x; }).attr('cy', function (q) { return q.y; }).attr('r', 2.8 / k)
        .attr('fill', function (q) { return d.colors[q.p[3]] || '#a1a1aa'; }).attr('stroke', dk ? '#09090b' : '#fff').attr('stroke-width', .6 / k)
        .style('cursor', 'pointer')
        .on('mousemove', function (e, q) { var el = document.getElementById('cov-map'), r = el.getBoundingClientRect(), tip = document.getElementById('cov-tip');
          tip.style.display = 'block'; tip.style.left = (e.clientX - r.left + 12) + 'px'; tip.style.top = (e.clientY - r.top + 12) + 'px';
          tip.textContent = q.p[1] + (q.p[2] ? ' · ' + q.p[2] : '') + ' · ' + (d.labels[q.p[3]] || q.p[3]); })
        .on('mouseleave', function () { document.getElementById('cov-tip').style.display = 'none'; })
        .on('click', function (e, q) { e.stopPropagation(); window.location.href = d.open + encodeURIComponent(q.p[0]); });
    }
    function go() { var d = data(); if (d) setup(d); }
    document.addEventListener('livewire:initialized', go);
    window.addEventListener('coverage-updated', function () { setTimeout(draw, 30); });
    if (document.readyState !== 'loading') setTimeout(go, 0); else document.addEventListener('DOMContentLoaded', go);
  })();
</script>
</x-filament-panels::page>
