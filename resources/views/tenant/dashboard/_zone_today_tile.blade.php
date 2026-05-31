{{-- MARKER-PATCH-110-STEP-6 - Today's schedule peek as a tile.
     Renders the upcoming 5 appointments inline. "Now" highlight on the
     next-up entry. Tile is wrapped in a link to the calendar. --}}

@php
  $todayAppts = $today['appointments'] ?? collect();
  $nextUp = $today['next_up'] ?? null;
  $todayCount = $today['today_count'] ?? 0;

  // Show next 5 upcoming + in-progress; skip completed/cancelled.
  $shownAppts = $todayAppts
      ->filter(fn($a) => !in_array($a->status, ['cancelled', 'refunded', 'completed', 'closed'], true))
      ->take(5);

  $dateLong = $greeting['date_long'] ?? \Carbon\Carbon::now()->format('l, F j');
@endphp

<div class="ia-dash-today-tile-block">
  <div class="ia-dash-tiles-zone-label">
    <span>Today</span>
    <span class="hint">
      @if($todayCount === 0)
        Nothing on the calendar today
      @elseif($nextUp && $nextUp->appointment_time)
        {{ $todayCount }} booked · next at {{ \Carbon\Carbon::parse($nextUp->appointment_time)->format('g:i A') }}
      @else
        {{ $todayCount }} booked today
      @endif
    </span>
  </div>

  <div class="ia-dash-today-tile">
    <div class="head">
      <div class="title">{{ $dateLong }}</div>
      <a href="{{ route('tenant.calendar.index') }}" class="open">Open calendar →</a>
    </div>

    @if($shownAppts->isEmpty())
      <div class="empty">
        @if($todayCount === 0)
          No appointments today. Open the calendar to book one.
        @else
          All of today's appointments are completed. Nice work.
        @endif
      </div>
    @else
      <div class="schedule-list">
        @foreach($shownAppts as $a)
          @php
            $isNext = $nextUp && $nextUp->id === $a->id;
            $svc = $a->items->first()?->item_name_snapshot ?? 'Service';
            $statusClass = str_replace('_', '-', $a->status);
            $timeStr = $a->appointment_time
                ? \Carbon\Carbon::parse($a->appointment_time)->format('g:i A')
                : 'Drop-off';
          @endphp
          <a href="{{ route('tenant.appointments.show', $a->id) }}"
             class="schedule-row {{ $isNext ? 'now' : '' }}">
            <div class="time">{{ $timeStr }}</div>
            <div class="info">
              <div class="name">{{ trim(($a->customer_first_name ?? '') . ' ' . ($a->customer_last_name ?? '')) }}</div>
              <div class="svc">{{ $svc }}</div>
            </div>
            <div class="status status--{{ $statusClass }}">{{ ucwords(str_replace('_', ' ', $a->status)) }}</div>
          </a>
        @endforeach
      </div>
    @endif
  </div>

  {{-- MARKER-PATCH-183B — today's deliveries, parallel to the appointments tile --}}
  @php $todayDeliveries = $today['today_deliveries'] ?? collect(); @endphp
  @if($todayDeliveries->isNotEmpty())
  <div class="ia-dash-today-tile" style="margin-top:12px">
    <div class="head">
      <div class="title">Deliveries · {{ $todayDeliveries->count() }} today</div>
      <a href="{{ route('tenant.deliveries.index') }}" class="open">Open deliveries →</a>
    </div>
    <div class="schedule-list">
      @foreach($todayDeliveries->take(5) as $d)
        @php
          $dTime = $d->scheduled_at ? $d->scheduled_at->format('g:i A') : 'Any time';
          $dWho  = $d->customer ? trim(($d->customer->first_name ?? '') . ' ' . ($d->customer->last_name ?? '')) : 'No customer';
          $dKind = $d->isPickup() ? 'Pickup' : 'Drop-off';
          $dStatus = str_replace('_', '-', $d->status);
        @endphp
        <a href="{{ route('tenant.deliveries.index') }}" class="schedule-row">
          <div class="time">{{ $dTime }}</div>
          <div class="info">
            <div class="name">{{ $dWho }}</div>
            <div class="svc">{{ $dKind }}@if($d->address) · {{ $d->address }}@endif</div>
          </div>
          <div class="status status--{{ $dStatus }}">{{ ucfirst($d->status) }}</div>
        </a>
      @endforeach
    </div>
  </div>
  @endif
</div>
