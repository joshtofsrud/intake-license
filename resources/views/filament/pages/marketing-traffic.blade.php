{{-- MARKER-MKTTRAFFIC --}}
<x-filament-panels::page>
{{-- MARKER-MKTCONV — tabs instead of one growing column. Panels are the
     existing blocks, wrapped; Conversions is new. Plain JS, no Livewire round
     trip, so switching tabs never refetches. --}}
<style>
  .mkt-tabs{display:flex;gap:4px;flex-wrap:wrap;margin-bottom:16px;border-bottom:1px solid rgba(127,127,127,.2);padding-bottom:8px}
  .mkt-tab{padding:6px 12px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;background:none;color:inherit;opacity:.6}
  .mkt-tab.on{opacity:1;border-color:rgba(127,127,127,.3);background:rgba(127,127,127,.1)}
  .mkt-panel[hidden]{display:none}
</style>
<div class="mkt-tabs">
  <button type="button" class="mkt-tab on" data-mkt-tab="overview">Overview</button>
  <button type="button" class="mkt-tab" data-mkt-tab="pages">Pages &amp; sources</button>
  <button type="button" class="mkt-tab" data-mkt-tab="sessions">Sessions</button>
  <button type="button" class="mkt-tab" data-mkt-tab="conversions">Conversions</button>
</div>

@if(! $platform)
  <div style="padding:20px;border-radius:12px;background:rgba(255,255,255,.04)">
    No platform tenant found (<code>tenants.is_platform</code>), so there is nothing to report on yet.
  </div>
@else

<style>
.mt-bar{display:flex;gap:6px;margin-bottom:18px}
/* MARKER-TRAFFIC-POLISH — the pill centres its own label rather than relying
   on the row's alignment, which left the text sitting low. */
.mt-bar a{height:100%;display:inline-flex;align-items:center;justify-content:center;
  padding:0 14px;border-radius:99px;font-size:12.5px;text-decoration:none;
  background:rgba(255,255,255,.06);color:inherit;opacity:.6}
.mt-bar a.on{opacity:1;font-weight:600;box-shadow:inset 0 0 0 1px currentColor}
.mt-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:22px}
.mt-tile{padding:14px 16px;border-radius:12px;background:rgba(255,255,255,.04)}
.mt-tile-k{font-size:11px;opacity:.55}
.mt-tile-v{font-size:24px;font-weight:700;margin-top:2px}
.mt-tile-d{font-size:11.5px;margin-top:2px;opacity:.6}
/* MARKER-TRAFFIC-V3 */
.mt-range{display:inline-flex;align-items:center;gap:6px;border:1px solid rgba(127,127,127,.3);border-radius:999px;padding:4px 10px;margin-left:6px}
.mt-range input{background:none;border:0;color:inherit;font:inherit;font-size:12.5px;width:120px}
.mt-range input::-webkit-calendar-picker-indicator{filter:invert(.6);cursor:pointer}
.mt-clear{font-size:11.5px;opacity:.55;text-decoration:underline}
.mt-compare{display:inline-flex;align-items:center;gap:7px;font-size:12.5px;opacity:.75;margin-left:10px}
.mt-legend{font-size:11px;opacity:.42;margin-top:2px}
.mt-two-up{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:20px}
@media(max-width:900px){.mt-two-up{grid-template-columns:1fr}}
.mt-card{border:1px solid rgba(127,127,127,.22);border-radius:12px;padding:14px 16px}
.mt-bar-row{position:relative;display:flex;align-items:center;gap:10px;padding:7px 8px;font-size:13px}
/* MARKER-TRAFFIC-V3-FIX — .mt-bar is the PRESETS toolbar; reusing the name
   here as an absolutely-positioned fill pulled that toolbar out of flow. */
