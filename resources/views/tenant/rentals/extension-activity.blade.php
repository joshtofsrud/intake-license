@extends('layouts.tenant.app')
@php $pageTitle = 'Last-minute offers'; @endphp

{{-- MARKER-RENTAL-OFFERS-STUDIO: did the robot make money? Same 30-day stats,
     filters and CSV export, in the Studio design. --}}

@section('mobile-fab', 'new-rental')
@include('layouts.tenant._rental-styles')

@section('content')
@php
  $filters = ['all' => 'All', 'accepted' => 'Accepted', 'dead' => 'Declined / expired'];
  $delta   = $sent - $sentPrev;
  $avgTxt  = $avgMins ? floor($avgMins / 60) . 'h ' . ($avgMins % 60) . 'm' : '—';
@endphp

<div class="sd rf" id="sd">
  <header class="sd-head" id="sdHead">
    <div class="sd-head-l">
      <h1 class="sd-hi">Last-minute offers</h1>
      <p class="sd-sub">Auto-extension activity · last 30 days.</p>
    </div>
    <div class="sd-head-r">
      <a href="{{ route('tenant.rentals.extension.activity', ['filter' => $filter, 'export' => 'csv']) }}" class="ia-btn ia-btn--sm">Export CSV</a>
    </div>
  </header>

  @include('layouts.tenant._rental-nav', ['active' => 'offers'])

  <div class="of-stats sd-in">
    <div class="of-stat"><small>Offers sent</small><b>{{ $sent }}</b><span class="{{ $delta >= 0 ? 'up' : '' }}">{{ $delta >= 0 ? '+' : '' }}{{ $delta }} vs. prev. 30d</span></div>
    <div class="of-stat"><small>Accepted</small><b>{{ $accepted }}</b><span>{{ $convPct }}% conversion</span></div>
    <div class="of-stat"><small>Revenue captured</small><b>{{ format_money($revenue) }}</b><span>{{ $accepted > 0 ? format_money($avgPer) . ' / accepted offer' : '—' }}</span></div>
    <div class="of-stat"><small>Avg. extension</small><b>{{ $avgTxt }}</b><span>per accepted offer</span></div>
  </div>

  <div class="rf-ctl sd-in">
    <div class="sd-seg" role="tablist" aria-label="Show">
      @foreach($filters as $k => $label)
        <a href="{{ route('tenant.rentals.extension.activity', ['filter' => $k]) }}" class="{{ $filter === $k ? 'on' : '' }}" role="tab">{{ $label }} <em>{{ $counts[$k] ?? 0 }}</em></a>
      @endforeach
    </div>
  </div>

  <div class="sd-list sd-in">
    @if($offers->isEmpty())
      <div class="sd-empty">
        @if($filter === 'accepted') No accepted offers in this list yet.
        @elseif($filter === 'dead') Nothing declined or expired.
        @else Nothing here yet — offers appear as the scan finds rentals that are coming back with nobody booked next.
        @endif
        <div class="sd-empty-acts">
          <a href="{{ route('tenant.rentals.settings') }}#ss-offers" class="ia-btn ia-btn--sm ia-btn--primary">Offer settings</a>
          @if($filter !== 'all')<a href="{{ route('tenant.rentals.extension.activity') }}" class="ia-btn ia-btn--sm">Show all offers</a>@endif
        </div>
      </div>
    @else
      <div class="of-h"><span>Sent</span><span>Customer</span><span>Unit</span><span>Channel</span><span style="text-align:right">Discount</span><span style="text-align:right">Offer total</span><span>Status</span></div>
      @foreach($offers as $o)
        @php $go = $o->rental ? route('tenant.rentals.bookings.show', $o->rental_id) : null; @endphp
        <div class="of-r" @if($go) data-href="{{ $go }}" @endif>
          <span class="when">{{ $o->sent_at ? tlocal($o->sent_at) : '—' }}</span>
          <b>{{ $o->rental?->customer?->fullName() ?? '—' }}</b>
          <span>{{ $o->rental?->lines?->first()?->name_snapshot ?? '—' }}</span>
          <span class="of-ch">{{ $o->channel === 'manual' ? 'Manual' : 'Auto' }}</span>
          <span class="num of-pct">{{ $o->discount_pct }}%</span>
          <span class="num">{{ format_money($o->total_cents) }}</span>
          <span class="of-st">
            @if($o->status === 'paid')<span class="rf-st out">Accepted · paid</span>
            @elseif($o->status === 'sent')<span class="rf-st res">Awaiting reply</span>
            @else<span class="rf-st off">{{ ucfirst($o->status) }}</span>
            @endif
          </span>
        </div>
      @endforeach
      <div class="sd-end in"><i></i><span>{{ $offers->count() >= 200 ? 'Showing the latest 200' : 'End of the list' }}</span><i></i></div>
    @endif
  </div>

  <p class="sd-legend">An offer is texted to the renter when their rental is about to come back and nothing is booked on the unit next. <b>Awaiting reply</b> means the pay link is still live; once it's paid the rental's due time moves for you automatically. Figures cover the last 30 days and compare with the 30 before. The rules for who gets an offer, and the discount, are in <a href="{{ route('tenant.rentals.settings') }}#ss-offers">Settings</a>.</p>
</div>
@endsection

@push('scripts')
@include('layouts.tenant._rental-boot')
<script>
// a row opens its booking
document.addEventListener('click', function (e) {
  var row = e.target.closest('.of-r[data-href]');
  if (row && !e.target.closest('a, button')) location.href = row.getAttribute('data-href');
});
</script>
@endpush
