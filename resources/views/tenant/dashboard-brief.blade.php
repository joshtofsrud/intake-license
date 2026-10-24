@extends('layouts.tenant.app')
{{-- MARKER-DASH-BRIEF: the third dashboard view, in beta. Built from the same
     data as Overview (DashboardController); Overview stays the default. --}}
@section('title', 'Dashboard')
@section('mobile-fab', 'walk-in')
@push('styles')
  <link rel="stylesheet" href="{{ asset('css/tenant/dashboard-simple.css') }}?v={{ filemtime(public_path('css/tenant/dashboard-simple.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/tenant/dashboard-brief.css') }}?v={{ filemtime(public_path('css/tenant/dashboard-brief.css')) }}">
  <style>
    .mb-head .vs-ic { display: none; }
    @media (max-width: 1023px) {
      .mb-head .ia-viewseg button { padding: 5px 8px !important; line-height: 0; }
      .mb-head .ia-viewseg .vs-lb { display: none; }
      .mb-head .ia-viewseg .vs-ic { display: inline-block; }
      .mb-head.has-fab .mb-acts > a.ia-btn { display: none !important; }
    }
  </style>
@endpush

@section('content')
@php
  // ---- the queue: the attention cards, grouped by how urgent they are ----
  $lateKeys  = ['overdue_unstarted', 'overdue_in_progress', 'so_overdue'];
  $laterKeys = ['so_needed', 'low_stock', 'catalog_attention', 'custom_items', 'win_back', 'transfers_arriving'];
  $cards  = collect($attention['cards'] ?? [])->filter(fn ($c) => (int) ($c['count'] ?? 0) > 0);
  $groups = [
      'late'  => ['Late',         $cards->filter(fn ($c) => in_array($c['key'] ?? '', $lateKeys, true))],
      'today' => ['Today',        $cards->reject(fn ($c) => in_array($c['key'] ?? '', array_merge($lateKeys, $laterKeys), true))],
      'later' => ['When you can', $cards->filter(fn ($c) => in_array($c['key'] ?? '', $laterKeys, true))],
  ];
  $lateN  = (int) $groups['late'][1]->sum('count');
  $todayN = (int) $groups['today'][1]->sum('count');

  // ---- the summary line ----
  $hello = 'Good ' . $greeting['time_of_day'] . ($greeting['name'] ? ', ' . $greeting['name'] : '') . '.';
  $booked = $today['today_count'] ?? 0;
  if ($booked > 0) {
      $dayLine = $booked . ' ' . \Illuminate\Support\Str::plural('booking', $booked) . ' today.';
  } elseif (! empty($today['next_day'])) {
      $dayLine = 'Nothing is booked until ' . ($today['next_day']->isTomorrow() ? 'tomorrow' : $today['next_day']->format('l')) . '.';
  } else {
      $dayLine = 'Nothing is booked in the next 30 days.';
  }

  // ---- setup: one line while unfinished ----
  $steps = [
      'services' => ['Add your services', route('tenant.services.index')],
      'hours'    => ['Set your hours', route('tenant.capacity.index')],
      'branding' => ['Add your logo and colours', route('tenant.settings.index')],
  ];
  $done = collect($steps)->keys()->filter(fn ($k) => ! empty($progress[$k]))->count();
  $nextStep = collect($steps)->first(fn ($v, $k) => empty($progress[$k]));

  // ---- week: open on today if it has bookings, else the first day that does ----
  $days = $week['days'];
  $sel = 0;
  if (empty($days[0]['bookings']) && empty($days[0]['untimed'])) {
      foreach ($days as $i => $d) { if ($d['bookings'] || $d['untimed']) { $sel = $i; break; } }
  }
  $H0 = $week['start_hour']; $H1 = $week['end_hour']; $HR = 44;
  $fmt = function (float $h): string {
      $H = (int) floor($h); $M = (int) round(($h - $H) * 60);
      if ($M === 60) { $H++; $M = 0; }
      return ((($H + 11) % 12) + 1) . ($M ? ':' . str_pad((string) $M, 2, '0', STR_PAD_LEFT) : '') . ($H < 12 ? 'a' : 'p');
  };
  $newUrl = route('tenant.appointments.index', ['new' => 1]);

  // ---- last 30 days ----
  $spark = function (array $vals): string {
      $vals = array_values($vals);
      $n = count($vals);
      if ($n < 2 || max($vals) == min($vals)) return '<svg viewBox="0 0 110 28" aria-hidden="true"></svg>';
      $avg = [];
      for ($i = 0; $i < $n; $i++) { $w = array_slice($vals, max(0, $i - 3), min(4, $i + 1)); $avg[] = array_sum($w) / count($w); }
      $mn = min($avg); $mx = max($avg); $pts = [];
      foreach ($avg as $i => $v) { $pts[] = round($i * 110 / ($n - 1), 1) . ',' . round(25 - ($mx > $mn ? ($v - $mn) / ($mx - $mn) : .5) * 22, 1); }
      $last = explode(',', end($pts));
      return '<svg viewBox="0 0 110 28" aria-hidden="true"><path d="M' . implode(' L', $pts) . '" fill="none" stroke="currentColor" stroke-opacity=".75" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/><circle cx="' . $last[0] . '" cy="' . $last[1] . '" r="2.2" fill="currentColor"/></svg>';
  };
  $delta = function ($pct) {
      if ($pct === null) return '';
      return '<small class="' . ($pct >= 0 ? 'up' : 'down') . '">' . ($pct >= 0 ? '▲ ' : '▼ ') . abs($pct) . '% vs the 30 days before</small>';
  };
