@extends('layouts.tenant.app')
@php $pageTitle = 'Rental Settings'; @endphp

{{-- MARKER-PATCH-228 — season window + leasing visibility toggle. --}}

@section('content')

@include('layouts.tenant._rental-nav', ['active' => 'settings'])

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Rental Settings</h1>
    <p class="ia-page-subtitle">Season window and what's switched on for your rental operation.</p>
  </div>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>
@endif
@if($errors->any())
  <div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ route('tenant.rentals.settings.save') }}">
  @csrf

  {{-- MARKER-PATCH-228B — Rentals on/off --}}
  <div class="ia-card" style="padding:18px 20px;margin-bottom:16px">
    <div class="ia-card-head"><span class="ia-card-title">Rentals</span></div>
    <p style="font-size:12.5px;opacity:.55;margin:6px 0 14px;line-height:1.5">
      When on, the rental desk, fleet, bookings, and availability show in your menu. Turn off to hide rentals entirely.
    </p>
    <label style="display:flex;align-items:center;gap:10px;font-size:13.5px;cursor:pointer">
      <input type="checkbox" name="rentals_visible" value="1" {{ $rentalsVisible ? 'checked' : '' }}>
      Show rentals in my menu
    </label>
  </div>

  {{-- MARKER-PATCH-228B — Leasing (season window merged in) --}}
  <div class="ia-card" style="padding:18px 20px;margin-bottom:16px">
    <div class="ia-card-head" style="display:flex;align-items:center;justify-content:space-between">
      <span class="ia-card-title">Season-long leasing</span>
      @unless($leasingAvailable)<span class="ia-badge ia-badge--unpaid">Scale plan</span>@endunless
    </div>
    @if($leasingAvailable)
      <p style="font-size:12.5px;opacity:.55;margin:6px 0 14px;line-height:1.5">
        Tiered season packages (e.g. "Junior Complete") that pull from your rental fleet. When off, leasing is hidden and your shop runs pure rentals.
      </p>
      <label style="display:flex;align-items:center;gap:10px;font-size:13.5px;cursor:pointer;margin-bottom:16px">
        <input type="checkbox" name="leases_enabled" value="1" {{ $leasesEnabled ? 'checked' : '' }}>
        Enable leasing (adds Leases to your rentals menu)
      </label>
      <div style="border-top:.5px solid var(--ia-border);padding-top:14px">
        <div class="ia-label" style="margin-bottom:8px">Season window</div>
        <p style="font-size:12px;opacity:.5;margin:0 0 12px;line-height:1.5">Season-long leases default their return date to the season end.</p>
        <div style="display:flex;gap:14px;flex-wrap:wrap">
          <div>
            <label class="ia-label" style="display:block;margin-bottom:5px">Season starts (MM-DD)</label>
            <input type="text" name="season_start" value="{{ $seasonStart }}" placeholder="11-01" class="ia-input" style="width:120px;font-family:var(--ia-font-mono)">
          </div>
          <div>
            <label class="ia-label" style="display:block;margin-bottom:5px">Season ends (MM-DD)</label>
            <input type="text" name="season_end" value="{{ $seasonEnd }}" placeholder="04-15" class="ia-input" style="width:120px;font-family:var(--ia-font-mono)">
          </div>
        </div>
      </div>
    @else
      <p style="font-size:12.5px;opacity:.55;margin:6px 0 0;line-height:1.5">
        Season-long leasing — tiered packages that pull from your fleet — is available on the <strong>Scale</strong> plan and up. Upgrade to turn it on.
      </p>
      {{-- season hidden inputs preserve config across saves even when leasing unavailable --}}
      <input type="hidden" name="season_start" value="{{ $seasonStart }}">
      <input type="hidden" name="season_end" value="{{ $seasonEnd }}">
    @endif
  </div>

  <button type="submit" class="ia-btn ia-btn--primary">Save settings</button>
</form>

@endsection
