{{-- MARKER-PATCH-135 — tile-free master admin dashboard --}}
<x-filament-panels::page>

<style>
  /* This view scopes its own styles. The Filament outer chrome supplies
     the global dark theme; everything inside .pd is self-contained so
     the dashboard renders the same whether Filament's defaults shift
     or not. */

  .pd {
    --pd-bg: var(--gray-950, #0a0a0a);
    --pd-surface: var(--gray-900, #131313);
    --pd-surface-2: var(--gray-800, #1a1a1a);
    --pd-border: rgba(255,255,255,.08);
    --pd-border-strong: rgba(255,255,255,.18);
    --pd-text: #f0f0f0;
    --pd-text-muted: rgba(255,255,255,.62);
    --pd-text-dim: rgba(255,255,255,.42);
    --pd-accent: #BEF264;
    --pd-ok: #86EFAC;
    --pd-warn: #FBBF24;
    --pd-bad: #F87171;
    --pd-info: #7DD3FC;
    --pd-r-md: 6px;
    --pd-r-lg: 10px;
    --pd-font-mono: 'JetBrains Mono', ui-monospace, monospace;

    color: var(--pd-text);
    font-size: 14px;
    line-height: 1.55;
  }
  .pd a { color: inherit; text-decoration: none; }
  .pd a:hover { color: var(--pd-text); }

  /* Hero */
  .pd-hero { display:grid; grid-template-columns:auto 1fr auto; gap:24px; align-items:center;
    padding:18px 22px; background:var(--pd-surface); border:1px solid var(--pd-border);
    border-radius:var(--pd-r-lg); margin-bottom:8px; }
  .pd-hero-state { display:flex; align-items:center; gap:12px; }
  .pd-hero-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
  .pd-hero-state.ok .pd-hero-dot   { background:var(--pd-ok); box-shadow:0 0 12px var(--pd-ok); }
  .pd-hero-state.warn .pd-hero-dot { background:var(--pd-warn); box-shadow:0 0 12px var(--pd-warn); }
  .pd-hero-state.bad .pd-hero-dot  { background:var(--pd-bad); box-shadow:0 0 12px var(--pd-bad); }
  .pd-hero-label { font-size:11px; text-transform:uppercase; letter-spacing:.08em; color:var(--pd-text-dim); margin-bottom:2px; }
  .pd-hero-headline { font-size:15px; font-weight:500; }
  .pd-hero-pulses { display:flex; gap:14px; }
  .pd-pulse { text-align:center; padding:4px 10px; border-radius:var(--pd-r-md); }
  .pd-pulse-lbl { font-size:9.5px; text-transform:uppercase; letter-spacing:.06em; color:var(--pd-text-dim); }
  .pd-pulse-bar { width:62px; height:4px; background:rgba(255,255,255,.06); border-radius:2px; margin-top:6px; overflow:hidden; }
  .pd-pulse-bar > span { display:block; height:100%; }
  .pd-pulse-bar.ok > span    { width:96%; background:var(--pd-ok); }
  .pd-pulse-bar.warn > span  { width:55%; background:var(--pd-warn); }
  .pd-pulse-bar.bad > span   { width:22%; background:var(--pd-bad); }
  .pd-pulse-bar.idle > span  { width:8%;  background:var(--pd-text-dim); }
  .pd-hero-meta { text-align:right; font-family:var(--pd-font-mono); font-size:11px; color:var(--pd-text-dim); }
  .pd-hero-meta b { display:block; color:var(--pd-text); font-size:13px; font-weight:500; }

  /* Top bar with refresh control */
  .pd-top { display:flex; justify-content:space-between; align-items:center; padding:18px 0 18px;
    border-bottom:1px solid var(--pd-border); margin-bottom:24px; }
  .pd-top-meta { color:var(--pd-text-dim); font-size:11.5px; font-family:var(--pd-font-mono); }
  .pd-top-controls { display:flex; align-items:center; gap:10px; }
  .pd-refresh-select { background:rgba(255,255,255,.04); border:1px solid var(--pd-border-strong); color:var(--pd-text);
    padding:5px 10px; border-radius:var(--pd-r-md); font-size:11.5px; font-family:inherit; }
  .pd-refresh-btn { background:rgba(255,255,255,.04); border:1px solid var(--pd-border-strong); color:var(--pd-text);
    padding:5px 12px; border-radius:var(--pd-r-md); font-size:11.5px; cursor:pointer; font-family:inherit; }
  .pd-refresh-btn:hover { background:rgba(255,255,255,.08); }

  /* Section */
  .pd-section { margin-bottom:36px; }
  .pd-section-head { display:flex; align-items:baseline; justify-content:space-between; margin-bottom:14px; }
  .pd-section-title { font-size:11px; text-transform:uppercase; letter-spacing:0.12em; color:var(--pd-text-muted); font-weight:500; }
  .pd-section-sub { font-size:11.5px; color:var(--pd-text-dim); }
  .pd-section-sub a:hover { color:var(--pd-text-muted); }

  /* Health */
  .pd-health { background:var(--pd-surface); border:1px solid var(--pd-border); border-radius:var(--pd-r-lg); overflow:hidden; }
  .pd-h-row { display:grid; grid-template-columns:4px 26px 1fr auto; gap:14px; align-items:center;
    padding:12px 20px; border-bottom:1px solid var(--pd-border); cursor:pointer; transition:background .12s ease; }
  .pd-h-row:last-child { border-bottom:none; }
  .pd-h-row:hover { background:var(--pd-surface-2); }
  .pd-h-stripe { height:24px; border-radius:2px; }
  .pd-h-row.ok .pd-h-stripe   { background:rgba(134,239,172,.4); }
  .pd-h-row.warn .pd-h-stripe { background:var(--pd-warn); }
  .pd-h-row.bad .pd-h-stripe  { background:var(--pd-bad); }
  .pd-h-row.idle .pd-h-stripe { background:rgba(255,255,255,.08); }
  .pd-h-symbol { font-family:var(--pd-font-mono); font-size:12px; font-weight:500; text-align:center; }
  .pd-h-row.ok .pd-h-symbol   { color:var(--pd-ok); }
  .pd-h-row.warn .pd-h-symbol { color:var(--pd-warn); }
  .pd-h-row.bad .pd-h-symbol  { color:var(--pd-bad); }
  .pd-h-row.idle .pd-h-symbol { color:var(--pd-text-dim); }
  .pd-h-name { font-size:13.5px; font-weight:500; }
  .pd-h-meta { font-size:11.5px; color:var(--pd-text-dim); margin-top:1px; font-family:var(--pd-font-mono); }
  .pd-h-value { text-align:right; font-family:var(--pd-font-mono); font-size:13px; color:var(--pd-text-muted); }
  .pd-h-value b { color:var(--pd-text); font-weight:500; }

  /* SaaS cards */
  .pd-biz { display:grid; grid-template-columns:repeat(3, 1fr); gap:14px; margin-bottom:14px; }
  .pd-biz-card { background:var(--pd-surface); border:1px solid var(--pd-border); border-radius:var(--pd-r-lg);
    padding:18px 22px; transition:border-color .12s ease; }
  .pd-biz-card:hover { border-color:var(--pd-border-strong); }
  .pd-biz-card.wide { grid-column:span 2; }
  .pd-biz-lbl { font-size:11px; text-transform:uppercase; letter-spacing:.08em; color:var(--pd-text-dim); margin-bottom:8px; }
  .pd-biz-num { font-size:32px; font-weight:600; letter-spacing:-0.02em; line-height:1.1; }
  .pd-biz-num small { font-family:var(--pd-font-mono); font-size:11px; color:var(--pd-text-dim); margin-left:6px; font-weight:400; vertical-align:middle; }
  .pd-biz-delta { font-size:12px; color:var(--pd-text-dim); margin-top:6px; }
  .pd-biz-delta b { color:var(--pd-ok); font-weight:500; }
  .pd-biz-delta b.down { color:var(--pd-bad); }
  .pd-biz-spark { margin-top:14px; height:38px; }

  /* Funnel */
  .pd-funnel { background:var(--pd-surface); border:1px solid var(--pd-border); border-radius:var(--pd-r-lg); padding:20px 24px; }
  .pd-funnel-title { font-size:11px; text-transform:uppercase; letter-spacing:.1em; color:var(--pd-text-dim); margin-bottom:14px; }
  .pd-funnel-row { display:flex; align-items:center; gap:14px; padding:10px 0; }
  .pd-funnel-row + .pd-funnel-row { border-top:1px solid var(--pd-border); }
  .pd-funnel-step { width:160px; font-size:12px; color:var(--pd-text-muted); }
  .pd-funnel-bar { flex:1; height:22px; background:rgba(255,255,255,.04); border-radius:4px; overflow:hidden; }
  .pd-funnel-bar > span { display:block; height:100%; background:linear-gradient(90deg, var(--pd-accent) 0%, rgba(190,242,100,.6) 100%); }
  .pd-funnel-count { width:90px; text-align:right; font-family:var(--pd-font-mono); font-size:12.5px; color:var(--pd-text); }
  .pd-funnel-count small { color:var(--pd-text-dim); font-size:10.5px; }

  /* WP */
  .pd-wp { display:grid; grid-template-columns:2fr 1fr 1fr; gap:14px; }
  .pd-wp-card { background:var(--pd-surface); border:1px solid var(--pd-border); border-radius:var(--pd-r-lg); padding:18px 22px; }
  .pd-wp-card:hover { border-color:var(--pd-border-strong); }
  .pd-wp-lbl { font-size:11px; text-transform:uppercase; letter-spacing:.08em; color:var(--pd-text-dim); margin-bottom:6px; }
  .pd-wp-num { font-size:26px; font-weight:600; letter-spacing:-0.02em; line-height:1.15; }
  .pd-wp-sub { font-size:12px; color:var(--pd-text-dim); margin-top:4px; }
  .pd-ratio-bar { margin-top:14px; }
  .pd-ratio-track { height:8px; background:rgba(255,255,255,.05); border-radius:4px; overflow:hidden; display:flex; }
  .pd-ratio-track > .free    { background:rgba(125,211,252,.65); height:100%; }
  .pd-ratio-track > .premium { background:var(--pd-accent); height:100%; }
  .pd-ratio-legend { display:flex; gap:14px; margin-top:8px; font-size:11px; color:var(--pd-text-muted); }
  .pd-ratio-legend .pd-swatch { display:inline-block; width:8px; height:8px; border-radius:2px; margin-right:6px; vertical-align:middle; }
  .pd-ratio-legend .pd-swatch.free    { background:rgba(125,211,252,.65); }
  .pd-ratio-legend .pd-swatch.premium { background:var(--pd-accent); }
  .pd-ratio-legend b { color:var(--pd-text); font-weight:500; }

  /* Two col attention row */
  .pd-two-col { display:grid; grid-template-columns:1.4fr 1fr; gap:14px; }
  .pd-domains { background:var(--pd-surface); border:1px solid var(--pd-border); border-radius:var(--pd-r-lg); padding:8px 0; }
  .pd-domain-row { display:grid; grid-template-columns:auto 1fr auto auto; gap:16px; align-items:center; padding:13px 22px; cursor:pointer; }
  .pd-domain-row + .pd-domain-row { border-top:1px solid var(--pd-border); }
  .pd-domain-row:hover { background:var(--pd-surface-2); }
  .pd-domain-dot { width:8px; height:8px; border-radius:50%; }
  .pd-domain-dot.ok   { background:var(--pd-ok); }
  .pd-domain-dot.warn { background:var(--pd-warn); }
  .pd-domain-dot.bad  { background:var(--pd-bad); }
  .pd-domain-host { font-family:var(--pd-font-mono); font-size:13px; }
  .pd-domain-tenant { font-size:11px; color:var(--pd-text-dim); margin-top:2px; }
  .pd-domain-state { font-size:12px; }
  .pd-domain-state.ok   { color:var(--pd-ok); }
  .pd-domain-state.warn { color:var(--pd-warn); }
  .pd-domain-state.bad  { color:var(--pd-bad); }
  .pd-domain-age { font-family:var(--pd-font-mono); font-size:11.5px; color:var(--pd-text-dim); }
  .pd-domain-empty { padding:36px 22px; text-align:center; color:var(--pd-text-dim); font-size:13px; }
  .pd-domain-empty b { color:var(--pd-text-muted); display:block; margin-bottom:4px; font-weight:500; }

  .pd-events { background:var(--pd-surface); border:1px solid var(--pd-border); border-radius:var(--pd-r-lg); overflow:hidden; }
  .pd-events-head { padding:12px 18px; border-bottom:1px solid var(--pd-border); font-size:10.5px; text-transform:uppercase; letter-spacing:.1em; color:var(--pd-text-dim); }
  .pd-event-row { display:grid; grid-template-columns:12px 1fr auto; gap:12px; padding:10px 18px; align-items:center; font-size:12px; }
  .pd-event-row + .pd-event-row { border-top:1px solid var(--pd-border); }
  .pd-event-dot { width:6px; height:6px; border-radius:50%; }
  .pd-event-dot.info { background:var(--pd-info); }
  .pd-event-dot.ok   { background:var(--pd-ok); }
  .pd-event-dot.warn { background:var(--pd-warn); }
  .pd-event-dot.bad  { background:var(--pd-bad); }
  .pd-event-text { color:var(--pd-text-muted); }
  .pd-event-time { font-family:var(--pd-font-mono); font-size:10.5px; color:var(--pd-text-dim); }
  .pd-event-empty { padding:24px 18px; text-align:center; font-size:12px; color:var(--pd-text-dim); }

  @media (max-width: 1100px) {
    .pd-biz { grid-template-columns:1fr 1fr; }
    .pd-biz-card.wide { grid-column:span 2; }
    .pd-wp { grid-template-columns:1fr; }
    .pd-two-col { grid-template-columns:1fr; }
  }
</style>

<div class="pd">

  {{-- ────────── HERO ────────── --}}
  <div class="pd-hero">
    <div class="pd-hero-state {{ $hero['state'] }}">
      <div class="pd-hero-dot"></div>
      <div>
        <div class="pd-hero-label">System status</div>
        <div class="pd-hero-headline">{{ $hero['headline'] }}</div>
      </div>
    </div>

    <div class="pd-hero-pulses">
      @foreach($hero['pulses'] as $key => $p)
        <div class="pd-pulse" title="{{ $p['label'] }}">
          <div class="pd-pulse-lbl">{{ $p['label'] }}</div>
          <div class="pd-pulse-bar {{ $p['state'] }}"><span></span></div>
        </div>
      @endforeach
    </div>

    <div class="pd-hero-meta">
      @if($hero['uptime'])<b>{{ $hero['uptime'] }}</b>uptime@endif
    </div>
  </div>

  {{-- ────────── REFRESH CONTROLS ────────── --}}
  <div class="pd-top">
    <div class="pd-top-meta">Generated {{ $generatedAt->format('H:i:s') }} UTC</div>
    <div class="pd-top-controls">
      <select class="pd-refresh-select" id="pd-refresh-select">
        <option value="0">Refresh: off</option>
        <option value="30">Refresh: 30s</option>
        <option value="60">Refresh: 60s</option>
        <option value="300">Refresh: 5min</option>
      </select>
      <button class="pd-refresh-btn" onclick="window.location.reload()">Refresh now</button>
    </div>
  </div>

  {{-- ────────── HEALTH ────────── --}}
  <div class="pd-section">
    <div class="pd-section-head">
      <div class="pd-section-title">Operational health</div>
      <div class="pd-section-sub"><a href="/admin/debug-logs">View full system log →</a></div>
    </div>

    <div class="pd-health">
      @foreach($health as $row)
        <a class="pd-h-row {{ $row['state'] }}" @if($row['href']) href="{{ $row['href'] }}" @endif>
          <div class="pd-h-stripe"></div>
          <div class="pd-h-symbol">{{ ['ok'=>'OK','warn'=>'!','bad'=>'!!','idle'=>'—'][$row['state']] ?? '?' }}</div>
          <div>
            <div class="pd-h-name">{{ $row['name'] }}</div>
            <div class="pd-h-meta">{{ $row['meta'] }}</div>
          </div>
          <div class="pd-h-value">{!! $row['value'] !!}</div>
        </a>
      @endforeach
    </div>
  </div>

  {{-- ────────── SAAS ────────── --}}
  <div class="pd-section">
    <div class="pd-section-head">
      <div class="pd-section-title">Intake SaaS</div>
      <div class="pd-section-sub">{{ $saas['totalTenants'] }} tenants · <a href="/admin/tenants">tenants directory →</a></div>
    </div>

    <div class="pd-biz">
      <a class="pd-biz-card" href="/admin/tenants">
        <div class="pd-biz-lbl">Total tenants</div>
        <div class="pd-biz-num">{{ $saas['totalTenants'] }} <small>{{ $saas['newThisWeek'] }} new this week</small></div>
        <div class="pd-biz-delta">
          @if($saas['weekDelta'] > 0)<b>+{{ $saas['weekDelta'] }}%</b>@elseif($saas['weekDelta'] < 0)<b class="down">{{ $saas['weekDelta'] }}%</b>@else flat@endif
          vs last week
        </div>
        @php
          $max = max(max($saas['weekly']), 1);
          $points = '';
          foreach ($saas['weekly'] as $i => $v) {
            $x = round(($i / 11) * 200, 1);
            $y = round(38 - ($v / $max) * 30, 1);
            $points .= ($i === 0 ? "M{$x} {$y}" : " L{$x} {$y}");
          }
        @endphp
        <svg class="pd-biz-spark" viewBox="0 0 200 40" preserveAspectRatio="none">
          <path d="{{ $points }}" stroke="#BEF264" stroke-width="1.5" fill="none"/>
        </svg>
      </a>

      <a class="pd-biz-card wide" href="/admin/tenants">
        <div class="pd-biz-lbl">Est. MRR</div>
        <div class="pd-biz-num">${{ number_format($saas['mrr']) }} <small>from {{ $saas['paidCount'] }} paid {{ \Str::plural('plan', $saas['paidCount']) }}</small></div>
        <div class="pd-biz-delta">
          {{ $saas['inTrial'] }} in trial · est. <b>+${{ number_format($saas['trialPotential']) }}</b> if all convert
        </div>
      </a>
    </div>

    <div class="pd-funnel">
      <div class="pd-funnel-title">Trial funnel · last 30 days</div>
      @foreach($funnel as $step)
        <div class="pd-funnel-row">
          <div class="pd-funnel-step">{{ $step['label'] }}</div>
          <div class="pd-funnel-bar"><span style="width:{{ $step['pct'] }}%"></span></div>
          <div class="pd-funnel-count">{{ $step['count'] }} <small>· {{ $step['pct'] }}%</small></div>
        </div>
      @endforeach
    </div>
  </div>

  {{-- ────────── WP ────────── --}}
  <div class="pd-section">
    <div class="pd-section-head">
      <div class="pd-section-title">WordPress plugin</div>
      <div class="pd-section-sub">licence server</div>
    </div>

    <div class="pd-wp">
      <div class="pd-wp-card">
        <div class="pd-wp-lbl">Free vs Premium</div>
        <div class="pd-wp-num">{{ $wp['total'] }} <small style="font-family:var(--pd-font-mono);font-size:11px;color:var(--pd-text-dim);margin-left:6px;font-weight:400">installs reporting</small></div>
        <div class="pd-ratio-bar">
          <div class="pd-ratio-track">
            <div class="free" style="width:{{ $wp['freePct'] }}%"></div>
            <div class="premium" style="width:{{ $wp['premiumPct'] }}%"></div>
          </div>
          <div class="pd-ratio-legend">
            <span><i class="pd-swatch free"></i> Free <b>{{ $wp['free'] }}</b></span>
            <span><i class="pd-swatch premium"></i> Premium <b>{{ $wp['premium'] }}</b></span>
          </div>
        </div>
      </div>

      <div class="pd-wp-card">
        <div class="pd-wp-lbl">Active in 30d</div>
        <div class="pd-wp-num">{{ $wp['active'] }}</div>
        <div class="pd-wp-sub">phoning home with a heartbeat</div>
      </div>

      <div class="pd-wp-card">
        <div class="pd-wp-lbl">Active licences</div>
        <div class="pd-wp-num">{{ $wp['activeLicenses'] }}</div>
        <div class="pd-wp-sub">valid, non-expired keys</div>
      </div>
    </div>
  </div>

  {{-- ────────── DOMAINS + RECENT EVENTS ────────── --}}
  <div class="pd-section">
    <div class="pd-section-head">
      <div class="pd-section-title">Tenant attention</div>
      <div class="pd-section-sub"><a href="/admin/tenant-domains">all domains →</a></div>
    </div>

    <div class="pd-two-col">
      <div class="pd-domains">
        @if(count($domains))
          @foreach($domains as $d)
            <a class="pd-domain-row" href="{{ $d['href'] }}">
              <div class="pd-domain-dot {{ $d['state'] }}"></div>
              <div>
                <div class="pd-domain-host">{{ $d['hostname'] }}</div>
                <div class="pd-domain-tenant">{{ $d['tenant'] ?? '—' }} · {{ $d['age'] }}</div>
              </div>
              <div class="pd-domain-state {{ $d['state'] }}">{{ $d['label'] }}</div>
              <div class="pd-domain-age">&nbsp;</div>
            </a>
          @endforeach
        @else
          <div class="pd-domain-empty">
            <b>No domains need attention.</b>
            When a tenant is stuck in DNS verification, errored, or has a cert about to expire, the affected domain shows here with a one-click path to its detail page.
          </div>
        @endif
      </div>

      <div class="pd-events">
        <div class="pd-events-head">Recent activity</div>
        @if(count($activity))
          @foreach($activity as $e)
            <div class="pd-event-row">
              <div class="pd-event-dot {{ $e['tone'] }}"></div>
              <div class="pd-event-text">{{ $e['text'] }}</div>
              <div class="pd-event-time">{{ $e['time'] }}</div>
            </div>
          @endforeach
        @else
          <div class="pd-event-empty">No recent activity yet. Events will start appearing here as tenants onboard and sign in.</div>
        @endif
      </div>
    </div>
  </div>

</div>

<script>
  // Refresh-rate dropdown with localStorage persistence.
  (function() {
    var sel = document.getElementById('pd-refresh-select');
    if (!sel) return;
    var KEY = 'pd-refresh-secs';
    sel.value = localStorage.getItem(KEY) || '0';
    var timer = null;
    function applyTimer() {
      if (timer) { clearTimeout(timer); timer = null; }
      var secs = parseInt(sel.value, 10);
      if (secs > 0) {
        timer = setTimeout(function() { window.location.reload(); }, secs * 1000);
      }
    }
    sel.addEventListener('change', function() {
      localStorage.setItem(KEY, sel.value);
      applyTimer();
    });
    applyTimer();
  })();
</script>

</x-filament-panels::page>
