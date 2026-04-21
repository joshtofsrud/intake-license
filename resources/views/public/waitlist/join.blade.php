@php
  $headerTitle    = 'Join the waitlist';
  $headerSubtitle = "We'll notify you as soon as a spot opens up.";
@endphp
@include('public.waitlist._shell', ['slot' => $__env->yieldContent('waitlist_slot')])
@section('waitlist_slot')
<div class="w-card">
  <form method="POST" action="{{ route('tenant.waitlist.submit') }}">
    @csrf
    <div class="w-row">
      <label class="w-label">Service</label>
      <select class="w-select" name="service_item_id" required>
        <option value="">Choose a service…</option>
        @foreach($services as $svc)
          <option value="{{ $svc->id }}" {{ ($preselectedService && $preselectedService->id === $svc->id) ? 'selected' : '' }}>
            {{ $svc->name }}
          </option>
        @endforeach
      </select>
    </div>

    <div class="w-row-2 w-row">
      <div>
        <label class="w-label">First name</label>
        <input class="w-input" type="text" name="first_name" required maxlength="80" value="{{ old('first_name') }}">
      </div>
      <div>
        <label class="w-label">Last name</label>
        <input class="w-input" type="text" name="last_name" required maxlength="80" value="{{ old('last_name') }}">
      </div>
    </div>

    <div class="w-row">
      <label class="w-label">Email</label>
      <input class="w-input" type="email" name="email" required maxlength="180" value="{{ old('email') }}">
    </div>

    <div class="w-row">
      <label class="w-label">Phone (for SMS alerts)</label>
      <input class="w-input" type="tel" name="phone" maxlength="32" value="{{ old('phone') }}">
    </div>

    <div class="w-row-2 w-row">
      <div>
        <label class="w-label">Earliest date</label>
        <input class="w-input" type="date" name="date_range_start" required value="{{ old('date_range_start', now()->toDateString()) }}">
      </div>
      <div>
        <label class="w-label">Latest date</label>
        <input class="w-input" type="date" name="date_range_end" required value="{{ old('date_range_end', now()->addMonth()->toDateString()) }}">
      </div>
    </div>

    <div class="w-row">
      <label class="w-label">Preferred days (optional)</label>
      <div class="w-day-picker">
        @foreach([0=>'Sun',1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat'] as $idx => $label)
          <label class="w-day-chip">
            <input type="checkbox" name="preferred_days[]" value="{{ $idx }}" {{ in_array($idx, old('preferred_days', [])) ? 'checked' : '' }}>
            {{ $label }}
          </label>
        @endforeach
      </div>
    </div>

    <div class="w-row-2 w-row">
      <div>
        <label class="w-label">Earliest time (optional)</label>
        <input class="w-input" type="time" name="preferred_time_start" value="{{ old('preferred_time_start') }}">
      </div>
      <div>
        <label class="w-label">Latest time (optional)</label>
        <input class="w-input" type="time" name="preferred_time_end" value="{{ old('preferred_time_end') }}">
      </div>
    </div>

    <div class="w-row">
      <label class="w-label">Notes (optional)</label>
      <textarea class="w-textarea" name="notes" maxlength="500" placeholder="Anything we should know?">{{ old('notes') }}</textarea>
    </div>

    <button type="submit" class="w-btn w-btn--full">Add me to the waitlist</button>
  </form>
</div>

<div class="w-footer-note">
  Want to book now instead? <a href="{{ route('tenant.booking') }}">Check availability</a>.
</div>
@endsection
