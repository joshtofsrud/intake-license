{{-- Day view grid. Time axis on the left, one column per resource.
     All grid math done at render time in PHP for static positioning. --}}
@php
  $pxPerMin    = 1.4;
  $rangeMin    = $closeMin - $openMin;
  $gridHeight  = (int) ceil($rangeMin * $pxPerMin);
  $apptsByResource = $appointments->groupBy('resource_id');

  $timeToMin = function ($hms) {
      if (!$hms) return null;
      $parts = explode(':', $hms);
      return ((int) ($parts[0] ?? 0)) * 60 + ((int) ($parts[1] ?? 0));
  };

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

@if($resources->isEmpty())
  <div class="ia-cal-empty">
    <div class="ia-cal-empty-title">No resources yet</div>
    <div class="ia-cal-empty-body">
      Add at least one staff member or work station to start booking.
      @if(Route::has('tenant.resources.index'))
        <a href="{{ route('tenant.resources.index') }}">Add a resource →</a>
      @endif
    </div>
  </div>
@elseif(!$hasRule)
  <div class="ia-cal-empty">
    <div class="ia-cal-empty-title">Closed on {{ $date->format('l') }}s</div>
    <div class="ia-cal-empty-body">
      Your business hours don't cover this day of the week.
      @if(Route::has('tenant.capacity.index'))
        <a href="{{ route('tenant.capacity.index') }}">Update business hours →</a>
      @endif
    </div>
  </div>
@else
<div class="ia-cal-body">

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

  <div class="ia-cal-grid"
       style="grid-template-columns: 56px repeat({{ $resources->count() }}, 1fr); height: {{ $gridHeight }}px;">

    <div class="ia-cal-time-col" style="height: {{ $gridHeight }}px;">
      @foreach($hourLabels as $hl)
        <div class="ia-cal-hour-label" style="top: {{ $hl['top'] }}px;">
          {{ $hl['label'] }}
        </div>
      @endforeach
    </div>

    @foreach($resources as $resource)
      @php
        $colAppts  = $apptsByResource->get($resource->id, collect());
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

        @foreach($hourLabels as $hl)
          <div class="ia-cal-hour-line" style="top: {{ $hl['top'] }}px;"></div>
        @endforeach

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

        @foreach($colAppts as $appt)
          @php
            $apptMin     = $timeToMin($appt->appointment_time);
            $prepMin     = (int) $appt->items->sum('prep_before_minutes_snapshot');
            $cleanMin    = (int) $appt->items->sum('cleanup_after_minutes_snapshot');
            $durMin      = (int) $appt->total_duration_minutes;
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

          <div class="ia-cal-appt status-{{ $appt->status }} {{ $appt->needs_time_review ? 'needs-review' : '' }}"
               style="top: {{ $coreTop }}px;
                      height: {{ $coreHeight }}px;
                      border-left-color: {{ $resourceColor }};
                      background: {{ $resourceColor }}1a;"
               data-appt-id="{{ $appt->id }}"
               @if($appt->needs_time_review) title="Auto-assigned time — please review" @endif>
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
