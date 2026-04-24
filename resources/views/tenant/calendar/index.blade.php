@extends('layouts.tenant.app')
@php
  $pageTitle = 'Calendar';

  // Grid math, done once at render time.
  // pixelsPerMinute controls how tall the grid is. 1.4px/min = 42px per 30-min slot,
  // which reads cleanly at desktop widths without being cramped.
  $pxPerMin    = 1.4;
  $rangeMin    = $closeMin - $openMin;
  $gridHeight  = (int) ceil($rangeMin * $pxPerMin);

  // Group appointments by resource for column-based rendering.
  $apptsByResource = $appointments->groupBy('resource_id');

  // Helper: minutes-since-midnight from a H:i:s time string.
  $timeToMin = function ($hms) {
      if (!$hms) return null;
      $parts = explode(':', $hms);
      return ((int) ($parts[0] ?? 0)) * 60 + ((int) ($parts[1] ?? 0));
  };

  // Hour labels for the time axis (every full hour in the visible range).
  $hourLabels = [];
  for ($m = (int) (ceil($openMin / 60) * 60); $m < $closeMin; $m += 60) {
      $h = intdiv($m, 60);
      $hourLabels[] = [
          'min'   => $m,
          'label' => $h === 0 ? '12 AM' : ($h === 12 ? '12 PM' : ($h < 12 ? $h . ' AM' : ($h - 12) . ' PM')),
          'top'   => (int) round(($m - $openMin) * $pxPerMin),
      ];
  }
@endphp

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Calendar</h1>
    <p class="ia-page-subtitle">Your schedule, your shop, one view.</p>
  </div>
</div>

