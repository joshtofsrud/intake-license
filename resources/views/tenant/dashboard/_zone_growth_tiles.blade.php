{{-- MARKER-PATCH-110-STEP-7 - Growth tiles: revenue, customers, health. --}}

@php
  $sparkline = function(array $series, int $width = 240, int $height = 36) {
      $n = count($series);
      if ($n < 2) return '';
      $min = min($series);
      $max = max($series);
      $range = max(1, $max - $min);
      $xStep = $width / max(1, $n - 1);
      $points = [];
      foreach ($series as $i => $v) {
          $x = round($i * $xStep, 2);
          $y = round($height - (($v - $min) / $range) * $height * 0.85 - 3, 2);
          $points[] = "$x,$y";
      }
      $pathD = 'M ' . implode(' L ', $points);
      $fillD = $pathD . ' L ' . ($width) . ',' . $height . ' L 0,' . $height . ' Z';
      return '<svg viewBox="0 0 ' . $width . ' ' . $height . '" class="ia-dash-tile-spark" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">'
           . '<path d="' . $fillD . '" fill="var(--ia-accent)" opacity="0.12"/>'
           . '<path d="' . $pathD . '" stroke="var(--ia-accent)" stroke-width="1.5" fill="none" stroke-linejoin="round" stroke-linecap="round"/>'
           . '</svg>';
  };

  $rev = $growth['revenue'];
  $cust = $growth['customers'];

  $revDeltaText = $rev['delta_pct'] !== null
      ? (($rev['delta_pct'] >= 0 ? '▲ ' : '▼ ') . abs($rev['delta_pct']) . '%')
      : null;
  $revDeltaClass = $rev['delta_pct'] !== null && $rev['delta_pct'] >= 0 ? 'up' : 'down';

  $custDeltaText = $cust['delta_pct'] !== null
      ? (($cust['delta_pct'] >= 0 ? '▲ ' : '▼ ') . abs($cust['delta_pct']) . '%')
      : null;
  $custDeltaClass = $cust['delta_pct'] !== null && $cust['delta_pct'] >= 0 ? 'up' : 'down';
@endphp

<div class="ia-dash-growth-tiles-block">
  <div class="ia-dash-tiles-zone-label">
    <span>How the business is doing</span>
    <span class="hint">last 30 days vs. prior 30 · tap for full report</span>
  </div>

  <div class="ia-dash-growth-tiles-grid">

    {{-- MARKER-PATCH-114 - tile links now apply matching filters --}}
    <a href="{{ route('tenant.reports.index', ['range' => 'last_30']) }}" class="ia-dash-growth-tile">
      <div class="label">Revenue · last 30d</div>
      <div class="value">{{ format_money($rev['current_cents']) }}</div>
      @if($revDeltaText)
        <div class="delta {{ $revDeltaClass }}">{{ $revDeltaText }}<span class="vs">vs {{ format_money($rev['prior_cents']) }}</span></div>
      @else
        <div class="delta flat">— <span class="vs">no prior period</span></div>
      @endif
      <div class="spark-wrap">
        {!! $sparkline($rev['sparkline']) !!}
      </div>
    </a>

    <a href="{{ route('tenant.customers.index', ['created_after' => \Carbon\Carbon::now()->subDays(29)->toDateString(), 'sort' => 'added_desc']) }}" class="ia-dash-growth-tile">
      <div class="label">New customers · last 30d</div>
      <div class="value">{{ $cust['current'] }}</div>
      @if($custDeltaText)
        <div class="delta {{ $custDeltaClass }}">{{ $custDeltaText }}<span class="vs">vs {{ $cust['prior'] }}</span></div>
      @else
        <div class="delta flat">— <span class="vs">no prior period</span></div>
      @endif
      <div class="spark-wrap">
        {!! $sparkline($cust['sparkline']) !!}
      </div>
    </a>

    <div class="ia-dash-growth-tile ia-dash-growth-tile--health">
      <div class="label">Operational health</div>
      <ul class="health-list">
        @foreach($growth['health'] as $item)
          <li class="health-row">
            <span class="dot dot--{{ $item['status'] }}"></span>
            <span class="health-label">{{ $item['label'] }}</span>
            <span class="health-detail">{{ $item['detail'] }}</span>
          </li>
        @endforeach
      </ul>
    </div>

  </div>
</div>