@endphp

<div class="mb">

  <section class="mb-head {{ tenant()->retail_enabled ? 'has-fab' : '' }}">
    <div style="display:flex;align-items:center;gap:12px">
      <div class="mb-eyebrow">{{ $greeting['date_long'] }}</div>
      <div class="ia-viewseg" style="margin-left:auto">
        <form method="POST" action="{{ route('tenant.dashboard.view') }}">
          @csrf<input type="hidden" name="view" value="overview">
          <button type="submit" title="Overview" aria-label="Overview"><svg class="vs-ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3" y="5" width="14" height="14" rx="2"/><path d="M21 7v10"/></svg><span class="vs-lb">Overview</span></button>
        </form>
        <form method="POST" action="{{ route('tenant.dashboard.view') }}">
          @csrf<input type="hidden" name="view" value="tiles">
          <button type="submit" title="Tiles" aria-label="Tiles"><svg class="vs-ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg><span class="vs-lb">Tiles</span></button>
        </form>
        <button type="button" class="on" title="Brief (beta)" aria-label="Brief (beta)"><svg class="vs-ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h10M4 18h13"/></svg><span class="vs-lb">Brief <span style="font-size:9.5px;font-weight:700;letter-spacing:.06em;opacity:.7">BETA</span></span></button>
      </div>
    </div>

    <h1 class="mb-h1">{{ $hello }}</h1>
    <p class="mb-sum">
      @if ($lateN || $todayN)
        @if ($lateN)<b class="late">{{ $lateN }} {{ \Illuminate\Support\Str::plural('thing', $lateN) }} {{ $lateN === 1 ? 'is' : 'are' }} late</b>@endif
        @if ($lateN && $todayN) and @endif
        @if ($todayN)<b>{{ $todayN }} {{ $lateN ? '' : \Illuminate\Support\Str::plural('thing', $todayN) . ' ' }}{{ $todayN === 1 ? 'is' : 'are' }} due today</b>@endif.
      @else
        <b>All caught up.</b>
      @endif
      {{ $dayLine }}
    </p>
    <div class="mb-acts">
      <a href="{{ $newUrl }}" class="ia-btn ia-btn--primary">New appointment</a>
      @if (tenant()->retail_enabled)
        <a href="{{ route('tenant.register.index') }}" class="ia-btn">Open register</a>
      @endif
    </div>

    @if (empty($progress['all_done']) && $nextStep)
      <div class="mb-setup">
        <span class="k">Finish setup</span>
        <span class="bar"><i style="width:{{ round($done / 3 * 100) }}%"></i></span>
        <span class="n">{{ $done }} of 3 done</span>
        <a href="{{ $nextStep[1] }}">{{ $nextStep[0] }} →</a>
      </div>
    @endif
  </section>

  <div class="mb-cols">

    {{-- QUEUE: each row is a count that opens its own filtered list --}}
    <section>
      <div class="mb-h2row"><h2 class="mb-h2">The queue</h2><span class="meta">Most urgent first · each row opens its list</span></div>
      @if ($cards->isEmpty())
        <div class="mb-calm">Nothing needs you right now.</div>
      @endif
      @foreach ($groups as $gk => [$label, $rows])
        @continue($rows->isEmpty())
        @php $sum = (int) $rows->sum('count'); @endphp
        <div class="mb-grp">
          <div class="mb-grp-h {{ $gk }}"><i></i>{{ $label }}<span class="c">{{ $sum }} {{ \Illuminate\Support\Str::plural('thing', $sum) }}</span></div>
          @foreach ($rows as $c)
            <a class="mb-row" href="{{ $c['link'] }}">
              <span class="n">{{ $c['count'] }}</span>
              <span><div class="t">{{ $c['title'] }}</div><div class="s">{{ $c['desc'] }}</div></span>
              <span class="arr">›</span>
            </a>
          @endforeach
        </div>
      @endforeach
    </section>

    {{-- WEEK + LAST 30 DAYS --}}
    <section>
      <div class="mb-h2row"><h2 class="mb-h2">This week</h2><span class="meta">{{ $week['total'] }} booked</span></div>

      <div class="mb-days" role="tablist">
        @foreach ($days as $i => $d)
          <button type="button" class="mb-day {{ $i === $sel ? 'on' : '' }} {{ $d['closed'] ? 'off' : '' }}" data-mb-day="{{ $i }}" role="tab">
            <div class="w">{{ $d['dow'] }}</div><div class="d">{{ $d['num'] }}</div>
            <div class="dots">@if ($d['closed'])closed @else @for ($k = 0; $k < min(4, count($d['bookings']) + count($d['untimed'])); $k++)<i></i>@endfor @endif</div>
          </button>
        @endforeach
      </div>

      @foreach ($days as $i => $d)
        @php $n = count($d['bookings']) + count($d['untimed']); @endphp
        <div class="mb-daypanel" data-mb-panel="{{ $i }}" @if ($i !== $sel) hidden @endif>
          <div class="mb-dayhead"><b>{{ $d['name'] }}</b><span>{{ $d['closed'] ? 'Closed' : ($n ? $n . ' booked' : '') }}</span></div>
          @if ($d['untimed'])
            <div class="mb-untimed">No time set:
              @foreach ($d['untimed'] as $u)<a href="{{ $u['link'] }}">{{ $u['who'] }}</a>{{ $loop->last ? '' : ', ' }}@endforeach
            </div>
          @endif
          <div class="mb-tl" style="--mb-hr:{{ $HR }}px">
            @for ($h = $H0; $h < $H1; $h++)<div class="hr"><span>{{ $fmt($h) }}</span></div>@endfor
            <div class="lane">
              @if ($d['closed'])
                <div class="closed">Closed</div>
              @else
                @php
                  $t = max($H0, $d['open'] ?? $H0);
                  $dayEnd = min($H1, $d['close'] ?? $H1);
                  $gaps = [];
                  foreach ($d['bookings'] as $b) { if ($b['at'] - $t >= 1) $gaps[] = [$t, $b['at']]; $t = max($t, $b['at'] + $b['len']); }
                  if ($dayEnd - $t >= 1) $gaps[] = [$t, $dayEnd];
                @endphp
                @foreach ($gaps as [$g0, $g1])
                  <a class="open" href="{{ $newUrl }}" style="top:{{ ($g0 - $H0) * $HR + 3 }}px;height:{{ ($g1 - $g0) * $HR - 6 }}px"><span>Open · {{ $fmt($g0) }}–{{ $fmt($g1) }}</span><b>+ Book</b></a>
                @endforeach
                @foreach ($d['bookings'] as $b)
                  <a class="bk {{ $b['awaiting'] ? 'pend' : 'ok' }}" href="{{ $b['link'] }}" style="top:{{ ($b['at'] - $H0) * $HR + 2 }}px;height:{{ max(28, $b['len'] * $HR - 4) }}px">
                    <div><span class="who">{{ $b['who'] }}</span><span class="tm">{{ $fmt($b['at']) }}–{{ $fmt($b['at'] + $b['len']) }} · {{ $b['what'] }}</span></div>
                    <div class="side">{{ $b['awaiting'] ? 'Confirm →' : 'Confirmed' }}</div>
                  </a>
                @endforeach
              @endif
              @if ($d['is_today'] && $week['now_hour'] >= $H0 && $week['now_hour'] <= $H1)
                <div class="now" style="top:{{ ($week['now_hour'] - $H0) * $HR }}px"><span>Now {{ $fmt($week['now_hour']) }}</span></div>
              @endif
            </div>
          </div>
        </div>
      @endforeach
      <div class="mb-legend"><span><i class="a"></i>Confirmed</span><span><i class="b"></i>Waiting on you</span><span><i class="c"></i>Open: click to book</span></div>

      <div class="mb-stats">
        <div class="mb-h2row"><h2 class="mb-h2">Last 30 days</h2></div>
        <div class="mb-st"><span class="k">Sales{!! $delta($growth['revenue']['delta_pct'] ?? null) !!}</span>{!! $spark($growth['revenue']['sparkline'] ?? []) !!}<span class="v">{{ format_money($growth['revenue']['current_cents'] ?? 0) }}</span></div>
        <div class="mb-st"><span class="k">Jobs completed{!! $delta($jobs['delta_pct'] ?? null) !!}</span><svg viewBox="0 0 110 28" aria-hidden="true"></svg><span class="v">{{ number_format($jobs['current'] ?? 0) }}</span></div>
        <div class="mb-st"><span class="k">New customers{!! $delta($growth['customers']['delta_pct'] ?? null) !!}</span>{!! $spark($growth['customers']['sparkline'] ?? []) !!}<span class="v">{{ number_format($growth['customers']['current'] ?? 0) }}</span></div>
        <div class="mb-note">Sales are payments received. The line is a 4-day average across the 30 days; comparisons appear once there's a month before to compare with.</div>
      </div>
    </section>
  </div>
</div>

<script>
  document.querySelectorAll('[data-mb-day]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-mb-day]').forEach(function (x) { x.classList.toggle('on', x === b); });
      document.querySelectorAll('[data-mb-panel]').forEach(function (p) { p.hidden = p.dataset.mbPanel !== b.dataset.mbDay; });
    });
  });
</script>
@endsection
