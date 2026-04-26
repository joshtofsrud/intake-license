{{-- Week view: per-resource swimlanes (rows), 7 day-columns Sun–Sat.
     Compact list inside each cell. No continuous time axis — appointments
     stack vertically by appointment_time within their cell.
     Click a cell → drills to day view at that resource + date. --}}
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
@else
<div class="ia-cal-week">

  {{-- Header row: empty corner + 7 day labels --}}
  <div class="ia-cal-week-header">
    <div class="ia-cal-week-corner"></div>
    @foreach($days as $day)
      <div class="ia-cal-week-day-head {{ $day['isToday'] ? 'is-today' : '' }} {{ $day['isWeekend'] ? 'is-weekend' : '' }}">
        <span class="ia-cal-week-day-short">{{ $day['short'] }}</span>
        <span class="ia-cal-week-day-num">{{ $day['num'] }}</span>
      </div>
    @endforeach
  </div>

  {{-- One row per resource --}}
  @foreach($resources as $resource)
    @php $resourceColor = $resource->color_hex ?: '#888'; @endphp
    <div class="ia-cal-week-row">
      <div class="ia-cal-week-resource">
        <span class="ia-cal-resource-dot" style="background: {{ $resourceColor }};"></span>
        <span class="ia-cal-week-resource-name">{{ $resource->name }}</span>
        @if($resource->subtitle)
          <span class="ia-cal-week-resource-sub">{{ $resource->subtitle }}</span>
        @endif
      </div>

      @foreach($days as $day)
        @php
          $cellAppts = $byResourceDate[$resource->id][$day['dateStr']] ?? [];
          $cellHref  = route('tenant.calendar.index', [
            'view' => 'day',
            'date' => $day['dateStr'],
            'resources' => $resource->id,
          ]);
        @endphp
        <a class="ia-cal-week-cell {{ $day['isToday'] ? 'is-today' : '' }} {{ $day['isWeekend'] ? 'is-weekend' : '' }}"
           href="{{ $cellHref }}"
           title="Open {{ $day['date']->format('l, M j') }} — {{ $resource->name }}">
          @if(empty($cellAppts))
            <span class="ia-cal-week-cell-empty">·</span>
          @else
            @foreach($cellAppts as $appt)
              @php
                $apptMin = (int) (substr($appt->appointment_time, 0, 2) * 60)
                         + (int) substr($appt->appointment_time, 3, 2);
                $h = intdiv($apptMin, 60);
                $m = $apptMin % 60;
                $timeStr = sprintf(
                    '%d:%02d%s',
                    $h === 0 ? 12 : ($h > 12 ? $h - 12 : $h),
                    $m,
                    $h < 12 ? 'a' : 'p'
                );
                $name = trim(($appt->customer_first_name ?? '') . ' ' . ($appt->customer_last_name ?? ''));
                $svc  = optional($appt->items->first())->item_name_snapshot ?? '';
              @endphp
              <span class="ia-cal-week-item status-{{ $appt->status }}"
                    style="border-left-color: {{ $resourceColor }};">
                <span class="ia-cal-week-item-time">{{ $timeStr }}</span>
                <span class="ia-cal-week-item-name">{{ $name ?: 'Appt' }}</span>
                @if($svc)
                  <span class="ia-cal-week-item-svc">{{ $svc }}</span>
                @endif
              </span>
            @endforeach
          @endif
        </a>
      @endforeach
    </div>
  @endforeach

</div>
@endif