<div class="ia-cal-shell"
     data-cal-open-min="{{ $openMin }}"
     data-cal-close-min="{{ $closeMin }}"
     data-cal-px-per-min="{{ $pxPerMin }}"
     data-cal-is-today="{{ $isToday ? '1' : '0' }}">

  {{-- =========================================================
       Toolbar
       ========================================================= --}}
  <div class="ia-cal-toolbar">
    <div class="ia-cal-toolbar-left">
      <a href="{{ route('calendar.index', ['date' => $todayStr]) }}"
         class="ia-cal-today-btn {{ $isToday ? 'is-active' : '' }}">
        Today
      </a>
      <div class="ia-cal-nav-group">
        <a href="{{ route('calendar.index', ['date' => $prevDate]) }}"
           class="ia-cal-nav-btn" aria-label="Previous day">‹</a>
        <a href="{{ route('calendar.index', ['date' => $nextDate]) }}"
           class="ia-cal-nav-btn" aria-label="Next day">›</a>
      </div>
      <div class="ia-cal-date-label">
        {{ $date->format('l, F j') }}<span class="ia-cal-date-year">{{ $date->format('Y') }}</span>
      </div>
    </div>
    <div class="ia-cal-toolbar-right">
      <div class="ia-cal-view-switch">
        <button type="button" class="ia-cal-view-btn is-active" data-view="day">Day</button>
        <button type="button" class="ia-cal-view-btn is-disabled" data-view="week" disabled title="Coming soon">Week</button>
        <button type="button" class="ia-cal-view-btn is-disabled" data-view="month" disabled title="Coming soon">Month</button>
      </div>
    </div>
  </div>

  {{-- =========================================================
       Empty states
       ========================================================= --}}
  @if($resources->isEmpty())
    <div class="ia-cal-empty">
      <div class="ia-cal-empty-title">No resources yet</div>
      <div class="ia-cal-empty-body">
        Add at least one staff member or work station to start booking.
        @if(Route::has('resources.index'))
          <a href="{{ route('resources.index') }}">Add a resource →</a>
        @endif
      </div>
    </div>
  @elseif(!$hasRule)
    <div class="ia-cal-empty">
      <div class="ia-cal-empty-title">Closed on {{ $date->format('l') }}s</div>
      <div class="ia-cal-empty-body">
        Your business hours don't cover this day of the week.
        @if(Route::has('capacity.index'))
          <a href="{{ route('capacity.index') }}">Update business hours →</a>
        @endif
      </div>
    </div>
  @else

  {{-- =========================================================
       Grid body
       ========================================================= --}}
  <div class="ia-cal-body">

    {{-- Resource header row --}}
    <div class="ia-cal-resource-headers"
         style="grid-template-columns: 56px repeat({{ $resources->count() }}, 1fr);">
      <div class="ia-cal-time-col-head"></div>
      @foreach($resources as $resource)
        <div class="ia-cal-resource-head">
          <span class="ia-cal-resource-dot"
                style="background: {{ $resource->color_hex ?: '#888' }};"></span>
          <span class="ia-cal-resource-name">{{ $resource->name }}</span>
          @if($resource->subtitle)
            <span class="ia-cal-resource-sub">· {{ $resource->subtitle }}</span>
          @endif
        </div>
      @endforeach
    </div>

    {{-- Grid with time axis + resource columns --}}
    <div class="ia-cal-grid"
         style="grid-template-columns: 56px repeat({{ $resources->count() }}, 1fr); height: {{ $gridHeight }}px;">

      {{-- Time axis column --}}
      <div class="ia-cal-time-col" style="height: {{ $gridHeight }}px;">
        @foreach($hourLabels as $hl)
          <div class="ia-cal-hour-label" style="top: {{ $hl['top'] }}px;">
            {{ $hl['label'] }}
          </div>
        @endforeach
      </div>

      {{-- Resource columns --}}
      @foreach($resources as $resource)
        @php
          $colAppts  = $apptsByResource->get($resource->id, collect());
          // Shop-wide breaks (resource_id = null) appear on every column.
          $colBreaks = collect($breakWindows)->filter(fn($b) =>
              $b['resource_id'] === null || $b['resource_id'] === $resource->id
          );
          $colHolds = collect($holdWindows)->filter(fn($h) =>
              $h['resource_id'] === $resource->id
          );
        @endphp

        <div class="ia-cal-resource-col"
             style="height: {{ $gridHeight }}px;"
             data-resource-id="{{ $resource->id }}">

          {{-- Hour grid lines (visual rhythm behind events) --}}
          @foreach($hourLabels as $hl)
            <div class="ia-cal-hour-line" style="top: {{ $hl['top'] }}px;"></div>
          @endforeach

          {{-- Breaks (hatched neutral overlay) --}}
          @foreach($colBreaks as $br)
            @php
              $top    = (int) round(($br['starts_min'] - $openMin) * $pxPerMin);
              $height = (int) round(($br['ends_min'] - $br['starts_min']) * $pxPerMin);
            @endphp
            <div class="ia-cal-break"
                 style="top: {{ $top }}px; height: {{ $height }}px;"
                 title="{{ $br['label'] ?: 'Break' }}">
              <span class="ia-cal-break-label">{{ $br['label'] ?: 'Break' }}</span>
            </div>
          @endforeach

          {{-- Walk-in holds (lime dashed) --}}
          @foreach($colHolds as $hold)
            @php
              $top    = (int) round(($hold['starts_min'] - $openMin) * $pxPerMin);
              $height = (int) round(($hold['ends_min'] - $hold['starts_min']) * $pxPerMin);
            @endphp
            <div class="ia-cal-hold"
                 style="top: {{ $top }}px; height: {{ $height }}px;"
                 title="Walk-in hold{{ $hold['label'] ? ' — ' . $hold['label'] : '' }}">
              <span class="ia-cal-hold-label">— Walk-in hold —</span>
            </div>
          @endforeach

          {{-- Appointments (with bookend wrappers) --}}
          @foreach($colAppts as $appt)
            @php
              $apptMin     = $timeToMin($appt->appointment_time);
              $prepMin     = (int) ($appt->prep_before_minutes_snapshot ?? 0);
              $cleanMin    = (int) ($appt->cleanup_after_minutes_snapshot ?? 0);
              $durMin      = (int) $appt->total_duration_minutes;
              // Core duration = total_duration minus prep + cleanup. This is
              // what the customer "occupies" visually. Total_duration already
              // includes prep + cleanup per createAppointment logic, so we
              // subtract them back out for the core.
              $coreMin     = max(0, $durMin - $prepMin - $cleanMin);

              $prepTop     = (int) round(($apptMin - $prepMin - $openMin) * $pxPerMin);
              $prepHeight  = (int) round($prepMin * $pxPerMin);
              $coreTop     = (int) round(($apptMin - $openMin) * $pxPerMin);
              $coreHeight  = (int) round($coreMin * $pxPerMin);
              $cleanTop    = $coreTop + $coreHeight;
              $cleanHeight = (int) round($cleanMin * $pxPerMin);

              $customerName = trim(($appt->customer_first_name ?? '') . ' ' . ($appt->customer_last_name ?? ''));
              $serviceName  = optional($appt->items->first())->item_name_snapshot ?? '';
              $resourceColor = $resource->color_hex ?: '#888';

              // Display times
              $startH = intdiv($apptMin, 60);
              $startM = $apptMin % 60;
              $endMin = $apptMin + $coreMin;
              $endH   = intdiv($endMin, 60);
              $endM   = $endMin % 60;
              $timeRange = sprintf(
                  '%d:%02d %s – %d:%02d %s',
                  $startH === 0 ? 12 : ($startH > 12 ? $startH - 12 : $startH), $startM, $startH < 12 ? 'am' : 'pm',
                  $endH   === 0 ? 12 : ($endH   > 12 ? $endH   - 12 : $endH),   $endM,   $endH   < 12 ? 'am' : 'pm'
              );
            @endphp

            @if($prepMin > 0)
              <div class="ia-cal-bookend is-prep"
                   style="top: {{ $prepTop }}px; height: {{ $prepHeight }}px;">
                ↓ {{ $prepMin }}m prep
              </div>
            @endif

            <div class="ia-cal-appt"
                 style="top: {{ $coreTop }}px;
                        height: {{ $coreHeight }}px;
                        border-left-color: {{ $resourceColor }};
                        background: {{ $resourceColor }}1a;"
                 data-appt-id="{{ $appt->id }}">
              <div class="ia-cal-appt-name">{{ $customerName ?: 'Appointment' }}</div>
              @if($serviceName)
                <div class="ia-cal-appt-svc">{{ $serviceName }}</div>
              @endif
              <div class="ia-cal-appt-time">{{ $timeRange }}</div>
            </div>

            @if($cleanMin > 0)
              <div class="ia-cal-bookend is-clean"
                   style="top: {{ $cleanTop }}px; height: {{ $cleanHeight }}px;">
                ↑ {{ $cleanMin }}m clean
              </div>
            @endif
          @endforeach

        </div>
      @endforeach

      {{-- Now-line (only visible on today's view) --}}
      @if($isToday)
        <div class="ia-cal-now-line"
             id="ia-cal-now-line"
             style="grid-column: 2 / -1; top: 0; display: none;">
          <span class="ia-cal-now-label" id="ia-cal-now-label">—</span>
        </div>
      @endif

    </div>

  </div>
  @endif

</div>

@endsection

@push('styles')
  <link rel="stylesheet" href="{{ asset('css/tenant/calendar.css') }}">
@endpush

@push('scripts')
  <script src="{{ asset('js/tenant/calendar.js') }}" defer></script>
@endpush
