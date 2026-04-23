@php
  $sparkline = function(array $series, int $width = 260, int $height = 48) {
      $n = count($series);
      if ($n < 2) return '';
      $min = min($series);
      $max = max($series);
      $range = max(1, $max - $min);

      $xStep = $width / max(1, $n - 1);
      $points = [];
      foreach ($series as $i => $v) {
          $x = round($i * $xStep, 2);
          $y = round($height - (($v - $min) / $range) * $height * 0.85 - 4, 2);
          $points[] = "$x,$y";
      }
      $pathD = 'M ' . implode(' L ', $points);
      $fillD = $pathD . ' L ' . ($width) . ',' . $height . ' L 0,' . $height . ' Z';
      return '<svg viewBox="0 0 ' . $width . ' ' . $height . '" class="ia-dash-spark" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">'
           . '<path d="' . $fillD . '" fill="var(--ia-accent)" opacity="0.12"/>'
           . '<path d="' . $pathD . '" stroke="var(--ia-accent)" stroke-width="1.5" fill="none" stroke-linejoin="round" stroke-linecap="round"/>'
           . '</svg>';
  };

  $revDeltaText = $growth['revenue']['delta_pct'] !== null
      ? (($growth['revenue']['delta_pct'] >= 0 ? '▲ ' : '▼ ') . abs($growth['revenue']['delta_pct']) . '%')
      : null;
  $revDeltaClass = $growth['revenue']['delta_pct'] !== null && $growth['revenue']['delta_pct'] >= 0 ? 'up' : 'down';

  $custDeltaText = $growth['customers']['delta_pct'] !== null
      ? (($growth['customers']['delta_pct'] >= 0 ? '▲ ' : '▼ ') . abs($growth['customers']['delta_pct']) . '%')
      : null;
  $custDeltaClass = $growth['customers']['delta_pct'] !== null && $growth['customers']['delta_pct'] >= 0 ? 'up' : 'down';
@endphp

<div class="ia-dash-growth-block">
  <div class="ia-dash-zone-head">
    <span class="ia-card-title">Growth · last 30 days</span>
  </div>

  <div class="ia-dash-growth-grid">
    <div class="ia-dash-growth-card">
      <div class="ia-dash-growth-head">
        <span class="ia-dash-growth-label">Revenue</span>
        @if($revDeltaText)
          <span class="ia-dash-growth-delta {{ $revDeltaClass }}">{{ $revDeltaText }}</span>
        @endif
      </div>
      <div class="ia-dash-growth-value">{{ format_money($growth['revenue']['current_cents']) }}</div>
      <div class="ia-dash-growth-sub">
        vs {{ format_money($growth['revenue']['prior_cents']) }} previous 30 days
      </div>
      <div class="ia-dash-spark-wrap">
        {!! $sparkline($growth['revenue']['sparkline']) !!}
      </div>
    </div>

    <div class="ia-dash-growth-card">
      <div class="ia-dash-growth-head">
        <span class="ia-dash-growth-label">New customers</span>
        @if($custDeltaText)
          <span class="ia-dash-growth-delta {{ $custDeltaClass }}">{{ $custDeltaText }}</span>
        @endif
      </div>
      <div class="ia-dash-growth-value">{{ $growth['customers']['current'] }}</div>
      <div class="ia-dash-growth-sub">
        vs {{ $growth['customers']['prior'] }} previous 30 days
      </div>
      <div class="ia-dash-spark-wrap">
        {!! $sparkline($growth['customers']['sparkline']) !!}
      </div>
    </div>
  </div>

  <div class="ia-dash-health">
    <div class="ia-dash-zone-head">
      <span class="ia-card-title">Operational health</span>
    </div>
    <ul class="ia-dash-health-list">
      @foreach($growth['health'] as $item)
        <li class="ia-dash-health-item">
          <span class="ia-dash-health-dot ia-dash-health-dot--{{ $item['status'] }}"></span>
          <span class="ia-dash-health-label"><strong>{{ $item['label'] }}</strong> — {{ $item['detail'] }}</span>
        </li>
      @endforeach
    </ul>
  </div>
</div>
