@extends('layouts.tenant.app')
@php $pageTitle = 'New shipment'; @endphp


@push('styles')
<style>
/* "Best on desktop" mobile notice (patch #38). Hidden on >640px. */
.recv-mobile-notice{display:none;background:rgba(250,180,106,.08);border:0.5px solid rgba(250,180,106,.25);border-radius:var(--ia-r-lg);padding:14px 16px;margin-bottom:16px}
.recv-mobile-notice-title{font-size:13px;font-weight:600;color:#FAB46A;margin-bottom:4px;display:flex;align-items:center;gap:6px}
.recv-mobile-notice-body{font-size:12px;color:var(--ia-text-muted);line-height:1.5}
@media(max-width:640px){
  .recv-mobile-notice{display:block}
}
</style>
@endpush

@section('content')


{{-- Mobile "best on desktop" notice (patch #38). Receiving is line-by-line
     entry that doesn't fit a phone — v1.1 will likely add barcode scanning
     and a different mobile flow. For now we surface the limitation rather
     than rebuild the form. --}}
<div class="recv-mobile-notice">
  <div class="recv-mobile-notice-title">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    Best on desktop
  </div>
  <div class="recv-mobile-notice-body">
    Receiving works on mobile, but line-by-line entry is faster on a larger screen. Mobile-optimized receiving (with barcode scanning) is on the roadmap.
  </div>
</div>

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">New shipment</h1>
    <p class="ia-page-subtitle">Start receiving stock against a shipment</p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.inventory.receiving.index') }}" class="ia-btn ia-btn--ghost">← Back</a>
  </div>
</div>

<form method="POST" action="{{ route('tenant.inventory.receiving.store') }}" class="ia-card" style="max-width:680px">
  @csrf
  <div class="ia-card-body" style="padding:20px">

    @if($errors->any())
      <div class="ia-flash ia-flash--error">
        @foreach($errors->all() as $err)<div>{{ $err }}</div>@endforeach
      </div>
    @endif

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 18px">
      <div class="ia-field" style="grid-column:1 / -1">
        <label class="ia-label">Shipment number</label>
        <input name="shipment_number" class="ia-input" value="{{ old('shipment_number', $defaultNumber) }}" required maxlength="30" autocomplete="off">
        <div class="ia-help">Default suggested. Replace with the vendor's number from the packing slip if you'd like.</div>
      </div>

      <div class="ia-field">
        <label class="ia-label">Location</label>
        @if($locations->count() === 1)
          <input class="ia-input" value="{{ $locations->first()->name }}" disabled>
          <input type="hidden" name="location_id" value="{{ $locations->first()->id }}">
        @else
          <select name="location_id" class="ia-input" required>
            @foreach($locations as $loc)
              <option value="{{ $loc->id }}" @selected(old('location_id', $defaultLocationId) === $loc->id)>{{ $loc->name }}</option>
            @endforeach
          </select>
        @endif
      </div>

      <div class="ia-field">
        <label class="ia-label">Received date</label>
        <input name="received_date" type="date" class="ia-input" value="{{ old('received_date', $today) }}" required>
      </div>

      <div class="ia-field">
        <label class="ia-label">Distributor name</label>
        <input name="distributor_name" class="ia-input" value="{{ old('distributor_name') }}" maxlength="128" placeholder="e.g. QBP, Hawley, J&B">
      </div>

      <div class="ia-field">
        <label class="ia-label">Distributor code <span style="color:var(--ia-text-muted)">(optional)</span></label>
        <input name="distributor_code" class="ia-input" value="{{ old('distributor_code') }}" maxlength="32" placeholder="Account #">
      </div>

      <div class="ia-field" style="grid-column:1 / -1">
        <label class="ia-label">Notes <span style="color:var(--ia-text-muted)">(optional)</span></label>
        <textarea name="notes" class="ia-input" rows="2" maxlength="2000">{{ old('notes') }}</textarea>
      </div>
    </div>

    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;padding-top:16px;border-top:1px solid var(--ia-border)">
      <a href="{{ route('tenant.inventory.receiving.index') }}" class="ia-btn ia-btn--ghost">Cancel</a>
      <button type="submit" class="ia-btn ia-btn--primary">Start shipment →</button>
    </div>
  </div>
</form>

@endsection
