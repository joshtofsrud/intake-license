@props([
  'active' => 'calendar',
])

{{-- MARKER-PATCH-152A — added Deliveries pill --}}

<div class="ia-schedule-toggle">
  <a href="{{ route('tenant.calendar.index') }}"
     class="ia-schedule-pill {{ $active === 'calendar' ? 'is-active' : '' }}">
    <svg width="13" height="13" viewBox="0 0 14 14" fill="none">
      <rect x="1" y="3" width="12" height="10" rx="1.5" stroke="currentColor" stroke-width="1.2"/>
      <path d="M5 3V1.5M9 3V1.5M1 6h12" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
    </svg>
    Calendar
  </a>
  <a href="{{ route('tenant.appointments.index') }}"
     class="ia-schedule-pill {{ $active === 'appointments' ? 'is-active' : '' }}">
    <svg width="13" height="13" viewBox="0 0 14 14" fill="none">
      <path d="M2 4h10M2 7h7M2 10h5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
    </svg>
    Appointments
  </a>
  @if($currentTenant->classes_enabled)
  <a href="{{ route('tenant.classes.sessions') }}"
     class="ia-schedule-pill {{ $active === 'classes' ? 'is-active' : '' }}">
    <svg width="13" height="13" viewBox="0 0 14 14" fill="none">
      <rect x="1" y="3" width="12" height="8" rx="1.2" stroke="currentColor" stroke-width="1.2"/>
      <path d="M5.5 5.5l3 1.5-3 1.5V5.5z" fill="currentColor"/>
    </svg>
    Classes
  </a>
  @endif
  {{-- MARKER-PATCH-156 — gate Deliveries pill behind feature toggle --}}
  @if($currentTenant->deliveries_enabled)
  <a href="{{ route('tenant.deliveries.index') }}"
     class="ia-schedule-pill {{ $active === 'deliveries' ? 'is-active' : '' }}">
    <svg width="13" height="13" viewBox="0 0 14 14" fill="none">
      <path d="M1 9V5a1 1 0 011-1h6v6H1z" stroke="currentColor" stroke-width="1.2"/>
      <path d="M8 6h3l2 2v2H8V6z" stroke="currentColor" stroke-width="1.2"/>
      <circle cx="4" cy="11" r="1.2" fill="currentColor"/>
      <circle cx="10.5" cy="11" r="1.2" fill="currentColor"/>
    </svg>
    Deliveries
  </a>
  @endif
</div>