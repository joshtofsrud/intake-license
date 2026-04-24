@props([
  'active' => 'calendar',  // 'calendar' or 'appointments'
])

<div class="ia-schedule-tabs">
  <a href="{{ route('tenant.calendar.index') }}"
     class="ia-schedule-tab {{ $active === 'calendar' ? 'is-active' : '' }}">
    <svg width="14" height="14" viewBox="0 0 14 14" fill="none" style="margin-right:6px">
      <rect x="1" y="3" width="12" height="10" rx="1.5" stroke="currentColor" stroke-width="1.2"/>
      <path d="M5 3V1.5M9 3V1.5M1 6h12" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
    </svg>
    Calendar
  </a>
  <a href="{{ route('tenant.appointments.index') }}"
     class="ia-schedule-tab {{ $active === 'appointments' ? 'is-active' : '' }}">
    <svg width="14" height="14" viewBox="0 0 14 14" fill="none" style="margin-right:6px">
      <path d="M2 4h10M2 7h7M2 10h5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
    </svg>
    Appointments
  </a>
</div>
