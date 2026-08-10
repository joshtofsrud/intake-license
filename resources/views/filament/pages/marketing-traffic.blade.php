{{-- MARKER-MKTTRAFFIC --}}
<x-filament-panels::page>
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
  @foreach(['7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days'] as $wKey => $wLabel)
    <a href="?window={{ $wKey }}" class="{{ $window === $wKey ? 'on' : '' }}">{{ $wLabel }}</a>
  @endforeach
  <span style="margin-left:auto;font-size:12px;opacity:.45;align-self:center">{{ $rangeLabel }}</span>
</div>

<div class="mt-grid">
  @foreach($stats as $tile)
    <div class="mt-tile">
      <div class="mt-tile-k">{{ $tile['label'] ?? '' }}</div>
      <div class="mt-tile-v">{{ number_format((float) ($tile['value'] ?? 0)) }}</div>
      @if(isset($tile['delta']) && $tile['delta'] !== null)
        <div class="mt-tile-d">{{ $tile['delta'] > 0 ? '+' : '' }}{{ $tile['delta'] }}% vs previous</div>
      @endif
    </div>
  @endforeach
</div>

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

{{-- MARKER-MKTSESSIONS — per-session explorer. Click a row to expand. --}}
<style>
.ms-row{border-radius:10px;background:rgba(255,255,255,.04);margin-bottom:6px;overflow:hidden}
.ms-row summary{display:flex;align-items:center;gap:12px;padding:10px 14px;cursor:pointer;list-style:none}
.ms-row summary::-webkit-details-marker{display:none}
.ms-time{font-size:12px;opacity:.6;width:78px;flex:0 0 auto}
.ms-time span{display:block;font-size:10.5px;opacity:.6}
.ms-land{flex:1;min-width:0;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ms-meta{font-size:11.5px;opacity:.5;flex:0 0 auto}
.ms-dur{font-variant-numeric:tabular-nums;font-size:12.5px;opacity:.7;width:60px;text-align:right;flex:0 0 auto}
.ms-badge{font-size:10.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;
  padding:3px 8px;border-radius:99px;flex:0 0 auto}
.ms-badge.converted{background:rgba(120,200,110,.18);color:#8fd082}
.ms-badge.browsed{background:rgba(255,255,255,.10);opacity:.8}
.ms-badge.bounced{background:rgba(255,255,255,.06);opacity:.45}
.ms-detail{padding:4px 14px 14px 92px;font-size:12.5px}
.ms-dmeta{display:flex;gap:14px;flex-wrap:wrap;opacity:.5;font-size:11.5px;margin-bottom:8px}
.ms-ev{display:flex;gap:12px;padding:3px 0}
.ms-ev .t{opacity:.45;font-variant-numeric:tabular-nums;width:82px;flex:0 0 auto}
.ms-note{font-size:11.5px;opacity:.4;margin:8px 0 14px;line-height:1.5}
</style>

<div class="mt-sec">Sessions</div>
<div class="ms-note">
  Newest first. Duration is first event to last, so a single-page visit reads 0:00 however long it was read &mdash;
  and &ldquo;pages&rdquo; means pages visited, not in-page clicks.
</div>

@forelse($sessions as $sess)
  <details class="ms-row">
    <summary>
      <span class="ms-time">{{ $sess['time'] }}<span>{{ $sess['day'] }}</span></span>
      <span class="ms-land">{{ $sess['landing'] }}</span>
      <span class="ms-meta">{{ $sess['page_count'] }} {{ \Illuminate\Support\Str::plural('page', $sess['page_count']) }}</span>
      @if($sess['device'])<span class="ms-meta">{{ $sess['device'] }}</span>@endif
      <span class="ms-dur">{{ $sess['duration'] }}</span>
      <span class="ms-badge {{ $sess['status'] }}">{{ $sess['status'] }}</span>
    </summary>
    <div class="ms-detail">
      <div class="ms-dmeta">
        <span>Session {{ $sess['session'] }}</span>
        @if($sess['referrer'])<span>From {{ $sess['referrer'] }}</span>@endif
        @if($sess['utm'])<span>utm_source {{ $sess['utm'] }}</span>@endif
      </div>
      @foreach($sess['timeline'] as $ev)
        <div class="ms-ev"><span class="t">{{ $ev['at'] }}</span><span>{{ $ev['what'] }}</span></div>
      @endforeach
    </div>
  </details>
@empty
  <div class="mt-empty">No sessions in this window yet.</div>
@endforelse

@endif
</x-filament-panels::page>
