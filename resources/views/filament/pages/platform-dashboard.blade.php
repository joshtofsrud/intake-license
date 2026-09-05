{{-- MARKER-DASH-REFACTOR — master admin dashboard in the Marketing traffic page's vocabulary.
     .mt-* rules are copied from marketing-traffic.blade.php so the two pages read as one. --}}
<x-filament-panels::page>

<style>
  .pd{ --pd-ok:#7FD98F; --pd-warn:#F0C46A; --pd-bad:#F08A8A; --pd-info:#7DB8E8; }
  html:not(.dark) .pd{ --pd-ok:#16A34A; --pd-warn:#D97706; --pd-bad:#DC2626; --pd-info:#0284C7; }

  /* traffic page vocabulary (verbatim) */
  .mt-bar{display:flex;gap:6px;margin-bottom:18px;flex-wrap:wrap}
  .mt-bar a,.mt-bar button{height:100%;display:inline-flex;align-items:center;justify-content:center;
    padding:0 14px;border-radius:99px;font-size:12.5px;text-decoration:none;min-height:30px;cursor:pointer;
    background:rgba(127,127,127,.12);color:inherit;opacity:.6;border:0;font:inherit}
  .mt-bar a.on,.mt-bar button.on{opacity:1;font-weight:600;box-shadow:inset 0 0 0 1px currentColor}
  .mt-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:22px}
  .mt-gtile{padding:14px 16px;border-radius:12px;background:rgba(127,127,127,.08)}
  .mt-tile-k{font-size:11px;opacity:.55}
  .mt-tile-v{font-size:24px;font-weight:700;margin-top:2px}
  .mt-tile-d{font-size:11.5px;margin-top:2px;opacity:.6}
  .mt-legend{font-size:11px;opacity:.42;margin-top:2px}
  .mt-two-up{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:20px}
  @media(max-width:900px){.mt-two-up{grid-template-columns:1fr}}
  .mt-card{border:1px solid rgba(127,127,127,.22);border-radius:12px;padding:14px 16px}
  .mt-bar-row{position:relative;display:flex;align-items:center;gap:10px;padding:7px 8px;font-size:13px;color:inherit;text-decoration:none}
  .mt-fill{position:absolute;left:0;top:2px;bottom:2px;background:rgba(139,124,246,.16);border-radius:6px}
  .mt-bar-label{position:relative;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
  .mt-bar-n{position:relative;margin-left:auto;opacity:.6;font-variant-numeric:tabular-nums;white-space:nowrap}
  .mt-note{border-radius:10px;padding:11px 14px;font-size:12.5px;line-height:1.55;margin-bottom:14px}
  .mt-note--warn{background:rgba(240,196,106,.08);border:1px solid rgba(240,196,106,.3)}
  .mt-headline{display:grid;grid-template-columns:210px 1fr;border:1px solid rgba(127,127,127,.22);border-radius:12px;overflow:hidden}
  @media(max-width:900px){.mt-headline{grid-template-columns:1fr}}
  .mt-metrics{border-right:1px solid rgba(127,127,127,.22);padding:4px 0}
  @media(max-width:900px){.mt-metrics{border-right:0;border-bottom:1px solid rgba(127,127,127,.22);display:grid;grid-template-columns:1fr 1fr}}
  .mt-metric{display:block;width:100%;text-align:left;background:none;border:0;border-left:2px solid transparent;padding:12px 16px;cursor:pointer;font:inherit;color:inherit}
  .mt-metric.is-on{background:rgba(139,124,246,.08);border-left-color:rgb(var(--primary-500))}
  .mt-metric .k{display:block;font-size:11.5px;opacity:.55}
  .mt-metric .v{display:block;font-size:22px;font-weight:700;letter-spacing:-.02em;line-height:1.15}
  .mt-metric .d{display:block;font-size:11.5px;opacity:.6}
  .mt-metric .d.up{color:var(--pd-ok);opacity:1}.mt-metric .d.down{color:var(--pd-bad);opacity:1}
  .mt-chartwrap{padding:14px 16px 8px}
  .mt-axis{display:flex;justify-content:space-between;font-size:11px;opacity:.45;margin-top:4px}
  .mt-tiles{display:flex;align-items:stretch;gap:0;flex-wrap:wrap}
  .mt-tile{flex:1 1 0;min-width:104px;padding:14px 12px;border-radius:12px;
    background:rgba(139,124,246,.10);border:1px solid rgba(139,124,246,.22);text-align:center}
  .mt-tile.is-zero{background:rgba(127,127,127,.06);border-color:rgba(127,127,127,.20)}
  .mt-tile .n{font-size:24px;font-weight:700;letter-spacing:-.02em;line-height:1.1;font-variant-numeric:tabular-nums}
  .mt-tile .l{font-size:12px;margin-top:4px;opacity:.75;line-height:1.35}
  .mt-tile .p{font-size:11px;margin-top:3px;opacity:.45}
  .mt-tile.is-zero .n{opacity:.45}
  .mt-gap{display:flex;flex-direction:column;align-items:center;justify-content:center;min-width:34px;font-size:11px;opacity:.6}
  .mt-arrow{font-size:13px;opacity:.3}

  /* dashboard-only, same vocabulary */
  .pd-head{display:flex;align-items:center;gap:14px;margin-bottom:16px;flex-wrap:wrap}
  .pd-head .gen{font-size:12px;opacity:.5;margin-left:auto}
  .st{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;opacity:.9}
  .st i{width:8px;height:8px;border-radius:50%;display:inline-block;background:var(--pd-ok)}
  .st.ok i{box-shadow:0 0 0 3px rgba(127,217,143,.18)} .st.warn i{background:var(--pd-warn)} .st.bad i{background:var(--pd-bad)}
  .card-h{display:flex;align-items:baseline;justify-content:space-between;margin-bottom:8px;gap:10px}
  .card-h .t{font-size:13px;font-weight:600}
  .card-h .s{font-size:11.5px;opacity:.5}
  .card-h .s a{text-decoration:underline}
  .res{display:flex;align-items:center;gap:10px;margin-top:8px;font-size:12px}
  .res .k{width:64px;opacity:.55;font-size:11.5px;text-transform:capitalize}
  .res .bar{flex:1;height:6px;border-radius:3px;background:rgba(127,127,127,.15);overflow:hidden}
  .res .bar span{display:block;height:100%;border-radius:3px;background:rgba(139,124,246,.7)}
  .res .v{width:120px;text-align:right;opacity:.75;font-variant-numeric:tabular-nums;font-size:11.5px}
  .pill{position:relative;font-size:10.5px;padding:1px 8px;border-radius:99px;border:1px solid rgba(127,127,127,.3);opacity:.7;white-space:nowrap}
  .pill.ok{border-color:rgba(127,217,143,.45);color:var(--pd-ok);opacity:1}
  .pill.warn{border-color:rgba(240,196,106,.5);color:var(--pd-warn);opacity:1}
  .pill.bad{border-color:rgba(240,138,138,.5);color:var(--pd-bad);opacity:1}
  .pill.idle{opacity:.45}
  a.mt-bar-row:hover{background:rgba(127,127,127,.08);border-radius:6px}
  .act{display:flex;gap:12px;padding:7px 0;font-size:12.5px;border-top:1px solid rgba(127,127,127,.12)}
  .act:first-child{border-top:0}
  .act .w{width:54px;opacity:.45;font-size:11.5px;flex:none;font-variant-numeric:tabular-nums}
  .act .who{opacity:.9;white-space:nowrap}
  .act .what{opacity:.6;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .act.bad .what{color:var(--pd-bad);opacity:1}
  .alert500{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px solid rgba(127,127,127,.15);font-size:12px}
  .alert500 input[type=email]{flex:1;min-width:180px;background:rgba(127,127,127,.08);border:1px solid rgba(127,127,127,.3);border-radius:6px;padding:5px 9px;font-size:12px;color:inherit}
  .alert500 button{background:rgb(var(--primary-500));color:#fff;border:0;border-radius:6px;padding:6px 12px;font-size:12px;font-weight:600;cursor:pointer}
  .empty{font-size:12px;opacity:.5;padding:6px 0}
</style>

<div class="pd">

  {{-- page head: system state is one line, not a hero --}}
  <div class="pd-head">
    <span class="st {{ $hero['state'] }}"><i></i>{{ $hero['headline'] }}</span>
    <span class="gen">Generated {{ $generatedAt->format('H:i:s') }} UTC{{ $hero['uptime'] ? ' · uptime ' . $hero['uptime'] : '' }}</span>
  </div>

  {{-- refresh cadence, as the traffic page's pill bar --}}
  <div class="mt-bar" id="pd-refresh">
    <button type="button" data-secs="30">Live · 30s</button>
    <button type="button" data-secs="60">1 min</button>
    <button type="button" data-secs="300">5 min</button>
    <button type="button" data-secs="0">Off</button>
    <button type="button" style="margin-left:auto" onclick="window.location.reload()">Refresh now</button>
  </div>

  {{-- headline: four metrics, one charted --}}
  <div class="mt-headline">
    <div class="mt-metrics">
      @foreach($series['metrics'] as $key => $m)
        <button type="button" class="mt-metric {{ $loop->first ? 'is-on' : '' }}" data-metric="{{ $key }}">
          <span class="k">{{ $m['k'] }}</span>
          <span class="v">{{ $m['v'] }}</span>
          <span class="d {{ $m['tone'] }}">{{ $m['d'] }}</span>
        </button>
      @endforeach
    </div>
    <div class="mt-chartwrap">
      <svg id="pd-chart" viewBox="0 0 640 150" width="100%" height="150" preserveAspectRatio="none">
        <defs><linearGradient id="pd-g" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="rgb(139,124,246)" stop-opacity=".35"/><stop offset="1" stop-color="rgb(139,124,246)" stop-opacity="0"/></linearGradient></defs>
        <path id="pd-area" fill="url(#pd-g)" d=""></path>
        <path id="pd-line" fill="none" stroke="rgb(139,124,246)" stroke-width="2" d=""></path>
        <circle id="pd-dot" r="3.5" fill="rgb(139,124,246)" cx="640" cy="75"></circle>
      </svg>
      <div class="mt-axis">
        <span>{{ \Carbon\Carbon::parse($series['labels'][0])->format('M j') }}</span>
        <span>{{ \Carbon\Carbon::parse($series['labels'][intdiv(count($series['labels']), 2)])->format('M j') }}</span>
        <span>{{ \Carbon\Carbon::parse(end($series['labels']))->format('M j') }}</span>
      </div>
      <div class="mt-legend" id="pd-chart-legend"></div>
    </div>
  </div>

  {{-- server tiles --}}
  <div class="mt-grid" style="margin-top:20px">
    @foreach($hero['tiles'] as $t)
      <div class="mt-gtile" @if($t['state'] === 'warn') style="box-shadow:inset 0 0 0 1px rgba(240,196,106,.35)" @elseif($t['state'] === 'bad') style="box-shadow:inset 0 0 0 1px rgba(240,138,138,.45)" @endif>
        <div class="mt-tile-k">{{ $t['label'] }}</div>
        <div class="mt-tile-v" @if($t['state'] === 'warn') style="color:var(--pd-warn)" @elseif($t['state'] === 'bad') style="color:var(--pd-bad)" @elseif($t['state'] === 'idle') style="opacity:.45" @endif>{{ $t['value'] }}</div>
        <div class="mt-tile-d">{{ $t['meta'] }}</div>
      </div>
    @endforeach
  </div>

  @php $backupRow = collect($health)->firstWhere('name', 'Last backup'); @endphp
  @if($backupRow && $backupRow['state'] !== 'ok')
    <div class="mt-note mt-note--warn">
      <b>Last backup: {{ $backupRow['value'] }}.</b> This tile reads <code>system_health.last_backup</code>, which only the server backup script writes. Until the fragment in <code>tools/</code> is appended to <code>/usr/local/bin/intake-backup.sh</code> it stays amber — an unreported backup, not a failed one.
    </div>
  @endif

  <div class="mt-two-up" style="margin-top:0">
    {{-- operational health --}}
    <div class="mt-card">
      <div class="card-h"><span class="t">Operational health</span><span class="s"><a href="/admin/debug-logs">Full system log →</a></span></div>
      @foreach($health as $row)
        @php $fill = $row['state'] === 'bad' ? 60 : ($row['state'] === 'warn' ? 30 : 0); @endphp
        <a class="mt-bar-row" href="{{ $row['href'] ?? '#' }}">
          @if($fill)<div class="mt-fill" style="width:{{ $fill }}%;{{ $row['state'] === 'warn' ? 'background:rgba(240,196,106,.14)' : 'background:rgba(240,138,138,.16)' }}"></div>@endif
          <span class="mt-bar-label">{{ $row['name'] }}</span>
          <span class="pill {{ $row['state'] }}">{{ ['ok' => 'ok', 'warn' => 'watch', 'bad' => 'action', 'idle' => 'n/a'][$row['state']] ?? $row['state'] }}</span>
          <span class="mt-bar-n">{!! $row['value'] !!}</span>
        </a>
      @endforeach
      {{-- MARKER-JOB-ISSUES — the unresolved errors, not just their count.
           One line per distinct failure; the row above stays the headline. --}}
      @if(!empty($issues))
        <div class="card-h" style="margin-top:14px"><span class="t">Open issues</span><span class="s"><a href="/admin/debug-logs?activeTab=errors">all unresolved →</a></span></div>
        @foreach($issues as $i)
          <a class="mt-bar-row" href="{{ $i['href'] }}" style="align-items:flex-start">
            <div class="mt-fill" style="width:{{ min(60, 12 + $i['n'] * 6) }}%;background:rgba(240,138,138,.14)"></div>
            <span class="mt-bar-label" style="white-space:normal">
              <b>{{ $i['title'] }}</b>
              @if($i['tenant']) <span class="pill" style="margin-left:6px">{{ $i['tenant'] }}</span>@endif
              @if($i['detail'])<br><span style="font-size:11.5px;opacity:.6">{{ $i['detail'] }}</span>@endif
            </span>
            <span class="mt-bar-n">{{ $i['job'] }} · {{ $i['n'] }}× · {{ $i['last'] }} ago</span>
          </a>
        @endforeach
      @endif

      {{-- MARKER-500-ALERT — switch + send-to for 5xx alert emails, behaviour unchanged. --}}
      <div class="alert500">
        <label style="display:flex;gap:8px;align-items:center;cursor:pointer">
          <input type="checkbox" wire:model="alert500Enabled" style="width:14px;height:14px">
          Email me on every 5xx and failed background job
        </label>
        <input type="email" wire:model="alert500Email" placeholder="alerts@intake.works">
        <button type="button" wire:click="saveAlert500">Save</button>
        <span class="mt-legend" style="flex-basis:100%">One email per distinct error or job failure per 15 minutes, carrying its refId. Off or blank = no emails; everything still logs and shows above.</span>
      </div>
    </div>

    {{-- trial funnel + SaaS by tier --}}
    <div class="mt-card">
      <div class="card-h"><span class="t">Trial funnel · last 30 days</span><span class="s">of signups</span></div>
      @php
        $fSteps = array_merge([['label' => 'Signed up', 'count' => $funnel['signups']['total'], 'pct' => null]], $funnel['stages']);
      @endphp
      <div class="mt-tiles">
        @foreach($fSteps as $i => $s)
          @if($i > 0)
            @php $prev = $fSteps[$i-1]['count']; $drop = $prev > 0 ? (int) round((1 - $s['count'] / $prev) * 100) : 0; @endphp
            <div class="mt-gap"><span class="mt-arrow">→</span>@if($prev > 0 && $drop > 0)<span>−{{ $drop }}%</span>@endif</div>
          @endif
          <div class="mt-tile {{ $s['count'] === 0 ? 'is-zero' : '' }}">
            <div class="n">{{ number_format($s['count']) }}</div>
            <div class="l">{{ $s['label'] }}</div>
            <div class="p">{{ $s['pct'] === null ? ' ' : $s['pct'] . '%' }}</div>
          </div>
        @endforeach
      </div>
      <div class="mt-legend" style="margin-top:12px">Accounts created in the window. Percentages are of signups; the gap is the drop from the step before.</div>

      <div class="card-h" style="margin-top:18px"><span class="t">Intake SaaS</span><span class="s"><a href="/admin/tenants">all tenants →</a></span></div>
      @php $tierMax = max(1, collect($saas['byTier'] ?? [])->max('tenants') ?? 1); @endphp
      @forelse(($saas['byTier'] ?? []) as $tier => $b)
        <div class="res">
          <span class="k">{{ $tier }}</span>
          <div class="bar"><span style="width:{{ (int) round($b['tenants'] / $tierMax * 100) }}%"></span></div>
          <span class="v">{{ $b['tenants'] }} · ${{ number_format($b['cents'] / 100) }}</span>
        </div>
      @empty
        <div class="empty">No active tenants yet.</div>
      @endforelse
      <div class="mt-legend" style="margin-top:8px">
        {{ $saas['totalTenants'] }} tenants · ${{ number_format($saas['mrr']) }} MRR after discounts (${{ number_format($saas['mrrList']) }} list) · {{ $saas['giftedCount'] }} gifted · {{ $saas['inTrial'] }} in trial worth ${{ number_format($saas['trialPotential']) }}
        @if($saas['extSent'] > 0) · rental extensions: {{ $saas['extAccepted'] }}/{{ $saas['extSent'] }} accepted, ${{ number_format($saas['extRevenue'] / 100) }} @endif
      </div>
    </div>
  </div>

  <div class="mt-two-up">
    {{-- wordpress plugin --}}
    <div class="mt-card">
      <div class="card-h"><span class="t">WordPress plugin</span><span class="s">licence server</span></div>
      <div class="res"><span class="k">Free</span><div class="bar"><span style="width:{{ $wp['freePct'] }}%"></span></div><span class="v">{{ number_format($wp['free']) }} installs</span></div>
      <div class="res"><span class="k">Premium</span><div class="bar"><span style="width:{{ $wp['premiumPct'] }}%"></span></div><span class="v">{{ number_format($wp['premium']) }} installs</span></div>
      <div class="mt-legend" style="margin-top:8px">{{ number_format($wp['active']) }} of {{ number_format($wp['total']) }} installs reporting · {{ number_format($wp['activeLicenses']) }} active licences.</div>
    </div>

    {{-- tenant attention + recent activity --}}
    <div class="mt-card">
      <div class="card-h"><span class="t">Tenant attention</span><span class="s"><a href="/admin/tenant-domains">all domains →</a></span></div>
      @forelse($domains as $d)
        <a class="mt-bar-row" href="{{ $d['href'] }}">
          <span class="mt-bar-label">{{ $d['hostname'] }}</span>
          <span class="pill {{ $d['state'] }}">{{ $d['label'] }}</span>
          <span class="mt-bar-n">{{ $d['tenant'] }} · {{ $d['age'] }}</span>
        </a>
      @empty
        <div class="empty">No domains need attention.</div>
      @endforelse

      <div class="card-h" style="margin-top:14px"><span class="t">Recent activity</span><span class="s">across all shops</span></div>
      @forelse($recentAlerts as $a)
        <div class="act {{ $a['tone'] }}"><span class="w">{{ $a['time'] }}</span><span class="who">{{ $a['who'] }}</span><span class="what">{{ $a['what'] }}</span></div>
      @empty
        <div class="empty">Nothing yet — shop alerts land here as they happen.</div>
      @endforelse

      @if(!empty($activity))
        <div class="card-h" style="margin-top:14px"><span class="t">Platform log</span><span class="s"><a href="/admin/debug-logs">more →</a></span></div>
        @foreach(array_slice($activity, 0, 5) as $e)
          <div class="act {{ $e['tone'] === 'bad' ? 'bad' : '' }}"><span class="w">{{ $e['time'] }}</span><span class="what">{{ $e['text'] }}</span></div>
        @endforeach
      @endif
    </div>
  </div>

</div>

<script>
  (function () {
    // ---- headline chart: pick a metric, draw its 30-day series ----
    var METRICS = @json(collect($series['metrics'])->map(fn ($m) => ['series' => $m['series'], 'mode' => $m['mode'], 'legend' => $m['legend']]));
    var W = 640, H = 150, PAD = 6;
    function draw(key) {
      var m = METRICS[key]; if (!m) return;
      var s = m.series.slice();
      if (m.mode === 'cumulative') { var acc = 0; s = s.map(function (v) { acc += v; return acc; }); }
      var max = Math.max.apply(null, s.concat([1]));
      var n = s.length, pts = [];
      for (var i = 0; i < n; i++) {
        var x = n > 1 ? (i / (n - 1)) * W : W;
        var y = H - PAD - (s[i] / max) * (H - PAD * 2);
        pts.push([x, y]);
      }
      var d = pts.map(function (p, i) { return (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1); }).join(' ');
      document.getElementById('pd-line').setAttribute('d', d);
      document.getElementById('pd-area').setAttribute('d', d + ' L' + W + ' ' + H + ' L0 ' + H + ' Z');
      var last = pts[pts.length - 1];
      var dot = document.getElementById('pd-dot');
      dot.setAttribute('cx', last[0]); dot.setAttribute('cy', last[1]);
      document.getElementById('pd-chart-legend').textContent = m.legend;
    }
    var btns = document.querySelectorAll('.mt-metric[data-metric]');
    btns.forEach(function (b) {
      b.addEventListener('click', function () {
        btns.forEach(function (x) { x.classList.toggle('is-on', x === b); });
        draw(b.dataset.metric);
      });
    });
    if (btns.length) draw(btns[0].dataset.metric);

    // ---- refresh cadence pills, persisted like the old select ----
    var KEY = 'pd-refresh-secs';
    var pills = document.querySelectorAll('#pd-refresh button[data-secs]');
    var timer = null;
    function apply(secs) {
      if (timer) { clearTimeout(timer); timer = null; }
      pills.forEach(function (p) { p.classList.toggle('on', parseInt(p.dataset.secs, 10) === secs); });
      if (secs > 0) { timer = setTimeout(function () { window.location.reload(); }, secs * 1000); }
    }
    pills.forEach(function (p) {
      p.addEventListener('click', function () {
        var secs = parseInt(p.dataset.secs, 10);
        localStorage.setItem(KEY, String(secs));
        apply(secs);
      });
    });
    apply(parseInt(localStorage.getItem(KEY) || '0', 10));
  })();
</script>

</x-filament-panels::page>
