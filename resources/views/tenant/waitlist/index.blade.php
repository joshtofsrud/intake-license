@extends('layouts.tenant.app')
@php $pageTitle = 'Waitlist'; @endphp

@push('styles')
<style>
.wl-entry{display:grid;grid-template-columns:1fr 200px 150px 150px 80px;gap:16px;padding:16px;border-bottom:0.5px solid var(--ia-border);align-items:center;font-size:13.5px}
.wl-entry:last-child{border-bottom:none}
.wl-entry-name{font-weight:500}
.wl-entry-sub{font-size:12px;color:var(--ia-text-muted);margin-top:2px}
.wl-entry-service{color:var(--ia-text-muted)}
.wl-entry-dates{font-variant-numeric:tabular-nums;font-size:12.5px}
.wl-entry-offers{font-size:12px;color:var(--ia-text-muted);text-align:right}
.wl-entry-offers b{color:var(--ia-text)}
.wl-entry-actions{text-align:right}
.wl-entry-status{display:inline-block;font-size:10px;text-transform:uppercase;letter-spacing:.06em;font-weight:500;padding:2px 8px;border-radius:4px;margin-left:8px}
.wl-entry-status.is-active{background:var(--ia-accent-soft);color:var(--ia-accent)}
.wl-entry-status.is-fulfilled{background:rgba(96,165,250,.12);color:#60A5FA}
.wl-head{display:grid;grid-template-columns:1fr 200px 150px 150px 80px;gap:16px;padding:10px 16px;background:var(--ia-surface-2);font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-muted);font-weight:500;border-bottom:0.5px solid var(--ia-border)}
.wl-wrap{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);overflow:hidden}
.wl-banner{padding:20px 24px;background:var(--ia-accent-soft);border:0.5px solid rgba(190,242,100,.25);border-radius:var(--ia-r-lg);margin-bottom:20px;display:flex;align-items:center;gap:16px}
.wl-banner-content{flex:1}
.wl-banner-title{font-size:15px;font-weight:500;margin-bottom:3px}
.wl-banner-sub{font-size:13px;color:var(--ia-text-muted)}
.wl-empty{padding:60px 20px;text-align:center;color:var(--ia-text-muted);font-size:14px}
@media (max-width:900px){
  .wl-entry,.wl-head{grid-template-columns:1fr}
  .wl-entry>div,.wl-head>div{text-align:left}
  .wl-entry-actions{text-align:left}
  .wl-head{display:none}
  .wl-entry{padding:14px;border-radius:10px;border:0.5px solid var(--ia-border);margin-bottom:8px}
  .wl-wrap{background:transparent;border:none;padding:0}
}
</style>
@endpush

@section('content')
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Waitlist</h1>
    <p class="ia-page-subtitle">{{ $entries->where('status', 'active')->count() }} active entries</p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.waitlist.settings') }}" class="ia-btn">Settings</a>
  </div>
</div>

@if(session('success'))
  <div style="padding:12px 16px;background:var(--ia-accent-soft);border:0.5px solid rgba(190,242,100,.25);border-radius:var(--ia-r-md);margin-bottom:16px;font-size:13px;color:var(--ia-accent)">
    {{ session('success') }}
  </div>
@endif

@if(!$settings->enabled)
  <div class="wl-banner">
    <div class="wl-banner-content">
      <div class="wl-banner-title">Waitlist is currently off</div>
      <div class="wl-banner-sub">Customers can't join the waitlist, and cancellations won't trigger notifications. Turn it on in Settings.</div>
    </div>
    <a href="{{ route('tenant.waitlist.settings') }}" class="ia-btn ia-btn--primary">Settings</a>
  </div>
@endif

<div class="wl-wrap">
  <div class="wl-head">
    <div>Customer</div>
    <div>Service</div>
    <div>Date range</div>
    <div>Offers</div>
    <div></div>
  </div>
  @forelse($entries as $entry)
    <div class="wl-entry">
      <div>
        <div class="wl-entry-name">
          {{ $entry->customer?->fullName() ?? '—' }}
          @if($entry->status === 'active')<span class="wl-entry-status is-active">Active</span>@endif
          @if($entry->status === 'fulfilled')<span class="wl-entry-status is-fulfilled">Booked</span>@endif
        </div>
        <div class="wl-entry-sub">
          {{ $entry->customer?->email }}
          @if($entry->customer?->phone) · {{ $entry->customer->phone }}@endif
        </div>
      </div>
      <div class="wl-entry-service">{{ $entry->serviceItem?->name ?? '—' }}</div>
      <div class="wl-entry-dates">
        {{ $entry->date_range_start->format('M j') }}–{{ $entry->date_range_end->format('M j') }}
      </div>
      <div class="wl-entry-offers">
        @php
          $offerCount = $entry->offers->count();
          $acceptedCount = $entry->offers->where('status', 'accepted')->count();
        @endphp
        @if($offerCount > 0)
          <b>{{ $offerCount }}</b> sent
          @if($acceptedCount > 0) · <b>{{ $acceptedCount }}</b> accepted@endif
        @else
          <span style="opacity:.5">None yet</span>
        @endif
      </div>
      <div class="wl-entry-actions">
        @if($entry->status === 'active')
          <form method="POST" action="{{ route('tenant.waitlist.cancel', ['id' => $entry->id]) }}" style="display:inline">
            @csrf
            @method('DELETE')
            <button type="submit" class="ia-btn ia-btn--sm" style="color:#EF4444;border-color:rgba(239,68,68,.3)" onclick="return confirm('Remove this entry?')">Remove</button>
          </form>
        @endif
      </div>
    </div>
  @empty
    <div class="wl-empty">
      No waitlist entries yet. Customers can join from your booking page.
    </div>
  @endforelse
</div>
@endsection
