@php
  $headerTitle    = 'Your waitlist';
  $headerSubtitle = "We'll email and text you as soon as a matching spot opens.";
  $dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
@endphp
@include('public.waitlist._shell', ['slot' => $__env->yieldContent('waitlist_slot')])
@section('waitlist_slot')
<div class="w-card">
  @if($entries->isEmpty())
    <div style="text-align:center;padding:24px 0">
      <p style="color:var(--p-muted);margin-bottom:16px">You're not on any waitlists yet.</p>
      <a href="{{ route('tenant.waitlist.join') }}" class="w-btn">Join the waitlist</a>
    </div>
  @else
    @foreach($entries as $entry)
      <div class="w-entry-row">
        <div style="flex:1;min-width:0">
          <div class="w-entry-name">
            {{ $entry->serviceItem?->name ?? 'Unknown service' }}
            @if($entry->status === 'fulfilled')<span class="w-entry-status is-fulfilled">Booked</span>@endif
          </div>
          <div class="w-entry-meta">
            {{ $entry->date_range_start->format('M j') }} – {{ $entry->date_range_end->format('M j, Y') }}
            @if(!empty($entry->preferred_days))
              · @foreach($entry->preferred_days as $d){{ $dayNames[$d] ?? '' }}{{ !$loop->last ? ', ' : '' }}@endforeach
            @endif
            @if($entry->preferred_time_start || $entry->preferred_time_end)
              · {{ $entry->preferred_time_start ? substr($entry->preferred_time_start, 0, 5) : '—' }}–{{ $entry->preferred_time_end ? substr($entry->preferred_time_end, 0, 5) : '—' }}
            @endif
          </div>
          @if($entry->notes)
            <div class="w-entry-meta" style="margin-top:6px;font-style:italic">"{{ $entry->notes }}"</div>
          @endif
        </div>
        @if($entry->status === 'active')
          <form method="POST" action="{{ route('tenant.waitlist.remove') }}" style="flex-shrink:0">
            @csrf
            <input type="hidden" name="entry_id" value="{{ $entry->id }}">
            <input type="hidden" name="token" value="{{ $token }}">
            <button type="submit" class="w-btn w-btn--sm w-btn--danger" onclick="return confirm('Remove this entry?')">Remove</button>
          </form>
        @endif
      </div>
    @endforeach
  @endif
</div>

<div class="w-footer-note">
  <a href="{{ route('tenant.waitlist.join') }}">Add another waitlist entry</a>
</div>
@endsection
