{{-- Month view: 6×7 density grid. Always 6 weeks for layout stability.
     Up to 4 stacked color-coded bars per cell, "+N more" overflow.
     Hover a bar = tooltip with appointment summary.
     Click cell = drill to day view for that date. --}}
<div class="ia-cal-month">

  {{-- Day-of-week header row --}}
  <div class="ia-cal-month-header">
    @foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dow)
      <div class="ia-cal-month-dow">{{ $dow }}</div>
    @endforeach
  </div>

  {{-- 42-cell grid --}}
  <div class="ia-cal-month-grid">
    @foreach($cells as $cell)
      @php
        $cellAppts = $byDate[$cell['dateStr']] ?? [];
        $shown     = array_slice($cellAppts, 0, 4);
        $overflow  = max(0, count($cellAppts) - 4);
        $cellHref  = route('tenant.calendar.index', [
          'view' => 'day',
          'date' => $cell['dateStr'],
        ]);
      @endphp
      <a class="ia-cal-month-cell
                {{ $cell['inMonth'] ? 'is-in-month' : 'is-out-month' }}
                {{ $cell['isToday'] ? 'is-today' : '' }}
                {{ in_array($cell['dayOfWeek'], [0, 6], true) ? 'is-weekend' : '' }}"
         href="{{ $cellHref }}"
         title="Open {{ $cell['date']->format('l, M j') }}">
        <span class="ia-cal-month-num">{{ $cell['num'] }}</span>

        @if(!empty($shown))
          <span class="ia-cal-month-bars">
            @foreach($shown as $appt)
              @php
                $rid       = $appt->resource_id;
                $color     = $resourceColors[$rid] ?? '#888';
                $rname     = $resourceNames[$rid] ?? 'Resource';
                $apptMin   = (int) (substr($appt->appointment_time, 0, 2) * 60)
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
                $tip  = sprintf('%s · %s%s · %s · %s',
                  $timeStr, $name ?: 'Appt',
                  $svc ? ' — ' . $svc : '',
                  $rname,
                  ucfirst(str_replace('_', ' ', $appt->status))
                );
              @endphp
              <span class="ia-cal-month-bar status-{{ $appt->status }}"
                    style="background: {{ $color }};"
                    title="{{ $tip }}"></span>
            @endforeach
            @if($overflow > 0)
              <span class="ia-cal-month-more">+{{ $overflow }} more</span>
            @endif
          </span>
        @endif
      </a>
    @endforeach
  </div>

</div>
