<div class="ia-dash-zone1">

  <div class="ia-dash-summary">
    <div class="ia-dash-greet-card">
      @if($today['today_count'] === 0 && $today['last_24h_bookings'] === 0)
        <div class="ia-dash-summary-line">
          No appointments on the books today. When customers book, you will see them here.
        </div>
      @else
        <div class="ia-dash-summary-line">
          @if($today['today_count'] > 0)
            You have
            <strong>{{ $today['today_count'] }} {{ Str::plural('appointment', $today['today_count']) }} today</strong>@if($today['next_up'] && $today['next_up']->appointment_time)
              — next up at
              <strong>{{ \Carbon\Carbon::parse($today['next_up']->appointment_time)->format('g:i A') }}</strong>
              with {{ $today['next_up']->customerName() }}@if($today['next_up']->items->isNotEmpty())
                ({{ $today['next_up']->items->first()->item_name_snapshot }})
              @endif.
            @else
              .
            @endif
          @else
            No appointments today.
          @endif

          @if($today['last_24h_bookings'] > 0)
            In the last 24 hours you got
            <strong>{{ $today['last_24h_bookings'] }} new {{ Str::plural('booking', $today['last_24h_bookings']) }}</strong>.
          @endif
        </div>
      @endif
    </div>

    <div class="ia-dash-weekstats">
      <div class="ia-dash-weekstat-head">This week so far</div>
      <div class="ia-dash-weekstat-grid">
        <div>
          <div class="ia-dash-weekstat-value">{{ $today['week_bookings'] }}</div>
          <div class="ia-dash-weekstat-label">Bookings</div>
        </div>
        <div>
          <div class="ia-dash-weekstat-value">{{ format_money($today['week_revenue_cents']) }}</div>
          <div class="ia-dash-weekstat-label">Revenue</div>
        </div>
        <div>
          <div class="ia-dash-weekstat-value">{{ $today['week_new_customers'] }}</div>
          <div class="ia-dash-weekstat-label">New customers</div>
        </div>
        <div>
          <div class="ia-dash-weekstat-value">{{ $today['week_cancellations'] }}</div>
          <div class="ia-dash-weekstat-label">Cancellations</div>
        </div>
      </div>
    </div>
  </div>

  @if($today['appointments']->isNotEmpty())
  <div class="ia-card" style="margin-top:20px">
    <div class="ia-card-head">
      <span class="ia-card-title">Today · {{ $today['today_count'] }} {{ Str::plural('appointment', $today['today_count']) }}</span>
      <a href="{{ route('tenant.appointments.index') }}" class="ia-card-action">Open calendar →</a>
    </div>

    <div class="ia-dash-today-list">
      @foreach($today['appointments'] as $appt)
        <a href="{{ route('tenant.appointments.show', $appt->id) }}" class="ia-dash-today-row">
          <div class="ia-dash-today-time">
            @if($appt->appointment_time)
              <div class="ia-dash-today-time-hm">
                {{ \Carbon\Carbon::parse($appt->appointment_time)->format('g:i') }}
              </div>
              <div class="ia-dash-today-time-ap">
                {{ \Carbon\Carbon::parse($appt->appointment_time)->format('A') }}
                @if($appt->total_duration_minutes)
                  · {{ $appt->total_duration_minutes }} min
                @endif
              </div>
            @else
              <div class="ia-dash-today-time-hm">Drop-off</div>
              <div class="ia-dash-today-time-ap">{{ $appt->receiving_method_snapshot ?: 'Any time' }}</div>
            @endif
          </div>
          <div class="ia-dash-today-main">
            <div class="ia-dash-today-service">
              {{ $appt->items->first()?->item_name_snapshot ?: 'Service' }}
            </div>
            <div class="ia-dash-today-customer">
              {{ $appt->customerName() }} · {{ format_money($appt->total_cents) }}
            </div>
          </div>
          <div class="ia-dash-today-status">
            <span class="ia-badge ia-badge--{{ str_replace('_', '-', $appt->status) }}">
              {{ ucwords(str_replace('_', ' ', $appt->status)) }}
            </span>
            @if($appt->payment_status !== 'unpaid')
              <span class="ia-badge ia-badge--{{ $appt->payment_status }}" style="margin-left:4px">
                {{ ucfirst($appt->payment_status) }}
              </span>
            @endif
          </div>
        </a>
      @endforeach
    </div>
  </div>
  @endif
</div>
