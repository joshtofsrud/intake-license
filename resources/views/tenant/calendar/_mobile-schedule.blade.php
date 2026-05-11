{{-- ================================================================
     Mobile schedule view (≤900px only) — MOBILE-SCHED-FILTER-GAPS v1
     Renders day-list or week-grouped-list using the same controller data
     as the desktop calendar. Hidden via CSS on desktop.

     Features:
       - Resource filter chip row (anchor links → ?resources= param)
       - Day mode: vertical list, resource-color stripe per appointment
       - Day mode: GAP ROWS rendered between appointments when ONE resource
         is filtered (gaps ≥ 15 min only)
       - Week mode: grouped-by-day denser list (no gap rendering)
       - Tap any appointment row → appointment detail page
     ================================================================ --}}
@php
  use Carbon\Carbon as Cb;

  // Anchor date logic.
  if ($viewMode === 'day') {
    $msAnchorDateStr = $dateStr;
    $msAppointments  = $appointments;
  } elseif ($viewMode === 'week') {
    $msAnchorDateStr = ($todayStr >= $weekStartStr && $todayStr <= $weekEndStr) ? $todayStr : $weekStartStr;
    $msAppointments = collect();
    foreach (($byResourceDate ?? []) as $rid => $byDate) {
      foreach ($byDate as $ds => $appts) {
        if ($ds === $msAnchorDateStr) {
          foreach ($appts as $a) $msAppointments->push($a);
        }
      }
    }
    $msAppointments = $msAppointments->sortBy('appointment_time')->values();
  } else {
    $msAnchorDateStr = $todayStr;
    $msAppointments  = collect();
  }

  $msAnchorDate = Cb::parse($msAnchorDateStr);

  // 7-day strip
  $msStripStart = $msAnchorDate->copy()->subDays(3);
  $msStripDays = [];
  for ($i = 0; $i < 7; $i++) {
    $d = $msStripStart->copy()->addDays($i);
    $msStripDays[] = [
      'date'      => $d->toDateString(),
      'dow'       => $d->format('D'),
      'num'       => (int) $d->format('j'),
      'is_today'  => $d->toDateString() === $todayStr,
      'is_anchor' => $d->toDateString() === $msAnchorDateStr,
    ];
  }

  // Resource color lookup. Use $allResources for the picker, $resources for
  // "currently visible" determination.
  $msResourceById = [];
  foreach (($allResources ?? $resources ?? []) as $r) {
    $msResourceById[$r->id] = $r;
  }
  $msVisibleResourceIds = ($resources ?? collect())->pluck('id')->all();

  // Filter mode detection. "Single resource filtered" trigger for gap rows.
  $msIsFiltered      = ($filterMode ?? 'all') !== 'all';
  $msSingleResource  = $msIsFiltered && count($msVisibleResourceIds) === 1
    ? ($msResourceById[$msVisibleResourceIds[0]] ?? null)
    : null;

  // Helpers
  $msFmtTime = function ($appt) {
    if (!$appt->appointment_time) return ['hm' => 'Any', 'ap' => 'time'];
    $c = Cb::parse($appt->appointment_time);
    return ['hm' => $c->format('g:i'), 'ap' => $c->format('A')];
  };
  $msStatusClass = function ($status) {
    return 'is-' . str_replace('_', '-', $status);
  };

  // Build links that preserve the current view + date but swap resources param.
  $msFilterLink = function ($resourceParam) use ($viewMode, $msAnchorDateStr) {
    return route('tenant.calendar.index', [
      'view'      => $viewMode === 'week' ? 'week' : 'day',
      'date'      => $msAnchorDateStr,
      'resources' => $resourceParam,
    ]);
  };

  // Gap calculation helper (day mode only).
  // Returns [minutesGap, gapStartTimeStr, gapEndTimeStr] or null if no
  // meaningful gap. End-of-prev = appointment_time + duration if end_time
  // not stored. Min threshold: 15 min.
  $msComputeGap = function ($prev, $curr) {
    if (!$prev || !$curr) return null;
    if (!$prev->appointment_time || !$curr->appointment_time) return null;
    try {
      $prevStart = Cb::parse($prev->appointment_date->toDateString() . ' ' . $prev->appointment_time);
      $prevDur   = (int) ($prev->total_duration_minutes ?? 0);
      $prevEnd   = $prev->appointment_end_time
        ? Cb::parse($prev->appointment_date->toDateString() . ' ' . $prev->appointment_end_time)
        : $prevStart->copy()->addMinutes($prevDur);
      $currStart = Cb::parse($curr->appointment_date->toDateString() . ' ' . $curr->appointment_time);
      // CARBON3-DIFF-FIX v1: timestamp math — Carbon 3's diffInMinutes(false)
      // returns negative when the argument is later than $this. Using raw
      // timestamps avoids version-specific sign behaviour.
      $gap = (int) round(($currStart->getTimestamp() - $prevEnd->getTimestamp()) / 60);
      if ($gap < 15) return null;
      return [
        'minutes' => $gap,
        'fromStr' => $prevEnd->format('g:i'),
        'toStr'   => $currStart->format('g:i'),
      ];
    } catch (\Throwable $e) {
      return null;
    }
  };

  // Format gap minutes as human-readable.
  $msFmtGap = function ($mins) {
    if ($mins < 60) return $mins . ' min';
    $h = intdiv($mins, 60);
    $m = $mins % 60;
    if ($m === 0) return $h . 'h';
    return $h . 'h ' . $m . ' min';
  };

  // Subtitle.
  if ($viewMode === 'week') {
    $msSubtitle = 'Week of ' . Cb::parse($weekStartStr)->format('M j');
  } elseif ($msAppointments->isEmpty()) {
    $msSubtitle = 'No appointments';
  } elseif ($msSingleResource) {
    $count = $msAppointments->count();
    $msSubtitle = $count . ' of ' . $msSingleResource->name . "'s appointments";
  } elseif ($msIsFiltered) {
    $count = $msAppointments->count();
    $resCount = count($msVisibleResourceIds);
    $msSubtitle = $count . ' appointments · ' . $resCount . ' ' . \Illuminate\Support\Str::plural('resource', $resCount);
  } else {
    $count = $msAppointments->count();
    $resCount = count(($allResources ?? []));
    $msSubtitle = $count . ' appointments · ' . $resCount . ' ' . \Illuminate\Support\Str::plural('resource', $resCount);
  }