.mt-fill{position:absolute;left:0;top:2px;bottom:2px;background:rgba(139,124,246,.16);border-radius:6px}
.mt-bar-label{position:relative;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
.mt-bar-n{position:relative;margin-left:auto;opacity:.6;font-variant-numeric:tabular-nums}
/* MARKER-TRAFFIC-V2 */
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
.mt-metric .d.up{color:#7FD98F;opacity:1}.mt-metric .d.down{color:#F08A8A;opacity:1}
.mt-chartwrap{padding:14px 16px 8px}
.mt-axis{display:flex;justify-content:space-between;font-size:11px;opacity:.45;margin-top:4px}
/* MARKER-FUNNEL-TILES */
.mt-tiles{display:flex;align-items:stretch;gap:0;flex-wrap:wrap}
.mt-tile{flex:1 1 0;min-width:104px;padding:14px 12px;border-radius:12px;
  background:rgba(139,124,246,.10);border:1px solid rgba(139,124,246,.22);text-align:center}
.mt-tile.is-zero{background:rgba(255,255,255,.03);border-color:rgba(127,127,127,.20)}
.mt-tile .n{font-size:24px;font-weight:700;letter-spacing:-.02em;line-height:1.1;font-variant-numeric:tabular-nums}
.mt-tile .l{font-size:12px;margin-top:4px;opacity:.75;line-height:1.35}
.mt-tile .p{font-size:11px;margin-top:3px;opacity:.45}
.mt-tile.is-zero .n{opacity:.45}
.mt-gap{display:flex;flex-direction:column;align-items:center;justify-content:center;
  min-width:52px;padding:0 4px;gap:2px}
.mt-arrow{font-size:13px;opacity:.3}
.mt-drop{font-size:11px;color:#F0A0A0;opacity:.85;font-variant-numeric:tabular-nums}
@media(max-width:760px){
  .mt-tiles{flex-direction:column}
  .mt-gap{flex-direction:row;min-width:0;padding:2px 0}
}
.mt-hint{font-size:12px;opacity:.5;line-height:1.55;margin-top:10px}
.mt-sec{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;opacity:.5;margin:24px 0 10px}
.mt-step{padding:11px 14px;border-radius:10px;background:rgba(255,255,255,.04);
  display:flex;align-items:center;gap:12px}
.mt-step-l{flex:1;font-size:13.5px}
.mt-step-n{font-size:18px;font-weight:700;font-variant-numeric:tabular-nums}
.mt-step-u{font-size:11px;opacity:.45;width:64px;text-align:right}
.mt-step-note{font-size:11px;opacity:.5;margin-top:3px}
.mt-track{height:4px;border-radius:3px;background:rgba(255,255,255,.10);margin-top:7px;overflow:hidden}
.mt-track i{display:block;height:100%;background:currentColor;opacity:.75}
.mt-two{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:820px){.mt-two{grid-template-columns:1fr}}
.mt-row{display:flex;justify-content:space-between;gap:12px;padding:7px 0;font-size:13px;
  border-bottom:.5px solid rgba(255,255,255,.07)}
.mt-row:last-child{border-bottom:0}
.mt-empty{padding:18px;text-align:center;font-size:12.5px;opacity:.4}
</style>

<div class="mt-bar">
  {{-- MARKER-MKTSID --}}
  @foreach(['1d' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days'] as $wKey => $wLabel)
    <a href="?window={{ $wKey }}" class="{{ $window === $wKey ? 'on' : '' }}">{{ $wLabel }}</a>
  @endforeach
  {{-- MARKER-TRAFFIC-V3 — a real range. TrafficReportService always accepted
       from/to; only the page never offered it. --}}
  <form method="GET" class="mt-range">
    <input type="hidden" name="window" value="{{ $window }}">
    <input type="date" name="from" value="{{ $from ?? '' }}" onchange="this.form.submit()">
    <span>→</span>
    <input type="date" name="to" value="{{ $to ?? '' }}" onchange="this.form.submit()">
    @if($from || $to)
      <a href="{{ url()->current() }}?window={{ $window }}" class="mt-clear" title="Back to the preset">clear</a>
    @endif
  </form>

  <label class="mt-compare">
    <input type="checkbox" wire:model.live="compare" @checked($compare)>
    <span>Compare to previous</span>
  </label>

  <span style="margin-left:auto;font-size:12px;opacity:.45;align-self:center">{{ $rangeLabel }}</span>
</div>

{{-- MARKER-INVEST-SHARE — Overview carries the tiles, the funnel and the
     chart; Pages & sources keeps the tables. --}}
<div class="mkt-panel" data-mkt-panel="overview">
{{-- MARKER-TRAFFIC-V2 — the tiles pick which metric the chart draws, so one
     large chart answers four questions instead of four tiles answering none. --}}
@if($identityCutover)
  <div class="mt-note mt-note--warn">
    <b>Visitor counting changed on {{ $identityCutover }}.</b>
    Before then a person returning in a new tab counted twice, so this window mixes two definitions — a drop
    across that date is partly the fix, not lost traffic.
  </div>
@endif

<div class="mt-headline">
  <div class="mt-metrics">
    @foreach(collect($stats)->only(['visitors', 'page_views', 'started', 'completed']) as $key => $tile)
      <button type="button" class="mt-metric {{ $metric === $key ? 'is-on' : '' }}"
              wire:click="$set('metric', '{{ $key }}')">
        <span class="k">{{ $tile['label'] ?? $key }}</span>
        <span class="v">{{ number_format((float) ($tile['value'] ?? 0)) }}</span>
        @if(isset($tile['delta']) && $tile['delta'] !== null)
          <span class="d {{ $tile['delta'] > 0 ? 'up' : ($tile['delta'] < 0 ? 'down' : '') }}">
            {{ $tile['delta'] > 0 ? '+' : '' }}{{ $tile['delta'] }}% vs previous
          </span>
        @endif
      </button>
    @endforeach
  </div>

  <div class="mt-chartwrap">
    @if(($series['points'] ?? 0) > 1)
      @php
        $peak = max(1, (int) $series['peak']);
        $n    = max(1, count($series['current']) - 1);
        $line = function ($vals) use ($peak, $n) {
            $out = [];
            foreach (array_values($vals) as $i => $v) {
                $out[] = round(($i / $n) * 720, 1) . ',' . round(190 - (((int) $v / $peak) * 165), 1);
            }
            return implode(' ', $out);
        };
      @endphp
      <svg viewBox="0 0 720 200" width="100%" height="200" preserveAspectRatio="none">
        <g stroke="rgba(127,127,127,.18)" stroke-width="1">
          <line x1="0" y1="25" x2="720" y2="25"/><line x1="0" y1="80" x2="720" y2="80"/>
          <line x1="0" y1="135" x2="720" y2="135"/><line x1="0" y1="190" x2="720" y2="190"/>
        </g>
        @if($compare && count($series['previous']) > 1)
          <polyline fill="none" stroke="rgba(127,127,127,.45)" stroke-width="1.5" stroke-dasharray="3 3"
                    points="{{ $line($series['previous']) }}"/>
        @endif
        {{-- MARKER-TRAFFIC-V3 — the fill is what makes a sparse line read as a
             quantity rather than a squiggle. --}}
        <defs>
          <linearGradient id="mtfill" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="rgb(var(--primary-500))" stop-opacity=".34"/>
            <stop offset="100%" stop-color="rgb(var(--primary-500))" stop-opacity="0"/>
          </linearGradient>
        </defs>
        <polygon fill="url(#mtfill)" points="0,200 {{ $line($series['current']) }} 720,200"/>
        <polyline fill="none" stroke="rgb(var(--primary-500))" stroke-width="2.5"
                  points="{{ $line($series['current']) }}"/>
      </svg>
      <div class="mt-axis">
        {{-- MARKER-TRAFFIC-V3 — real dates, not "start of window" --}}
        @php $lbl = $series['labels'] ?? []; @endphp
        <span>{{ $lbl[0] ?? '' }}</span>
        @if(count($lbl) > 3)<span>{{ $lbl[intdiv(count($lbl), 3)] }}</span>@endif
        @if(count($lbl) > 3)<span>{{ $lbl[intdiv(count($lbl) * 2, 3)] }}</span>@endif
        <span>{{ $lbl[count($lbl) - 1] ?? '' }}</span>
      </div>
      @if($compare)
        <div class="mt-legend">dashed = the same length of time before this window</div>
      @endif
    @else
      <p class="mt-empty">Not enough buckets in this window to draw a line — widen the range.</p>
    @endif
  </div>
</div>

<div class="mt-sec">From visit to shop</div>
@php
  // There is no 'possible' flag on a stage; the step that cannot happen yet is
  // marked by its note. Filtering on a key that does not exist would silently
  // keep everything, so filter on the note.
  $steps = collect($stages ?? [])
      ->reject(fn ($st) => str_contains((string) ($st['note'] ?? ''), "isn't built yet"))
      ->values();
@endphp
@if($steps->count())
  {{-- MARKER-FUNNEL-TILES — equal tiles. At 39 → 1 → 0 the widths of a
       proportional bar say nothing, and a floor under them says less. The
       count is the message; the gap carries the fall-off. --}}
  <div class="mt-tiles">
    @foreach($steps as $i => $st)
      @php
        $count = (int) $st['count'];
        $prev  = $i > 0 ? (int) $steps[$i - 1]['count'] : null;
        $drop  = ($prev !== null && $prev > 0) ? round((1 - ($count / $prev)) * 100) : null;
      @endphp

      @if($i > 0)
        <div class="mt-gap" aria-hidden="true">
          <span class="mt-arrow">→</span>
          {{-- MARKER-TRAFFIC-POLISH — only when the sequence actually falls.
               These steps are independent counts in the window, not stages one
               person passes through: the live data runs 39 → 1 → 0 → 1 → 1, and
               a percentage between unrelated numbers asserts a journey that did
               not happen. --}}
          @if($drop !== null && $drop > 0 && $count > 0)
            <span class="mt-drop">−{{ $drop }}%</span>
          @endif
        </div>
      @endif

      <div class="mt-tile {{ $count === 0 ? 'is-zero' : '' }}">
        <div class="n">{{ number_format($count) }}</div>
        <div class="l">{{ $st['label'] }}</div>
        @if($i > 0 && $steps[0]['count'] > 0)
          <div class="p">{{ round(($count / (int) $steps[0]['count']) * 100) }}% of visits</div>
        @else
          <div class="p">&nbsp;</div>
        @endif
      </div>
    @endforeach
  </div>

  <p class="mt-hint">
    Each tile counts what happened in this window on its own — they are not yet scoped to the people who
    passed the step before, so a later number can be higher than an earlier one. Percentages appear only
    where the sequence genuinely falls. A step that cannot happen yet is left out rather than sitting at
    zero.
  </p>
@else
  <p class="mt-empty">No funnel activity in this window.</p>
@endif

<div class="mt-two" style="margin-top:24px">
  <div>
{{-- MARKER-TRAFFIC-V2 — the daily bars moved into the headline chart above,
     where they can be compared against the previous period. --}}
{{-- MARKER-MKTSESSTYLE — mirrors tenant admin's booking sessions explorer
     (resources/views/tenant/reports/traffic.blade.php, .rse-* block). Same
     class names and values, so the two surfaces stay comparable. The scroll
     box is what keeps this section from running away as traffic grows. --}}

{{-- MARKER-TRAFFIC-V3 — where they came from and what they read belong under
     the funnel, not behind another tab. --}}
<div class="mt-two-up">
  <div class="mt-card">
    <div class="mt-sec" style="margin-top:0">Where they came from</div>
    @php $srcMax = collect($sources)->max('visits') ?: 1; @endphp
    @forelse($sources as $src)
      <div class="mt-bar-row">
        <span class="mt-fill" style="width:{{ max(6, round((($src['visits'] ?? 0) / $srcMax) * 100)) }}%"></span>
        <span class="mt-bar-label">{{-- MARKER-TRAFFIC-V3-FIX — topSources() emits 'name'; reading 'source'
             made every row read "unknown". --}}
        {{ $src['name'] ?: '(direct)' }}</span>
        <span class="mt-bar-n">{{ number_format($src['visits'] ?? 0) }}</span>
      </div>
    @empty
      <p class="mt-empty">Nothing recorded in this window.</p>
    @endforelse
  </div>

  <div class="mt-card">
    <div class="mt-sec" style="margin-top:0">What they read</div>
    @php $pgMax = collect($pages)->max('views') ?: 1; @endphp
    @forelse($pages as $pg)
      <div class="mt-bar-row">
        <span class="mt-fill" style="width:{{ max(6, round((($pg['views'] ?? 0) / $pgMax) * 100)) }}%"></span>
        <span class="mt-bar-label">{{ $pg['path'] ?? '/' }}</span>
        <span class="mt-bar-n">{{ number_format($pg['views'] ?? 0) }}</span>
      </div>
    @empty
      <p class="mt-empty">Nothing recorded in this window.</p>
    @endforelse
  </div>
</div>
</div>
<div class="mkt-panel" data-mkt-panel="pages" hidden>
    <div class="mt-sec" style="margin-top:0">Quiz recommendations</div>
    @forelse($intent['quiz_recommendation'] as $rec => $count)
      <div class="mt-row"><span style="text-transform:capitalize">{{ $rec }}</span><b>{{ number_format($count) }}</b></div>
    @empty
      <div class="mt-empty">No quiz completions in this window</div>
    @endforelse
  </div>

  <div>
@forelse($intent['industry_pages'] as $path => $sessions)
      <div class="mt-row"><span>{{ $path }}</span><b>{{ number_format($sessions) }}</b></div>
    @empty
@endforelse
  </div>
</div>

{{-- MARKER-MKTCONV — chart sits in Pages & sources; it reads with the
     page/source tables it sits beside. --}}
</div>
<div class="mkt-panel" data-mkt-panel="sessions" hidden>
<style>
.rse-zone{margin-top:26px}
.rse-zone-title{font-size:15px;font-weight:700;letter-spacing:-.01em}
.rse-zone-sub{font-size:12px;color:var(--ia-text-dim,rgba(255,255,255,.42));font-weight:500;margin-top:2px}
.rse-filters{display:flex;gap:7px;margin:12px 0;flex-wrap:wrap}
.rse-chip{font-size:11.5px;font-weight:600;border:.5px solid var(--ia-border);border-radius:100px;padding:5px 12px;color:var(--ia-text-2,rgba(255,255,255,.6));cursor:pointer}
.rse-chip.on{background:var(--ia-lime,#BEF264);color:#0B0B0B;border-color:var(--ia-lime,#BEF264);font-weight:700}
.rse-scroll{max-height:430px;overflow-y:auto;border:.5px solid var(--ia-border);border-radius:12px;background:rgba(0,0,0,.18)}
.rse-row{display:flex;align-items:center;gap:13px;padding:12px 15px;border-bottom:.5px solid rgba(255,255,255,.05);cursor:pointer;flex-wrap:wrap}
.rse-row:hover{background:rgba(255,255,255,.03)}
.rse-row:last-child{border-bottom:none}
.rse-time{width:82px;flex:none;font-size:12.5px;font-weight:700}
.rse-time span{display:block;font-size:10.5px;font-weight:400;color:var(--ia-text-dim,rgba(255,255,255,.42))}
.rse-land{flex:1;min-width:170px;font-size:12.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rse-pages{font-size:11px;color:var(--ia-text-dim,rgba(255,255,255,.5));flex:none}
.rse-dev{color:var(--ia-text-dim,rgba(255,255,255,.42));font-size:11px;flex:none;width:52px;text-align:right}
.rse-dur{font-size:12px;font-variant-numeric:tabular-nums;color:var(--ia-text-2,rgba(255,255,255,.72));flex:none;width:56px;text-align:right}
.rse-status{flex:none;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;border-radius:100px;padding:4px 10px}
.rse-status.converted{background:rgba(127,217,143,.11);color:#7FD98F;border:.5px solid rgba(127,217,143,.32)}
.rse-status.browsed{background:rgba(190,242,100,.09);color:var(--ia-lime,#BEF264);border:.5px solid rgba(190,242,100,.32)}
.rse-status.bounced{background:rgba(255,255,255,.05);color:var(--ia-text-dim,rgba(255,255,255,.42));border:.5px solid rgba(255,255,255,.10)}
.rse-detail{display:none;flex-basis:100%;background:rgba(0,0,0,.25);border:.5px solid var(--ia-border);border-radius:10px;margin-top:9px;padding:12px 15px}
.rse-row.open .rse-detail{display:block}
.rse-dmeta{display:flex;gap:16px;flex-wrap:wrap;font-size:11px;color:var(--ia-text-dim,rgba(255,255,255,.42));margin-bottom:9px;border-bottom:.5px solid rgba(255,255,255,.06);padding-bottom:9px}
.rse-ev{display:flex;gap:11px;font-size:12px;padding:4px 0;align-items:baseline}
.rse-ev .t{width:76px;flex:none;color:var(--ia-text-dim,rgba(255,255,255,.42));font-size:11px}
.rse-ev .w{color:var(--ia-text-2,rgba(255,255,255,.72))}
.rse-none{padding:22px;text-align:center;font-size:13px;color:var(--ia-text-dim,rgba(255,255,255,.42))}
</style>

@php
  $msCounts = collect($sessions)->countBy('status');
@endphp

<div class="rse-zone">
  <div class="rse-zone-title">Sessions</div>
  <div class="rse-zone-sub">
    Newest first &middot; duration is first event to last, so a single-page visit reads 0:00 &middot;
    &ldquo;pages&rdquo; means pages visited, not in-page clicks
  </div>

  <div class="rse-filters" id="mktSessFilters">
    <span class="rse-chip on" data-f="all">All ({{ count($sessions) }})</span>
    <span class="rse-chip" data-f="converted">Converted ({{ $msCounts['converted'] ?? 0 }})</span>
    <span class="rse-chip" data-f="browsed">Browsed ({{ $msCounts['browsed'] ?? 0 }})</span>
    <span class="rse-chip" data-f="bounced">Bounced ({{ $msCounts['bounced'] ?? 0 }})</span>
  </div>

  <div class="rse-scroll">
    @forelse($sessions as $sess)
      <div class="rse-row" data-status="{{ $sess['status'] }}" onclick="this.classList.toggle('open')">
        <span class="rse-time">{{ $sess['time'] }}<span>{{ $sess['day'] }}</span></span>
        <span class="rse-land">{{ $sess['landing'] }}</span>
        <span class="rse-pages">{{ $sess['page_count'] }} {{ \Illuminate\Support\Str::plural('page', $sess['page_count']) }}</span>
        <span class="rse-dev">{{ $sess['device'] }}</span>
        <span class="rse-dur">{{ $sess['duration'] }}</span>
        <span class="rse-status {{ $sess['status'] }}">{{ $sess['status'] }}</span>

        <div class="rse-detail" onclick="event.stopPropagation()">
          <div class="rse-dmeta">
            <span>Session {{ $sess['session'] }}</span>
            @if($sess['referrer'])<span>From {{ $sess['referrer'] }}</span>@endif
            @if($sess['utm'])<span>utm_source {{ $sess['utm'] }}</span>@endif
            <span>Duration {{ $sess['duration'] }}</span>
          </div>
          @foreach($sess['timeline'] as $ev)
            <div class="rse-ev"><span class="t">{{ $ev['at'] }}</span><span class="w">{{ $ev['what'] }}</span></div>
          @endforeach
        </div>
      </div>
    @empty
      <div class="rse-none">No sessions in this window yet.</div>
    @endforelse
  </div>
</div>

<script>
  // MARKER-MKTSESSTYLE — same filter behaviour as the tenant explorer.
  (function () {
    var wrap = document.getElementById('mktSessFilters');
    if (!wrap) { return; }
    wrap.addEventListener('click', function (e) {
      var chip = e.target.closest('.rse-chip');
      if (!chip) { return; }
      wrap.querySelectorAll('.rse-chip').forEach(function (c) { c.classList.remove('on'); });
      chip.classList.add('on');
      var f = chip.getAttribute('data-f');
      document.querySelectorAll('.rse-row').forEach(function (r) {
        r.style.display = (f === 'all' || r.getAttribute('data-status') === f) ? 'flex' : 'none';
      });
    });
  })();
</script>

@endif
</div>

{{-- MARKER-MKTCONV — the two conversions that matter, plus click intent --}}
<div class="mkt-panel" data-mkt-panel="conversions" hidden>
    @php $cv = $this->conversions(); @endphp
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px">
        <div style="border:1px solid rgba(127,127,127,.22);border-radius:12px;padding:16px 18px">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;opacity:.55">Demo entries</div>
            <div style="font-size:24px;font-weight:700;margin-top:4px">{{ $cv['demo_entries'] ?? 0 }}</div>
            <div style="font-size:12.5px;opacity:.6">{{ $cv['demo_sessions'] ?? 0 }} distinct visitors</div>
        </div>
        <div style="border:1px solid rgba(127,127,127,.22);border-radius:12px;padding:16px 18px">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;opacity:.55">Booking page</div>
            <div style="font-size:24px;font-weight:700;margin-top:4px">{{ $cv['booking_views'] ?? 0 }}</div>
            <div style="font-size:12.5px;opacity:.6">visitors who opened it</div>
        </div>
        <div style="border:1px solid rgba(127,127,127,.22);border-radius:12px;padding:16px 18px">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;opacity:.55">Calls booked</div>
            <div style="font-size:24px;font-weight:700;margin-top:4px">{{ $cv['bookings'] ?? 0 }}</div>
            <div style="font-size:12.5px;opacity:.6">recorded when the booking saved</div>
        </div>
    </div>

    <div style="border:1px solid rgba(127,127,127,.22);border-radius:12px;padding:16px 18px;margin-top:16px">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;opacity:.55;margin-bottom:8px">Clicks by destination</div>
        @if(!empty($cv['clicks']))
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                @foreach($cv['clicks'] as $label => $count)
                    <span style="border:1px solid rgba(127,127,127,.25);border-radius:999px;padding:4px 12px;font-size:13px">{{ $label }} · {{ $count }}</span>
                @endforeach
            </div>
        @else
            <div style="font-size:13px;opacity:.6">No clicks recorded in this window.</div>
        @endif
        <div style="font-size:12px;opacity:.5;margin-top:10px">
            Clicks come from the browser and can be blocked or missed. Demo entries and booked calls are recorded on the server when they happen, so trust those two.
        </div>
    </div>

    <div style="border:1px solid rgba(127,127,127,.22);border-radius:12px;padding:16px 18px;margin-top:16px">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;opacity:.55;margin-bottom:8px">Most recent</div>
        @forelse($cv['recent'] ?? [] as $r)
            <div style="display:flex;gap:10px;padding:6px 0;border-bottom:1px solid rgba(127,127,127,.12);font-size:13.5px">
                <span style="font-weight:600;min-width:110px">{{ $r['what'] }}</span>
                <span style="opacity:.6">{{ $r['step'] }}</span>
                <span style="margin-left:auto;opacity:.55;font-size:12px">{{ \Carbon\Carbon::parse($r['at'])->diffForHumans() }}</span>
            </div>
        @empty
            <div style="font-size:13px;opacity:.6">Nothing yet in this window.</div>
        @endforelse
    </div>
</div>

<script>
(function () {
  var tabs = document.querySelectorAll('[data-mkt-tab]');
  var panels = document.querySelectorAll('[data-mkt-panel]');
  function show(name) {
    tabs.forEach(function (t) { t.classList.toggle('on', t.dataset.mktTab === name); });
    panels.forEach(function (p) { p.hidden = (p.dataset.mktPanel !== name); });
  }
  tabs.forEach(function (t) {
    t.addEventListener('click', function () { show(t.dataset.mktTab); });
  });
  show('overview');
})();
</script>
</x-filament-panels::page>
