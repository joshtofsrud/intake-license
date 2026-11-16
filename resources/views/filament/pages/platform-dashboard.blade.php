{{-- master admin dashboard in the Marketing traffic page's vocabulary.
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
  /* the right column held a funnel and a title that
     needed room; at 1fr it wrapped "of signups" mid-phrase. */
  .mt-two-up{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(300px,1fr);gap:14px;margin-top:20px}
  @media(max-width:900px){.mt-two-up{grid-template-columns:1fr}}
  .mt-card{border:1px solid rgba(127,127,127,.22);border-radius:12px;padding:14px 16px}
  .mt-bar-row{position:relative;display:flex;align-items:center;gap:10px;padding:7px 8px;font-size:13px;color:inherit;text-decoration:none}
  /* joins a short label to a value on a card whose width
     is whatever the window leaves it. Without it the two sat at opposite ends
     of the row with nothing relating them. */
  .mt-lead{flex:1;border-bottom:1px dotted rgba(255,255,255,.13);transform:translateY(-3px);min-width:12px}
  .mt-bar-row .mt-bar-label{white-space:nowrap;overflow:visible;text-overflow:clip}
  .mt-bar-row .mt-bar-n{margin-left:0;white-space:nowrap}
  /* .mt-fill deleted, not just unused: dead styling is
     how decoration creeps back into a row that shouldn't have it. */
  .iss-row{display:flex;align-items:flex-start;gap:9px;padding:8px 8px;border-radius:6px;color:inherit;text-decoration:none}
  .iss-row:hover{background:rgba(127,127,127,.08)}
  .iss-dot{width:6px;height:6px;border-radius:50%;background:var(--pd-bad);flex:none;margin-top:6px}
  .iss-body{min-width:0;flex:1}
  .iss-msg{display:block;font-size:12.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .iss-meta{display:block;font-size:11px;opacity:.5;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .iss-meta .pill{margin:0 2px}
  .iss-meta code{font-size:10.5px}
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
  /* MARKER-DASH-CHART-FILL: the chart fills the box, however tall the metric column is */
  .mt-chartwrap{padding:14px 16px 8px;display:flex;flex-direction:column}
  .mt-plot{position:relative;flex:1 1 auto;min-height:150px}
  .mt-plot svg{position:absolute;inset:0;width:100%;height:100%}
  .mt-plot .mt-dot{position:absolute;width:7px;height:7px;margin:-3.5px 0 0 -3.5px;border-radius:50%;background:rgb(139,124,246)}
  .mt-axis{display:flex;justify-content:space-between;font-size:11px;opacity:.45;margin-top:4px}
  /* one row per step. As boxes in a grid they had no
     room in a 300px column and the arrows fell between lines. */
  /* MARKER-LOAD-PANEL: the Load view takes the chart box's place, no new page */
  .pd-open{display:flex;align-items:center;gap:5px;font-size:12px;opacity:.75;margin-right:6px;white-space:nowrap}
  .mt-gtile.is-link{cursor:pointer;border:0;text-align:left;font:inherit;color:inherit;width:100%}
  .mt-gtile.is-link:hover{background:rgba(127,127,127,.13)}
  .mt-gtile.is-link.is-on{box-shadow:inset 0 0 0 1px rgb(var(--primary-500))}
  .mt-gtile .go{font-size:11px;opacity:.5;margin-top:4px}
  .ld{border:1px solid rgba(127,127,127,.22);border-radius:12px;padding:14px 16px}
  .ld[hidden],.mt-headline[hidden]{display:none}
  .ld-top{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
  .ld-back{background:rgba(127,127,127,.12);border:0;color:inherit;font:inherit;font-size:12.5px;border-radius:99px;padding:5px 12px;cursor:pointer}
  .ld-t{font-size:15px;font-weight:700}
  .ld-nums{display:flex;gap:16px;font-size:12px;opacity:.75;font-variant-numeric:tabular-nums}
  .ld-nums b{font-size:18px;opacity:1}
  .ld-when{margin-left:auto;font-size:11.5px;opacity:.5}
  .ld-plot{position:relative;height:110px;margin-top:10px}
  .ld-plot svg{position:absolute;inset:0;width:100%;height:100%}
  .ld-cols{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:6px 28px;margin-top:12px}
  @media(max-width:900px){.ld-cols{grid-template-columns:1fr}}
  .ld-h{font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;opacity:.5;margin:12px 0 4px}
  .ld-row{display:flex;align-items:baseline;gap:10px;padding:4px 0;font-size:12.5px;border-bottom:1px solid rgba(127,127,127,.12)}
  .ld-row .nm{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .ld-row .nm code{font-size:11.5px}
  .ld-row .n{white-space:nowrap;opacity:.65;font-variant-numeric:tabular-nums}
  .ld-row .n.hot{color:var(--pd-warn);opacity:1}
  .ld-row .run{color:var(--pd-ok);font-size:11px;white-space:nowrap}
  .ld-bar{height:3px;border-radius:2px;background:rgba(127,127,127,.18);margin-top:3px;overflow:hidden}
  .ld-bar i{display:block;height:100%;background:rgb(139,124,246)}
  .ld-empty{font-size:12px;opacity:.45;padding:4px 0}
  .mt-tiles{display:flex;flex-direction:column;gap:8px}
  .mt-tiles .mt-gap{display:none}
  .mt-tiles .mt-tile{display:flex;align-items:baseline;gap:10px;text-align:left;min-width:0;padding:10px 12px}
  .mt-tiles .mt-tile .n{font-size:20px;line-height:1}
  .mt-tiles .mt-tile .l{flex:1;margin-top:0}
  .mt-tiles .mt-tile .p{margin-top:0}
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
  .pd-head{display:flex;align-items:center;gap:10px;margin-bottom:14px}
  .pd-head .st{background:none;border:0;padding:0;color:inherit;font:inherit;font-size:12.5px;cursor:pointer;white-space:nowrap}
  .pd-head .gen{font-size:12px;opacity:.5;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .pd-rf{margin-left:auto;display:flex;align-items:center;gap:4px;flex:none}
  .pd-sel{border:1px solid rgba(127,127,127,.3);background:transparent;color:inherit;border-radius:8px;padding:4px 6px;font:inherit;font-size:12.5px}
  .pd-sel option{color:#111}
  .pd-ib{width:30px;height:30px;border:1px solid rgba(127,127,127,.3);background:transparent;color:inherit;border-radius:8px;cursor:pointer;font-size:15px;line-height:1}
  @media(max-width:640px){.pd-head .pd-up{display:none}.pd-head.show-up .pd-up{display:inline}}
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
  {{-- MARKER-DASH-COMPACT: state, time and auto-refresh on one line --}}
  <div class="pd-head">
    <button type="button" class="st {{ $hero['state'] }}" onclick="this.closest('.pd-head').classList.toggle('show-up')" title="Uptime {{ $hero['uptime'] }}"><i></i>{{ $hero['headline'] }}</button>
    <span class="gen">{{ alocal($generatedAt, 'g:i a') }}<span class="pd-up">{{ $hero['uptime'] ? ' · up ' . $hero['uptime'] : '' }}</span></span>
    <span class="pd-rf" id="pd-refresh">
      {{-- MARKER-LOAD-PANEL: which view this page opens on, saved for you --}}
      <label class="pd-open">Opens on
        <select class="pd-sel" id="pd-open-on" aria-label="Dashboard opens on">
          @foreach($openOnOpts as $ok => $ol)
            <option value="{{ $ok }}" {{ $openOn === $ok ? 'selected' : '' }}>{{ $ol }}</option>
          @endforeach
        </select>
      </label>
      <select class="pd-sel" data-refresh-select aria-label="Auto-refresh">
        <option value="30">Live</option>
        <option value="60">1 min</option>
        <option value="300">5 min</option>
        <option value="0">Off</option>
      </select>
      <button type="button" class="pd-ib" title="Refresh now" aria-label="Refresh now" onclick="window.location.reload()">&#8635;</button>
    </span>
  </div>

  {{-- headline: four metrics, one charted --}}
  <div class="mt-headline" id="pd-charts">
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
      <div class="mt-plot">
        <svg id="pd-chart" viewBox="0 0 640 150" preserveAspectRatio="none">
          <defs><linearGradient id="pd-g" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="rgb(139,124,246)" stop-opacity=".35"/><stop offset="1" stop-color="rgb(139,124,246)" stop-opacity="0"/></linearGradient></defs>
          <path id="pd-area" fill="url(#pd-g)" d=""></path>
          <path id="pd-line" fill="none" stroke="rgb(139,124,246)" stroke-width="2" vector-effect="non-scaling-stroke" d=""></path>
        </svg>
        <span class="mt-dot" id="pd-dot" style="left:100%;top:50%"></span>
      </div>
      <div class="mt-axis">
        <span>{{ \Carbon\Carbon::parse($series['labels'][0])->format('M j') }}</span>
        <span>{{ \Carbon\Carbon::parse($series['labels'][intdiv(count($series['labels']), 2)])->format('M j') }}</span>
        <span>{{ \Carbon\Carbon::parse(end($series['labels']))->format('M j') }}</span>
      </div>
      <div class="mt-legend" id="pd-chart-legend"></div>
    </div>
  </div>

  {{-- MARKER-LOAD-PANEL: swaps in for the charts above; Back returns to them --}}
  <div class="ld" id="pd-load" hidden>
    <div class="ld-top">
      <button type="button" class="ld-back" data-show-charts>&larr; Back to charts</button>
      <span class="ld-t">Server load</span>
      <span class="ld-nums" id="ld-nums"></span>
      <span class="ld-when" id="ld-when">Loading…</span>
    </div>
    <div class="ld-plot">
      <svg id="ld-chart" viewBox="0 0 640 110" preserveAspectRatio="none">
        <defs><linearGradient id="ld-g" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="rgb(139,124,246)" stop-opacity=".35"/><stop offset="1" stop-color="rgb(139,124,246)" stop-opacity="0"/></linearGradient></defs>
        <line id="ld-cores" x1="0" x2="640" y1="0" y2="0" stroke="currentColor" stroke-opacity=".25" stroke-dasharray="4 4" vector-effect="non-scaling-stroke"></line>
        <path id="ld-area" fill="url(#ld-g)" d=""></path>
        <path id="ld-line" fill="none" stroke="rgb(139,124,246)" stroke-width="2" vector-effect="non-scaling-stroke" d=""></path>
      </svg>
    </div>
    <div class="ld-cols">
      <div>
        <div class="ld-h">Busiest processes</div><div id="ld-procs"></div>
        <div class="ld-h">Commands running</div><div id="ld-cmds"></div>
        <div class="ld-h">Distributor syncs · last 24 hours</div><div id="ld-syncs"></div>
      </div>
      <div>
        <div class="ld-h">Queues</div><div id="ld-queues"></div>
        <div class="ld-h">Database queries running</div><div id="ld-queries"></div>
        <div class="ld-h">Scheduled · next 2 hours</div><div id="ld-sched"></div>
      </div>
    </div>
    <div class="mt-legend" style="margin-top:12px">
      Refreshes every 5 seconds while open; read only, nothing here stops or changes anything.
      The line is the 1-minute load while this panel has been open (shared by anyone viewing it); the dashed line is the core count, and load above it means work is waiting for a CPU.
      CPU % is per core, so one busy process can show up to 100%. Database queries only list statements running right now, not idle connections. Scheduled leaves out the every-minute housekeeping unless it is running.
    </div>
  </div>

  {{-- server tiles --}}
  <div class="mt-grid" style="margin-top:20px">
    @foreach($hero['tiles'] as $tk => $t)
      @if($tk === 'cpu')
      <button type="button" class="mt-gtile is-link" data-show-load title="See what is running" @if($t['state'] === 'warn') style="box-shadow:inset 0 0 0 1px rgba(240,196,106,.35)" @elseif($t['state'] === 'bad') style="box-shadow:inset 0 0 0 1px rgba(240,138,138,.45)" @endif>
        <div class="mt-tile-k">{{ $t['label'] }}</div>
        <div class="mt-tile-v" @if($t['state'] === 'warn') style="color:var(--pd-warn)" @elseif($t['state'] === 'bad') style="color:var(--pd-bad)" @elseif($t['state'] === 'idle') style="opacity:.45" @endif>{{ $t['value'] }}</div>
        <div class="mt-tile-d">{{ $t['meta'] }}</div>
        <div class="go">What's running &rarr;</div>
      </button>
      @continue
      @endif
      <div class="mt-gtile" @if($t['state'] === 'warn') style="box-shadow:inset 0 0 0 1px rgba(240,196,106,.35)" @elseif($t['state'] === 'bad') style="box-shadow:inset 0 0 0 1px rgba(240,138,138,.45)" @endif>
        <div class="mt-tile-k">{{ $t['label'] }}</div>
        <div class="mt-tile-v" @if($t['state'] === 'warn') style="color:var(--pd-warn)" @elseif($t['state'] === 'bad') style="color:var(--pd-bad)" @elseif($t['state'] === 'idle') style="opacity:.45" @endif>{{ $t['value'] }}</div>
        <div class="mt-tile-d">{{ $t['meta'] }}</div>
      </div>
    @endforeach
  </div>

  {{-- this said the same thing as the tile and the
       health row: three times on one screen for one unwired script. The row
       carries the explanation now. --}}

  <div class="mt-two-up" style="margin-top:0">
    {{-- operational health --}}
    <div class="mt-card">
      <div class="card-h"><span class="t">Operational health</span><span class="s"><a href="/admin/debug-logs">Full system log →</a></span></div>
      @foreach($health as $row)
        <a class="mt-bar-row" href="{{ $row['href'] ?? '#' }}">
          {{-- no fill. Its width meant severity, not a
               quantity, so it drew a box behind the label that looked like a
               button. The pill carries the state instead. --}}
          <span class="mt-bar-label">{{ $row['name'] }}</span>
          <span class="pill {{ $row['state'] }}">{{ ['ok' => 'ok', 'warn' => 'watch', 'bad' => 'action', 'idle' => 'n/a'][$row['state']] ?? $row['state'] }}</span>
          <span class="mt-lead"></span>
          <span class="mt-bar-n">{!! $row['value'] !!}</span>
        </a>
      @endforeach
      {{-- the unresolved errors, not just their count.
           One line per distinct failure; the row above stays the headline. --}}
      @if(!empty($issues))
        <div class="card-h" style="margin-top:14px"><span class="t">Open issues</span><span class="s"><a href="/admin/debug-logs?activeTab=errors">all unresolved →</a></span></div>
        @foreach($issues as $i)
          {{-- an exception message is not a short label.
               One line, truncated, full text on hover; everything else on a
               muted second line. --}}
          <a class="iss-row" href="{{ $i['href'] }}" title="{{ $i['title'] }}{{ $i['detail'] ? ' — ' . $i['detail'] : '' }}">
            <span class="iss-dot" aria-hidden="true"></span>
            <span class="iss-body">
              <span class="iss-msg">{{ $i['title'] }}</span>
              <span class="iss-meta">
                {{ $i['job'] }}
                @if($i['tenant'])<span class="pill">{{ $i['tenant'] }}</span>@endif
                · {{ $i['n'] }}× · {{ $i['last'] }} ago
                @if($i['ref']) · <code>{{ $i['ref'] }}</code>@endif
              </span>
            </span>
          </a>
        @endforeach
      @endif

      {{-- switch + send-to for 5xx alert emails, behavior unchanged. --}}
      <div class="alert500">
        <label style="display:flex;gap:8px;align-items:center;cursor:pointer">
          <input type="checkbox" wire:model="alert500Enabled" style="width:14px;height:14px">
          Email me on every 5xx and failed background job
        </label>
        <input type="email" wire:model="alert500Email" placeholder="alerts@intake.works">
        <button type="button" wire:click="saveAlert500">Save</button>
        <span class="mt-legend" style="flex-basis:100%">One email per distinct error or job failure per 15 minutes, carrying its refId. Off or blank = no emails; everything still logs and shows above.
          <b>Queue stopped</b> and <b>back to normal</b> emails go to this address whenever one is set, even with the switch off (MARKER-QUEUE-WATCHDOG): they come once per outage, and a stopped queue is never noise.</span>
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
      <div class="card-h"><span class="t">WordPress plugin</span><span class="s">license server</span></div>
      <div class="res"><span class="k">Free</span><div class="bar"><span style="width:{{ $wp['freePct'] }}%"></span></div><span class="v">{{ number_format($wp['free']) }} installs</span></div>
      <div class="res"><span class="k">Premium</span><div class="bar"><span style="width:{{ $wp['premiumPct'] }}%"></span></div><span class="v">{{ number_format($wp['premium']) }} installs</span></div>
      <div class="mt-legend" style="margin-top:8px">{{ number_format($wp['active']) }} of {{ number_format($wp['total']) }} installs reporting · {{ number_format($wp['activeLicenses']) }} active licenses.</div>
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
      dot.style.left = (last[0] / W * 100) + '%'; dot.style.top = (last[1] / H * 100) + '%';
      document.getElementById('pd-chart-legend').textContent = m.legend;
      // MARKER-CHART-HOVER: hand the drawn points to the hover read-out
      var hw = document.getElementById('pd-chart').closest('.mt-chartwrap');
      if (hw) {
        hw.setAttribute('data-hover-chart', '');
        hw.dataset.cur = JSON.stringify(s);
        hw.dataset.labels = JSON.stringify(@json(array_values($series['labels'])));
        hw.dataset.geom = [H, H - PAD, H - PAD * 2, max].join(',');
        var kb = document.querySelector('.mt-metric[data-metric="' + key + '"] .k');
        hw.dataset.name = kb ? kb.textContent.trim().replace(/ \u00b7 .*$/, '') : '';
        hw.dataset.fmt = key === 'mrr' ? 'money' : 'int';
      }
    }
    var btns = document.querySelectorAll('.mt-metric[data-metric]');
    btns.forEach(function (b) {
      b.addEventListener('click', function () {
        btns.forEach(function (x) { x.classList.toggle('is-on', x === b); });
        draw(b.dataset.metric);
      });
    });
    // MARKER-LOAD-PANEL: open on the saved view; a reload keeps the view you were on
    var OPEN_ON = @json($openOn);
    var cur = null;
    try { cur = sessionStorage.getItem('pd-view'); } catch (e) {}
    var start = cur || OPEN_ON;
    function pickMetric(k) {
      var b = document.querySelector('.mt-metric[data-metric="' + k + '"]') || btns[0];
      if (!b) return;
      btns.forEach(function (x) { x.classList.toggle('is-on', x === b); });
      draw(b.dataset.metric);
    }
    btns.forEach(function (b) {
      b.addEventListener('click', function () { try { sessionStorage.setItem('pd-view', b.dataset.metric); } catch (e) {} });
    });
    if (start === 'load') { pickMetric('signups'); showLoad(); } else { pickMetric(start); }

    var openSel = document.getElementById('pd-open-on');
    if (openSel) openSel.addEventListener('change', function () {
      @this.call('setOpenOn', openSel.value);
      try { sessionStorage.removeItem('pd-view'); } catch (e) {}
      if (openSel.value === 'load') { showLoad(); } else { showCharts(); pickMetric(openSel.value); }
    });

    // ---- Load panel ----
    var ldTimer = null, ldOpen = false;
    function esc(v) { return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    function dur(s) {
      if (s == null) return '';
      if (s < 60) return s + 's';
      if (s < 3600) return Math.floor(s / 60) + 'm';
      return Math.floor(s / 3600) + 'h ' + Math.floor((s % 3600) / 60) + 'm';
    }
    function rows(list, fn, empty) {
      if (list === null) return '<div class="ld-empty">Unreadable on this server.</div>';
      if (!list || !list.length) return '<div class="ld-empty">' + empty + '</div>';
      return list.map(fn).join('');
    }
    function at(iso) { try { return new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); } catch (e) { return ''; } }
    function paint(d) {
      var c = d.cpu || {};
      document.getElementById('ld-nums').innerHTML =
        '<span><b>' + c.load_1m + '</b> 1 min</span><span><b>' + c.load_5m + '</b> 5 min</span><span><b>' + c.load_15m + '</b> 15 min</span><span><b>' + c.cores + '</b> cores</span>';
      document.getElementById('ld-when').textContent = 'Updated ' + at(d.taken_at);

      var h = d.history || [], W = 640, H = 110, P = 6;
      var max = Math.max.apply(null, h.map(function (p) { return p[1]; }).concat([c.cores || 1, 0.5])) * 1.1;
      var t0 = h.length ? h[0][0] : 0, t1 = h.length ? h[h.length - 1][0] : 1, span = Math.max(1, t1 - t0);
      var pts = h.map(function (p) { return [h.length > 1 ? (p[0] - t0) / span * W : W, H - P - (p[1] / max) * (H - P * 2)]; });
      var path = pts.map(function (p, i) { return (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1); }).join(' ');
      document.getElementById('ld-line').setAttribute('d', path);
      document.getElementById('ld-area').setAttribute('d', pts.length ? path + ' L' + pts[pts.length - 1][0] + ' ' + H + ' L' + pts[0][0] + ' ' + H + ' Z' : '');
      var cy = H - P - ((c.cores || 1) / max) * (H - P * 2);
      var cl = document.getElementById('ld-cores'); cl.setAttribute('y1', cy); cl.setAttribute('y2', cy);

      document.getElementById('ld-procs').innerHTML = rows(d.processes, function (p) {
        return '<div class="ld-row"><span class="nm" title="pid ' + p.pid + '">' + esc(p.name) + '</span><span class="n' + (p.cpu >= 50 ? ' hot' : '') + '">' + p.cpu + '% CPU</span><span class="n">' + p.mem + '% mem</span><span class="n">' + dur(p.secs) + '</span></div>';
      }, 'Nothing busy.');
      document.getElementById('ld-cmds').innerHTML = rows(d.commands, function (p) {
        return '<div class="ld-row"><span class="nm"><code>' + esc(p.command) + '</code>' + (p.n > 1 ? ' ×' + p.n : '') + '</span><span class="n' + (p.cpu >= 50 ? ' hot' : '') + '">' + p.cpu + '% CPU</span><span class="n">running ' + dur(p.secs) + '</span></div>';
      }, 'No artisan commands running.');
      document.getElementById('ld-syncs').innerHTML = rows(d.syncs, function (s) {
        var st = s.status === 'running' ? '<span class="run">running</span>' : (s.status === 'failed' ? '<span class="n hot" title="' + esc(s.error) + '">failed</span>' : '<span class="n">done</span>');
        return '<div class="ld-row" style="display:block"><div style="display:flex;gap:10px;align-items:baseline"><span class="nm">' + esc(s.tenant) + ' · ' + esc(s.distributor) + '</span>' + st +
          (s.linked ? '<span class="n">' + s.processed.toLocaleString() + ' of ' + s.linked.toLocaleString() + '</span><span class="n">' + (s.per_min != null ? s.per_min.toLocaleString() + '/min' : '') + '</span>' : '<span class="n">no items</span>') + '<span class="n">' + dur(s.secs) + '</span></div>' +
          (s.pct != null ? '<div class="ld-bar"><i style="width:' + s.pct + '%"></i></div>' : '') + '</div>';
      }, 'No syncs in the last 24 hours.');
      document.getElementById('ld-queues').innerHTML = rows(d.queues, function (q) {
        var names = Object.keys(q.names || {}).map(function (k) { return esc(k) + (q.names[k] > 1 ? ' ×' + q.names[k] : ''); }).join(', ');
        return '<div class="ld-row"><span class="nm">' + esc(q.queue) + (names ? ' · <span style="opacity:.65">' + names + '</span>' : '') + '</span><span class="n' + (q.waiting > 50 ? ' hot' : '') + '">' + q.waiting + ' waiting</span><span class="n">' + q.running + ' running</span><span class="n">' + q.delayed + ' delayed</span></div>';
      }, 'No queues.');
      document.getElementById('ld-queries').innerHTML = rows(d.queries, function (q) {
        return '<div class="ld-row"><span class="nm" title="' + esc(q.sql) + '"><code>' + esc(q.sql) + '</code></span><span class="n' + (q.secs >= 10 ? ' hot' : '') + '">' + dur(q.secs) + '</span></div>';
      }, 'No queries running right now.');
      document.getElementById('ld-sched').innerHTML = rows(d.scheduled, function (e) {
        return '<div class="ld-row"><span class="nm"><code>' + esc(e.command) + '</code></span>' + (e.running ? '<span class="run">running now</span>' : '') + '<span class="n">' + esc(e.every) + '</span><span class="n">next ' + at(e.next) + '</span></div>';
      }, 'Nothing due in the next 2 hours.');
    }
    function poll() {
      if (!ldOpen) return;
      @this.call('loadSnapshot').then(function (d) { if (d) paint(d); })
        .catch(function () { document.getElementById('ld-when').textContent = 'Could not refresh'; })
        .finally(function () { if (ldOpen) ldTimer = setTimeout(poll, 5000); });
    }
    function showLoad() {
      document.getElementById('pd-charts').hidden = true;
      document.getElementById('pd-load').hidden = false;
      var t = document.querySelector('[data-show-load]'); if (t) t.classList.add('is-on');
      try { sessionStorage.setItem('pd-view', 'load'); } catch (e) {}
      if (!ldOpen) { ldOpen = true; poll(); }
    }
    function showCharts() {
      ldOpen = false; if (ldTimer) { clearTimeout(ldTimer); ldTimer = null; }
      document.getElementById('pd-load').hidden = true;
      document.getElementById('pd-charts').hidden = false;
      var t = document.querySelector('[data-show-load]'); if (t) t.classList.remove('is-on');
      var on = document.querySelector('.mt-metric.is-on');
      try { sessionStorage.setItem('pd-view', on ? on.dataset.metric : 'signups'); } catch (e) {}
    }
    var lt = document.querySelector('[data-show-load]');
    if (lt) lt.addEventListener('click', function () { if (ldOpen) { showCharts(); } else { showLoad(); } });
    document.querySelectorAll('[data-show-charts]').forEach(function (b) { b.addEventListener('click', showCharts); });

    // ---- refresh cadence: one small menu, remembered in this browser ----
    var KEY = 'pd-refresh-secs';
    var sel = document.querySelector('[data-refresh-select]');
    var timer = null;
    function apply(secs) {
      if (timer) { clearTimeout(timer); timer = null; }
      if (sel) { sel.value = String(secs); }
      if (secs > 0) { timer = setTimeout(function () { window.location.reload(); }, secs * 1000); }
    }
    if (sel) {
      sel.addEventListener('change', function () {
        var secs = parseInt(sel.value, 10);
        try { localStorage.setItem(KEY, String(secs)); } catch (e) {}
        apply(secs);
      });
    }
    var saved = '0';
    try { saved = localStorage.getItem(KEY) || '0'; } catch (e) {}
    apply(parseInt(saved, 10));
  })();
</script>
@include('filament.partials.chart-hover')

</x-filament-panels::page>
