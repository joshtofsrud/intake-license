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
.mt-bar a{padding:6px 13px;border-radius:99px;font-size:12.5px;text-decoration:none;
  background:rgba(255,255,255,.06);color:inherit;opacity:.6}
.mt-bar a.on{opacity:1;font-weight:600;box-shadow:inset 0 0 0 1px currentColor}
.mt-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:22px}
.mt-tile{padding:14px 16px;border-radius:12px;background:rgba(255,255,255,.04)}
.mt-tile-k{font-size:11px;opacity:.55}
.mt-tile-v{font-size:24px;font-weight:700;margin-top:2px}
.mt-tile-d{font-size:11.5px;margin-top:2px;opacity:.6}
.mt-sec{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;opacity:.5;margin:24px 0 10px}
.mt-funnel{display:flex;flex-direction:column;gap:8px}
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
  <span style="margin-left:auto;font-size:12px;opacity:.45;align-self:center">{{ $rangeLabel }}</span>
</div>

<div class="mkt-panel" data-mkt-panel="overview">
{{-- MARKER-MKTTILES — by key: topStats() also returns a 'sessions' entry that
     is explorer data, not a tile, and looping the array rendered it as a
     blank 0. --}}
<div class="mt-grid">
  @foreach(collect($stats)->only(['visitors', 'page_views', 'started', 'completed']) as $tile)
    <div class="mt-tile">
      <div class="mt-tile-k">{{ $tile['label'] ?? '' }}</div>
      <div class="mt-tile-v">{{ number_format((float) ($tile['value'] ?? 0)) }}</div>
      @if(isset($tile['delta']) && $tile['delta'] !== null)
        <div class="mt-tile-d">{{ $tile['delta'] > 0 ? '+' : '' }}{{ $tile['delta'] }}% vs previous</div>
      @endif
    </div>
  @endforeach
</div>

</div>
<div class="mkt-panel" data-mkt-panel="pages" hidden>
<div class="mt-sec">Signup funnel</div>
@php $mtTop = collect($stages)->max('count') ?: 1; @endphp
<div class="mt-funnel">
  @foreach($stages as $stage)
    <div>
      <div class="mt-step">
        <div class="mt-step-l">{{ $stage['label'] }}
          @if($stage['note'])<div class="mt-step-note">{{ $stage['note'] }}</div>@endif
        </div>
        <div class="mt-step-n">{{ number_format($stage['count']) }}</div>
        <div class="mt-step-u">{{ $stage['unit'] }}</div>
      </div>
      <div class="mt-track"><i style="width:{{ $mtTop > 0 ? round(($stage['count'] / $mtTop) * 100) : 0 }}%"></i></div>
    </div>
  @endforeach
</div>

<div class="mt-two" style="margin-top:24px">
  <div>
    <div class="mt-sec" style="margin-top:0">Quiz recommendations</div>
    @forelse($intent['quiz_recommendation'] as $rec => $count)
      <div class="mt-row"><span style="text-transform:capitalize">{{ $rec }}</span><b>{{ number_format($count) }}</b></div>
    @empty
      <div class="mt-empty">No quiz completions in this window</div>
    @endforelse
  </div>

  <div>
    <div class="mt-sec" style="margin-top:0">Industry landing pages</div>
    @forelse($intent['industry_pages'] as $path => $sessions)
      <div class="mt-row"><span>{{ $path }}</span><b>{{ number_format($sessions) }}</b></div>
    @empty
      <div class="mt-empty">No industry page visits in this window</div>
    @endforelse
  </div>
</div>

{{-- MARKER-MKTCONV — chart sits in Pages & sources; it reads with the
     page/source tables it sits beside. --}}
<div class="mt-sec">Daily visitors</div>
{{-- dailyVisitors() returns ['current' => int[], 'prior' => int[], 'hourly' => bool]
     — a flat series of counts, one per bucket, NOT a list of rows. --}}
@php $mtSeries = $daily['current'] ?? []; @endphp
@if(count($mtSeries))
  @php $mtMax = max($mtSeries) ?: 1; @endphp
  <div style="display:flex;align-items:flex-end;gap:2px;height:110px">
    @foreach($mtSeries as $mtV)
      <div title="{{ (int) $mtV }} {{ ($daily['hourly'] ?? false) ? 'this hour' : 'this day' }}"
           style="flex:1;min-width:2px;height:{{ max(2, round(((int) $mtV / $mtMax) * 100)) }}%;
                  background:currentColor;opacity:.55;border-radius:2px 2px 0 0"></div>
    @endforeach
  </div>
@else
  <div class="mt-empty">No traffic recorded yet — data starts accumulating once this deploys.</div>
@endif

{{-- MARKER-MKTSESSTYLE — mirrors tenant admin's booking sessions explorer
     (resources/views/tenant/reports/traffic.blade.php, .rse-* block). Same
     class names and values, so the two surfaces stay comparable. The scroll
     box is what keeps this section from running away as traffic grows. --}}
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
