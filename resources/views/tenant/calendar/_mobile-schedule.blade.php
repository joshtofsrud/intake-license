{{-- ================================================================
     Mobile schedule view (≤900px only)
     Renders day-list or week-grouped-list using the same controller
     data as the desktop calendar. Hidden via CSS on desktop.

     Day mode: vertical scroll, time column + body card per appointment,
               resource-color left border. Day-strip up top to jump days.
     Week mode: grouped-by-day list, denser rows.

     Single tap on any row navigates to the appointment detail page.
     ================================================================ --}}
@php
  use Carbon\Carbon as Cb;

  // Resolve "current date" for the mobile view's day-strip and header,
  // regardless of which mode the controller resolved to.
  if ($viewMode === 'day') {
    $msAnchorDateStr = $dateStr;
    $msAppointments  = $appointments;
  } elseif ($viewMode === 'week') {
    // In week mode controller doesn't pass a single "anchor day"; default to today
    // if it's inside the week, else weekStart.
    $msAnchorDateStr = ($todayStr >= $weekStartStr && $todayStr <= $weekEndStr) ? $todayStr : $weekStartStr;
    // Flatten byResourceDate to a single date-keyed map for the day view.
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
    // Month mode — just pick today.
    $msAnchorDateStr = $todayStr;
    $msAppointments  = collect();
  }

  $msAnchorDate = Cb::parse($msAnchorDateStr);

  // Build 7-day strip centered on anchor: anchor-3 ... anchor+3
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

  // Resource color lookup
  $msResourceById = [];
  foreach (($allResources ?? $resources ?? []) as $r) {
    $msResourceById[$r->id] = $r;
  }

  // Helpers
  $msFmtTime = function ($appt) {
    if (!$appt->appointment_time) return ['hm' => 'Any', 'ap' => 'time'];
    $c = Cb::parse($appt->appointment_time);
    return ['hm' => $c->format('g:i'), 'ap' => $c->format('A')];
  };
  $msStatusClass = function ($status) {
    return 'is-' . str_replace('_', '-', $status);
  };
@endphp

<div class="ia-msched">

  {{-- Header: title + mode toggle --}}
  <div class="ia-msched-head">
    <div class="ia-msched-title-block">
      <div class="ia-msched-title">{{ $msAnchorDate->format('l, M j') }}</div>
      <div class="ia-msched-sub">
        @if($viewMode === 'week')
          Week of {{ Cb::parse($weekStartStr)->format('M j') }}
        @elseif($msAppointments->isEmpty())
          No appointments
        @else
          {{ $msAppointments->count() }} {{ \Illuminate\Support\Str::plural('appointment', $msAppointments->count()) }}
        @endif
      </div>
    </div>
    <div class="ia-msched-mode">
      <a href="{{ route('tenant.calendar.index', ['view' => 'day', 'date' => $msAnchorDateStr]) }}"
         class="ia-msched-mode-btn {{ $viewMode === 'day' ? 'is-active' : '' }}">Day</a>
      <a href="{{ route('tenant.calendar.index', ['view' => 'week', 'date' => $msAnchorDateStr]) }}"
         class="ia-msched-mode-btn {{ $viewMode === 'week' ? 'is-active' : '' }}">Week</a>
    </div>
  </div>

  {{-- 7-day strip — always visible for quick jumps --}}
  <div class="ia-msched-strip" role="tablist">
    @foreach($msStripDays as $sd)
      <a href="{{ route('tenant.calendar.index', ['view' => $viewMode === 'week' ? 'week' : 'day', 'date' => $sd['date']]) }}"
         class="ia-msched-strip-chip {{ $sd['is_anchor'] ? 'is-active' : '' }} {{ $sd['is_today'] ? 'is-today' : '' }}"
         role="tab" aria-selected="{{ $sd['is_anchor'] ? 'true' : 'false' }}">
        <span class="ia-msched-strip-dow">{{ $sd['dow'] }}</span>
        <span class="ia-msched-strip-num">{{ $sd['num'] }}</span>
      </a>
    @endforeach
  </div>

  {{-- Prev / Next day shortcut buttons --}}
  <div class="ia-msched-nav">
    <a href="{{ route('tenant.calendar.index', ['view' => 'day', 'date' => $msAnchorDate->copy()->subDay()->toDateString()]) }}"
       class="ia-msched-nav-btn">‹ Prev day</a>
    @unless($msAnchorDateStr === $todayStr)
      <a href="{{ route('tenant.calendar.index', ['view' => $viewMode === 'week' ? 'week' : 'day', 'date' => $todayStr]) }}"
         class="ia-msched-nav-btn is-today">Today</a>
    @endunless
    <a href="{{ route('tenant.calendar.index', ['view' => 'day', 'date' => $msAnchorDate->copy()->addDay()->toDateString()]) }}"
       class="ia-msched-nav-btn">Next day ›</a>
  </div>

  {{-- ─── Mode-specific body ─── --}}
  @if($viewMode === 'week')
    {{-- WEEK MODE: grouped-by-day list --}}
    @php
      // Build day-grouped appointments. Iterate $days from controller for header order.
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
          <a href="{{ route('tenant.calendar.index', ['view' => 'day', 'date' => $g['dateStr']]) }}" class="ia-msched-day-header-link">
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
            <a href="{{ route('tenant.calendar.index', ['view' => 'day', 'date' => $g['dateStr']]) }}" class="ia-msched-more">
              + {{ $g['appts']->count() - 8 }} more — tap for day view
            </a>
          @endif
        @endif
      </div>
    @endforeach

  @else
    {{-- DAY MODE: vertical list for one day --}}
    @if($msAppointments->isEmpty())
      <div class="ia-msched-empty">
        <div class="ia-msched-empty-h">Nothing on the books</div>
        <div class="ia-msched-empty-sub">Tap the + button to start a walk-in, or pick a different day above.</div>
      </div>
    @else
      <div class="ia-msched-list">
        @foreach($msAppointments as $appt)
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
                @if($r)
                  <span class="ia-msched-row-res-dot" style="background:{{ $rColor }}"></span>
                  <span class="ia-msched-row-res">{{ $r->name }}</span>
                @endif
              </div>
            </div>
            <span class="ia-msched-row-status {{ $msStatusClass($appt->status) }}" aria-label="{{ ucfirst(str_replace('_', ' ', $appt->status)) }}"></span>
          </a>
        @endforeach
      </div>
    @endif

  @endif

</div>