@endphp

<div class="ia-msched">

  {{-- Header: title + mode toggle --}}
  <div class="ia-msched-head">
    <div class="ia-msched-title-block">
      <div class="ia-msched-title">{{ $msAnchorDate->format('l, M j') }}</div>
      <div class="ia-msched-sub">{{ $msSubtitle }}</div>
    </div>
    <div class="ia-msched-mode">
      <a href="{{ route('tenant.calendar.index', ['view' => 'day', 'date' => $msAnchorDateStr, 'resources' => $filterMode === 'all' ? null : implode(',', $msVisibleResourceIds)]) }}"
         class="ia-msched-mode-btn {{ $viewMode === 'day' ? 'is-active' : '' }}">Day</a>
      <a href="{{ route('tenant.calendar.index', ['view' => 'week', 'date' => $msAnchorDateStr, 'resources' => $filterMode === 'all' ? null : implode(',', $msVisibleResourceIds)]) }}"
         class="ia-msched-mode-btn {{ $viewMode === 'week' ? 'is-active' : '' }}">Week</a>
    </div>
  </div>

  {{-- Resource filter chips --}}
  @if(($allResources ?? collect())->count() > 1)
    <div class="ia-msched-resfilter" role="tablist" aria-label="Filter resources">
      <a href="{{ $msFilterLink('all') }}"
         class="ia-msched-resfilter-chip {{ $filterMode === 'all' ? 'is-active' : '' }}"
         role="tab" aria-selected="{{ $filterMode === 'all' ? 'true' : 'false' }}">
        All
      </a>
      @foreach($allResources as $r)
        @php
          $isActive = in_array($r->id, $msVisibleResourceIds) && $filterMode !== 'all';
          $rColor = $r->color_hex ?: '#888';
        @endphp
        <a href="{{ $msFilterLink($r->id) }}"
           class="ia-msched-resfilter-chip {{ $isActive ? 'is-active' : '' }}"
           role="tab" aria-selected="{{ $isActive ? 'true' : 'false' }}">
          <span class="ia-msched-resfilter-dot" style="background: {{ $rColor }};"></span>
          {{ $r->name }}
        </a>
      @endforeach
    </div>
  @endif

  {{-- 7-day strip --}}
  <div class="ia-msched-strip" role="tablist">
    @foreach($msStripDays as $sd)
      @php
        $stripParams = ['view' => $viewMode === 'week' ? 'week' : 'day', 'date' => $sd['date']];
        if ($filterMode !== 'all' && !empty($msVisibleResourceIds)) {
          $stripParams['resources'] = implode(',', $msVisibleResourceIds);
        }
      @endphp
      <a href="{{ route('tenant.calendar.index', $stripParams) }}"
         class="ia-msched-strip-chip {{ $sd['is_anchor'] ? 'is-active' : '' }} {{ $sd['is_today'] ? 'is-today' : '' }}"
         role="tab" aria-selected="{{ $sd['is_anchor'] ? 'true' : 'false' }}">
        <span class="ia-msched-strip-dow">{{ $sd['dow'] }}</span>
        <span class="ia-msched-strip-num">{{ $sd['num'] }}</span>
      </a>
    @endforeach
  </div>

  {{-- Prev / Today / Next quick nav --}}
  @php
    $prevParams = ['view' => 'day', 'date' => $msAnchorDate->copy()->subDay()->toDateString()];
    $nextParams = ['view' => 'day', 'date' => $msAnchorDate->copy()->addDay()->toDateString()];
    $todayParams = ['view' => $viewMode === 'week' ? 'week' : 'day', 'date' => $todayStr];
    foreach ([$prevParams, $nextParams, $todayParams] as $key => &$pp) {
      if ($filterMode !== 'all' && !empty($msVisibleResourceIds)) {
        $pp['resources'] = implode(',', $msVisibleResourceIds);
      }
    }
    unset($pp);
  @endphp
  <div class="ia-msched-nav">
    <a href="{{ route('tenant.calendar.index', $prevParams) }}" class="ia-msched-nav-btn">‹ Prev day</a>
    @unless($msAnchorDateStr === $todayStr)
      <a href="{{ route('tenant.calendar.index', $todayParams) }}" class="ia-msched-nav-btn is-today">Today</a>
    @endunless
    <a href="{{ route('tenant.calendar.index', $nextParams) }}" class="ia-msched-nav-btn">Next day ›</a>
  </div>

  {{-- ─── Body ─── --}}
  @if($viewMode === 'week')
    {{-- WEEK MODE: grouped-by-day list (no gap rendering) --}}
    @php
      $weekGroups = [];
      foreach (($days ?? []) as $day) {
        $ds = $day['dateStr'];
        $dayAppts = collect();
        foreach (($byResourceDate ?? []) as $rid => $byDate) {
          foreach (($byDate[$ds] ?? []) as $a) $dayAppts->push($a);
        }
        $weekGroups[] = [
          'dateStr'  => $ds,
          'date'     => $day['date'] ?? Cb::parse($ds),
          'is_today' => $ds === $todayStr,
          'appts'    => $dayAppts->sortBy('appointment_time')->values(),
        ];
      }
    @endphp

    @foreach($weekGroups as $g)
      <div class="ia-msched-day-group">
        <div class="ia-msched-day-header">
          <a href="{{ route('tenant.calendar.index', array_merge(['view' => 'day', 'date' => $g['dateStr']], $filterMode !== 'all' && !empty($msVisibleResourceIds) ? ['resources' => implode(',', $msVisibleResourceIds)] : [])) }}" class="ia-msched-day-header-link">
            @if($g['is_today'])
              <span class="ia-msched-day-name">Today</span>
              <span class="ia-msched-day-meta">{{ $g['date']->format('D · M j') }}</span>
            @else
              <span class="ia-msched-day-name">{{ $g['date']->format('l') }}</span>
              <span class="ia-msched-day-meta">{{ $g['date']->format('M j') }}</span>
            @endif
          </a>
          <span class="ia-msched-day-count">{{ $g['appts']->count() }} {{ \Illuminate\Support\Str::plural('appt', $g['appts']->count()) }}</span>
        </div>
        @if($g['appts']->isEmpty())
          <div class="ia-msched-empty-row">No appointments</div>
        @else
          @foreach($g['appts']->take(8) as $appt)
            @php
              $t = $msFmtTime($appt);
              $r = $msResourceById[$appt->resource_id] ?? null;
              $rColor = $r?->color_hex ?: '#888';
            @endphp
            <a href="{{ route('tenant.appointments.show', $appt->id) }}" class="ia-msched-row">
              <div class="ia-msched-row-time">
                <div class="ia-msched-row-time-hm">{{ $t['hm'] }}</div>
                <div class="ia-msched-row-time-ap">{{ $t['ap'] }}</div>
              </div>
              <div class="ia-msched-row-stripe" style="background:{{ $rColor }}"></div>
              <div class="ia-msched-row-body">
                <div class="ia-msched-row-cust">{{ trim(($appt->customer_first_name ?? '') . ' ' . ($appt->customer_last_name ?? '')) ?: 'Customer' }}</div>
                <div class="ia-msched-row-meta">
                  @if($r)
                    <span class="ia-msched-row-res">{{ $r->name }}</span>
                  @endif
                  @if($appt->items->isNotEmpty())
                    <span class="ia-msched-row-svc">· {{ $appt->items->first()->item_name_snapshot }}</span>
                  @endif
                </div>
              </div>
              <span class="ia-msched-row-status {{ $msStatusClass($appt->status) }}"></span>
            </a>
          @endforeach
          @if($g['appts']->count() > 8)
            <a href="{{ route('tenant.calendar.index', array_merge(['view' => 'day', 'date' => $g['dateStr']], $filterMode !== 'all' && !empty($msVisibleResourceIds) ? ['resources' => implode(',', $msVisibleResourceIds)] : [])) }}" class="ia-msched-more">
              + {{ $g['appts']->count() - 8 }} more — tap for day view
            </a>
          @endif
        @endif
      </div>
    @endforeach

  @else
    {{-- DAY MODE: vertical list, with gap rows when single resource is filtered --}}
    @if($msAppointments->isEmpty())
      <div class="ia-msched-empty">
        <div class="ia-msched-empty-h">Nothing on the books</div>
        <div class="ia-msched-empty-sub">
          @if($msSingleResource)
            {{ $msSingleResource->name }} has no appointments this day.
          @else
            Tap the + button to start a walk-in, or pick a different day above.
          @endif
        </div>
      </div>
    @else
      <div class="ia-msched-list">
        @php $msPrevAppt = null; @endphp
        @foreach($msAppointments as $appt)
          {{-- Render a gap row before this appointment if single-resource filter is active --}}
          @if($msSingleResource && $msPrevAppt)
            @php $gap = $msComputeGap($msPrevAppt, $appt); @endphp
            @if($gap)
              <div class="ia-msched-gap" aria-hidden="true">
                <div class="ia-msched-gap-time">
                  <div>{{ $gap['fromStr'] }}</div>
                  <div>{{ $gap['toStr'] }}</div>
                </div>
                <div class="ia-msched-gap-stripe"></div>
                <div class="ia-msched-gap-body">
                  <strong>{{ $msFmtGap($gap['minutes']) }}</strong> free
                </div>
              </div>
            @endif
          @endif

          @php
            $t = $msFmtTime($appt);
            $r = $msResourceById[$appt->resource_id] ?? null;
            $rColor = $r?->color_hex ?: '#888';
          @endphp
          <a href="{{ route('tenant.appointments.show', $appt->id) }}" class="ia-msched-row">
            <div class="ia-msched-row-time">
              <div class="ia-msched-row-time-hm">{{ $t['hm'] }}</div>
              <div class="ia-msched-row-time-ap">{{ $t['ap'] }}</div>
              @if($appt->total_duration_minutes)
                <div class="ia-msched-row-time-dur">{{ $appt->total_duration_minutes }}m</div>
              @endif
            </div>
            <div class="ia-msched-row-stripe" style="background:{{ $rColor }}"></div>
            <div class="ia-msched-row-body">
              <div class="ia-msched-row-cust">{{ trim(($appt->customer_first_name ?? '') . ' ' . ($appt->customer_last_name ?? '')) ?: 'Customer' }}</div>
              @if($appt->items->isNotEmpty())
                <div class="ia-msched-row-svc-main">{{ $appt->items->first()->item_name_snapshot }}</div>
              @endif
              <div class="ia-msched-row-meta">
                {{-- Hide resource name when filtered to one resource (it's redundant) --}}
                @if($r && !$msSingleResource)
                  <span class="ia-msched-row-res-dot" style="background:{{ $rColor }}"></span>
                  <span class="ia-msched-row-res">{{ $r->name }}</span>
                @endif
              </div>
            </div>
            <span class="ia-msched-row-status {{ $msStatusClass($appt->status) }}" aria-label="{{ ucfirst(str_replace('_', ' ', $appt->status)) }}"></span>
          </a>
          @php $msPrevAppt = $appt; @endphp
        @endforeach
      </div>
    @endif

  @endif

</div>
