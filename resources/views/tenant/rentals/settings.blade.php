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

  {{-- MARKER-PATCH-233 — late & overdue policy. Suggested automatically
       in the return flow; always editable per return. --}}
  <div class="ia-card" style="padding:18px 20px;margin-bottom:16px">
    <div class="ia-card-head"><span class="ia-card-title">Late &amp; overdue policy</span></div>
    <p style="font-size:12.5px;opacity:.55;margin:6px 0 14px;line-height:1.5">
      The return flow suggests a late fee from these rules. Staff can edit or waive it on every return — this sets the default, not a mandate.
    </p>
    <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:end">
      <div>
        <label class="ia-label" style="display:block;margin-bottom:5px">Grace period (minutes)</label>
        <input type="number" name="late_grace_minutes" value="{{ $lateGraceMinutes }}" min="0" max="1440" class="ia-input" style="width:120px">

        {{-- MARKER-RENTAL-EXT — last-minute extension offers --}}
        @php $extS = app(\App\Services\RentalExtensionOfferService::class)->settings(tenant()); @endphp
        @if(tenant()->rental_extensions_enabled)
          <div style="border-top:.5px solid var(--ia-border);margin-top:18px;padding-top:16px">
            <div class="ia-label" style="margin-bottom:4px">Last-minute extension offers</div>
            <p style="font-size:12px;opacity:.5;margin:0 0 12px;line-height:1.5">When a rental is coming back and nobody has the unit booked next, Intake texts the renter a discounted extension with a one-tap pay link.</p>
            <label style="display:flex;align-items:center;gap:9px;font-size:13px;margin-bottom:12px;cursor:pointer">
              <input type="checkbox" name="ext_enabled" value="1" {{ $extS['enabled'] ? 'checked' : '' }}> Send automatic offers
            </label>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px">
              <div>
                <label class="ia-label" style="display:block;margin-bottom:5px">Discount %</label>
                <input type="number" name="ext_discount_pct" value="{{ $extS['discount_pct'] }}" min="0" max="90" class="ia-input" style="width:100%">
              </div>
              <div>
                <label class="ia-label" style="display:block;margin-bottom:5px">Minimum gap (min)</label>
                <input type="number" name="ext_min_gap_minutes" value="{{ $extS['min_gap'] }}" min="30" max="1440" class="ia-input" style="width:100%">
              </div>
              <div>
                <label class="ia-label" style="display:block;margin-bottom:5px">Send before return (min)</label>
                <input type="number" name="ext_send_before_minutes" value="{{ $extS['send_before'] }}" min="15" max="480" class="ia-input" style="width:100%">
              </div>
              <div>
                <label class="ia-label" style="display:block;margin-bottom:5px">Extend up to (daily cutoff)</label>
                <input type="time" name="ext_until" value="{{ $extS['until'] }}" class="ia-input" style="width:100%">
              </div>
              <div>
                <label class="ia-label" style="display:block;margin-bottom:5px">Quiet hours start</label>
                <input type="time" name="ext_quiet_start" value="{{ $extS['quiet_start'] }}" class="ia-input" style="width:100%">
              </div>
              <div>
                <label class="ia-label" style="display:block;margin-bottom:5px">Quiet hours end</label>
                <input type="time" name="ext_quiet_end" value="{{ $extS['quiet_end'] }}" class="ia-input" style="width:100%">
              </div>
            </div>
          </div>
        @endif
      </div>
      <div>
        <label class="ia-label" style="display:block;margin-bottom:5px">Late fee ($ / hour)</label>
        <input type="number" name="late_fee_per_hour" value="{{ $lateFeePerHour }}" min="0" step="0.01" class="ia-input" style="width:120px;text-align:right">
      </div>
      <div>
        <label class="ia-label" style="display:block;margin-bottom:5px">Cap the fee at</label>
        <select name="late_fee_cap" class="ia-input">
          <option value="day_rate" {{ $lateFeeCap === 'day_rate' ? 'selected' : '' }}>One day's rate</option>
          <option value="none" {{ $lateFeeCap === 'none' ? 'selected' : '' }}>No cap</option>
        </select>
      </div>
    </div>
    <p style="font-size:11.5px;opacity:.45;margin-top:10px">Within the grace period nothing is suggested. Past it, full hours from the due time are billed. Set $0/hour to turn suggestions off.</p>
  </div>

  {{-- MARKER-PATCH-237 — deposit behavior. --}}
  <div class="ia-card" style="padding:18px 20px;margin-bottom:16px">
    <div class="ia-card-head"><span class="ia-card-title">Deposits</span></div>
    <label style="display:flex;gap:10px;align-items:flex-start;margin-top:10px;cursor:pointer">
      <input type="checkbox" name="deposit_autorelease_quick" value="1" {{ $depositAutoRelease ? 'checked' : '' }} style="margin-top:3px">
      <span style="font-size:13px">Auto-release deposit holds on <b>quick</b> check-in
        <span style="display:block;font-size:11.5px;opacity:.5;margin-top:2px">The guided return flow always asks explicitly — this only controls the one-click escape hatch. Turn off if you'd rather every hold be a deliberate decision.</span>
      </span>
    </label>
  </div>

  <button type="submit" class="ia-btn ia-btn--primary">Save settings</button>
