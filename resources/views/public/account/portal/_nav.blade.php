{{-- portal section nav (underline pattern). $active = key --}}
<div class="ac-nav">
  <a href="{{ route('tenant.customer.portal') }}" class="{{ $active === 'home' ? 'on' : '' }}">Home</a>
  <a href="{{ route('tenant.customer.portal.bookings') }}" class="{{ $active === 'bookings' ? 'on' : '' }}">Bookings</a>
  @if(tenant()?->classes_active){{-- MARKER-CLASSES-P3 --}}
  <a href="{{ route('tenant.customer.portal.passes') }}" class="{{ $active === 'passes' ? 'on' : '' }}">Passes</a>
  @endif
  <a href="{{ route('tenant.customer.portal.orders') }}" class="{{ $active === 'orders' ? 'on' : '' }}">Orders</a>
  <a href="{{ route('tenant.customer.portal.rentals') }}" class="{{ $active === 'rentals' ? 'on' : '' }}">Rentals</a>
  <a href="{{ route('tenant.customer.portal.messages') }}" class="{{ $active === 'messages' ? 'on' : '' }}">Messages</a>
  <a href="{{ route('tenant.customer.portal.account') }}" class="{{ $active === 'account' ? 'on' : '' }}">Account</a>
</div>
