{{-- Block: last 30 days (Brief). --}}
@php
  $spark = fn (array $vals): string => \App\Support\Dashboard\BriefView::spark($vals);
  $delta = fn ($pct): string => \App\Support\Dashboard\BriefView::delta($pct);
@endphp
      <div class="mb-stats">
        <div class="mb-h2row"><h2 class="mb-h2">Last 30 days</h2></div>
        <div class="mb-st"><span class="k">Sales{!! $delta($growth['revenue']['delta_pct'] ?? null) !!}</span>{!! $spark($growth['revenue']['sparkline'] ?? []) !!}<span class="v">{{ format_money($growth['revenue']['current_cents'] ?? 0) }}</span></div>
        <div class="mb-st"><span class="k">Jobs completed{!! $delta($jobs['delta_pct'] ?? null) !!}</span><svg viewBox="0 0 110 28" aria-hidden="true"></svg><span class="v">{{ number_format($jobs['current'] ?? 0) }}</span></div>
        <div class="mb-st"><span class="k">New customers{!! $delta($growth['customers']['delta_pct'] ?? null) !!}</span>{!! $spark($growth['customers']['sparkline'] ?? []) !!}<span class="v">{{ number_format($growth['customers']['current'] ?? 0) }}</span></div>
        <div class="mb-note">Sales are payments received. The line is a 4-day average across the 30 days; comparisons appear once there's a month before to compare with.</div>
      </div>
