{{-- MARKER-CLASSES-P3: Schedule / Passes & pricing / My passes. $active = schedule|passes --}}
<div class="ps-tabs">
  <a href="{{ route('tenant.customer.classes') }}" class="ps-tab {{ $active === 'schedule' ? 'on' : '' }}">Schedule</a>
  <a href="{{ route('tenant.customer.classes.passes') }}" class="ps-tab {{ $active === 'passes' ? 'on' : '' }}">Passes &amp; pricing</a>
  @if(Auth::guard('customer')->check())
    <a href="{{ route('tenant.customer.portal.passes') }}" class="ps-tab">My passes</a>
  @endif
</div>