</form>

{{-- MARKER-PATCH-237 — versioned agreement templates. Own form: publishing
     is separate from saving settings. --}}
<div class="ia-card" style="padding:18px 20px;margin-top:16px">
  <div class="ia-card-head"><span class="ia-card-title">Rental agreement</span></div>
  @if($agreementTemplates->isEmpty())
    <p style="font-size:12.5px;opacity:.55;margin:6px 0 14px;line-height:1.5">
      No agreement yet — the check-out flow currently skips the signature step. Publish v1 below and every guided check-out from then on requires a signed agreement (PDF snapshotted to the rental record).
    </p>
  @else
    <div style="margin:10px 0 16px">
      @foreach($agreementTemplates as $tpl)
        <details style="border:.5px solid var(--ia-border);border-radius:8px;padding:10px 14px;margin-bottom:8px;{{ $loop->first ? 'background:rgba(190,242,100,.05)' : '' }}">
          <summary style="cursor:pointer;font-size:13px;display:flex;gap:10px;align-items:center;list-style:none">
            <b>{{ $tpl->title }}</b>
            <span style="font-size:11px;opacity:.5;font-family:var(--ia-font-mono,monospace)">v{{ $tpl->version }}</span>
            @if($loop->first)<span style="font-size:10.5px;font-weight:600;color:var(--ia-accent);background:rgba(190,242,100,.12);border-radius:999px;padding:2px 9px">current</span>@endif
            <span style="font-size:11px;opacity:.45;margin-left:auto">{{ tlocal_date($tpl->created_at) }}</span>
          </summary>
          <pre style="font-size:12px;white-space:pre-wrap;font-family:inherit;opacity:.75;margin-top:10px;line-height:1.6">{{ $tpl->body }}</pre>
        </details>
      @endforeach
    </div>
  @endif

  <form method="POST" action="{{ route('tenant.rentals.settings.agreements.store') }}">
    @csrf
    <div style="display:flex;flex-direction:column;gap:10px">
      <div>
        <label class="ia-label" style="display:block;margin-bottom:5px">Title</label>
        <input type="text" name="title" maxlength="160" required class="ia-input" style="width:100%;max-width:480px" value="{{ $agreementTemplates->first()?->title ?? 'Rental agreement' }}">
      </div>
      <div>
        <label class="ia-label" style="display:block;margin-bottom:5px">Terms</label>
        <textarea name="body" rows="10" required maxlength="50000" class="ia-input" style="width:100%;font-size:12.5px;line-height:1.6" placeholder="The customer agrees to…">{{ $agreementTemplates->first()?->body }}</textarea>
      </div>
      <div style="display:flex;gap:10px;align-items:center">
        <button type="submit" class="ia-btn ia-btn--primary">Publish {{ $agreementTemplates->isEmpty() ? 'v1' : 'v' . ($agreementTemplates->first()->version + 1) }}</button>
        <span style="font-size:11.5px;opacity:.45">Publish-only — past rentals keep the version they signed.</span>
      </div>
    </div>
  </form>
</div>

@endsection
